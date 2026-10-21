<?php
/**
 * BookingController.php - AI Chatbot > Customer > Bookings.
 *
 * Bookings the AI chatbot made with the Flowise tool make_booking. Each one
 * is also an event in the business's Google Calendar (BookingService).
 *
 *   GET  bookings          list: upcoming / past / cancelled / all, search
 *   POST bookings/cancel   cancel, remove the Google event, tell the customer
 */
declare(strict_types=1);

namespace Src\Controllers;

use DateTimeZone;
use PDOException;
use RuntimeException;
use Src\Models\Booking;
use Src\Models\GoogleCalendarIntegration;
use Src\Services\BookingService;
use Throwable;

final class BookingController
{
    private const PER_PAGE = 15;
    public const VIEWS     = ['upcoming' => 'Upcoming', 'past' => 'Past', 'cancelled' => 'Cancelled', 'all' => 'All'];

    /** GET bookings */
    public function index(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $when   = (string) ($_GET['when'] ?? 'upcoming');
        $when   = isset(self::VIEWS[$when]) ? $when : 'upcoming';
        $q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $page   = max(1, (int) ($_GET['p'] ?? 1));

        $calendar = (new GoogleCalendarIntegration())->find($userId);
        $zoneName = (string) ($calendar['timezone'] ?? 'Asia/Kuala_Lumpur');
        try {
            $zone = new DateTimeZone($zoneName);
        } catch (Throwable) {
            $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        }

        $model = new Booking();
        try {
            $total    = $model->count($userId, $when, $q);
            $pages    = max(1, (int) ceil($total / self::PER_PAGE));
            $page     = min($page, $pages);
            $bookings = $model->search($userId, $when, $q, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $stats    = $model->stats($userId, $zone->getName());
            $missingTable = false;
        } catch (PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42P01', '42703'], true)) {
                throw $e;
            }
            [$total, $pages, $bookings, $missingTable] = [0, 1, [], true];
            $stats = ['today' => 0, 'today_pax' => 0, 'upcoming' => 0, 'cancelled' => 0];
        }

        $rules = null;
        try {
            $rules = BookingService::rulesText((new BookingService())->settings($userId));
        } catch (Throwable) {
            $rules = null; // not connected yet: the page says so
        }

        view('bookings/index', [
            'user'         => $user,
            'bookings'     => $bookings,
            'stats'        => $stats,
            'when'         => $when,
            'q'            => $q,
            'total'        => $total,
            'page'         => $page,
            'pages'        => $pages,
            'perPage'      => self::PER_PAGE,
            'zone'         => $zone,
            'rules'        => $rules,
            'missingTable' => $missingTable,
        ]);
    }

    /** POST bookings/cancel */
    public function cancel(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $booking = (new Booking())->find($userId, (int) ($_POST['id'] ?? 0));
        $back    = (string) ($_POST['return'] ?? '');
        $back    = str_starts_with($back, APP_URL . '/') ? $back : url('bookings');

        if ($booking === null) {
            flash('error', 'That booking was not found.', 'Not changed');
            redirect($back);
        }

        try {
            $row = (new BookingService())->cancel($userId, $booking);
            flash('info', !empty($row['notified'])
                ? 'It was removed from Google Calendar and the customer was told on Telegram.'
                : 'It was removed from Google Calendar. The customer could not be messaged on Telegram.',
                'Booking ' . $booking['booking_no'] . ' cancelled');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage(), 'Not changed');
        }

        redirect($back);
    }
}
