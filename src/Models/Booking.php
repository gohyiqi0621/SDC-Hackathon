<?php
/**
 * Booking.php - bookings (migration 013), always scoped to one business.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Booking
{
    public const STATUSES = ['confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'];

    public function create(int $userId, array $b): array
    {
        $db = Database::connection();
        for ($try = 0; $try < 5; $try++) {
            $stmt = $db->prepare(
                'INSERT INTO bookings (user_id, booking_no, channel, customer_chat_id, customer_name, customer_username,
                                       customer_phone, pax, starts_at, ends_at, note, calendar_id, google_event_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, booking_no) DO NOTHING
                 RETURNING *'
            );
            $stmt->execute([
                $userId, $this->newNo(), $b['channel'], $b['customer_chat_id'],
                $b['customer_name'] ?: null, $b['customer_username'] ?: null, $b['customer_phone'] ?: null,
                $b['pax'], $b['starts_at'], $b['ends_at'], $b['note'] ?: null, $b['calendar_id'], $b['google_event_id'],
            ]);
            if ($row = $stmt->fetch()) {
                return $row;
            }
        }
        throw new \RuntimeException('Could not create a booking number. Please try again.');
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM bookings WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The AI repeating the same make_booking call: the same chat, start time,
     * pax and name, made in the last 10 minutes. A different name or pax at
     * the same time is a real second booking (e.g. booking for a friend).
     */
    public function findSame(int $userId, string $channel, string $chatId, string $startsAt, int $pax, string $name): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM bookings WHERE user_id = ? AND channel = ? AND customer_chat_id = ?
                AND status = 'confirmed' AND starts_at = ?::timestamptz AND pax = ?
                AND LOWER(COALESCE(customer_name, '')) = LOWER(?)
                AND created_at > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $channel, $chatId, $startsAt, $pax, trim($name)]);
        return $stmt->fetch() ?: null;
    }

    /** This customer's upcoming confirmed bookings, soonest first. */
    public function upcomingForChat(int $userId, string $channel, string $chatId, int $limit = 3): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM bookings WHERE user_id = ? AND channel = ? AND customer_chat_id = ?
                AND status = 'confirmed' AND ends_at > CURRENT_TIMESTAMP
              ORDER BY starts_at LIMIT " . max(1, min(10, $limit))
        );
        $stmt->execute([$userId, $channel, $chatId]);
        return $stmt->fetchAll();
    }

    /**
     * @param string $when 'upcoming' | 'past' | 'cancelled' | 'all'
     * @return list<array>
     */
    public function search(int $userId, string $when, string $q, int $limit, int $offset): array
    {
        [$where, $params, $order] = $this->filters($userId, $when, $q);
        $stmt = Database::connection()->prepare(
            "SELECT * FROM bookings WHERE $where ORDER BY $order LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(int $userId, string $when, string $q): int
    {
        [$where, $params] = $this->filters($userId, $when, $q);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM bookings WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Numbers for the stat cards. */
    public function stats(int $userId, string $timezone): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE status = 'confirmed' AND (starts_at AT TIME ZONE ?)::date = (CURRENT_TIMESTAMP AT TIME ZONE ?)::date) AS today,
                COALESCE(SUM(pax) FILTER (WHERE status = 'confirmed' AND (starts_at AT TIME ZONE ?)::date = (CURRENT_TIMESTAMP AT TIME ZONE ?)::date), 0) AS today_pax,
                COUNT(*) FILTER (WHERE status = 'confirmed' AND ends_at > CURRENT_TIMESTAMP) AS upcoming,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
               FROM bookings WHERE user_id = ?"
        );
        $stmt->execute([$timezone, $timezone, $timezone, $timezone, $userId]);
        return array_map('intval', $stmt->fetch() ?: ['today' => 0, 'today_pax' => 0, 'upcoming' => 0, 'cancelled' => 0]);
    }

    public function cancel(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ? AND status = 'confirmed' RETURNING *"
        );
        $stmt->execute([$userId, $id]);
        return $stmt->fetch() ?: null;
    }

    /* ---------------------------------------------------------- private ---- */

    /** e.g. BK-261021-7K3Q */
    private function newNo(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $suffix   = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'BK-' . date('ymd') . '-' . $suffix;
    }

    /** @return array{0: string, 1: array, 2: string} */
    private function filters(int $userId, string $when, string $q): array
    {
        $where  = 'user_id = ?';
        $params = [$userId];
        $order  = 'starts_at ASC, id ASC';

        switch ($when) {
            case 'past':
                $where .= " AND status = 'confirmed' AND ends_at <= CURRENT_TIMESTAMP";
                $order  = 'starts_at DESC, id DESC';
                break;
            case 'cancelled':
                $where .= " AND status = 'cancelled'";
                $order  = 'starts_at DESC, id DESC';
                break;
            case 'all':
                $order = 'starts_at DESC, id DESC';
                break;
            default: // upcoming
                $where .= " AND status = 'confirmed' AND ends_at > CURRENT_TIMESTAMP";
        }

        if ($q !== '') {
            $like     = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $where   .= " AND (LOWER(booking_no) LIKE ? OR LOWER(COALESCE(customer_name, '')) LIKE ?"
                      . " OR LOWER(COALESCE(customer_username, '')) LIKE ? OR COALESCE(customer_phone, '') LIKE ? OR customer_chat_id LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [$where, $params, $order];
    }
}
