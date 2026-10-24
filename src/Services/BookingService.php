<?php
/**
 * BookingService.php - time slots on the business's Google Calendar.
 *
 * Used by the Flowise tools check_availability and make_booking
 * (ToolController) and by the Bookings page (cancel).
 *
 * Rules come from AI Settings > Google Calendar (migration 013):
 *   booking_mode   single: a slot is full as soon as anything is in it
 *                  multi:  a slot holds slot_capacity bookings (or pax)
 *   slot length    default_appointment_duration_minutes
 *   open hours     open_time - close_time on open_days, in the calendar timezone
 *
 * What fills a slot (read from Google Calendar, so events you add by hand
 * count too):
 *   - bookings made here (tagged in the event's private extendedProperties,
 *     with their pax);
 *   - other timed events: "single" -> blocks the slot; "multi" -> counts as
 *     1 booking, or the number in its title ("Ali 4 pax", "6 orang");
 *   - all-day events only matter when their title says the business is
 *     closed ("Closed", "Tutup", "Cuti", "Holiday", "Fully booked").
 *
 * make_booking checks again inside a database lock, so two customers asking
 * for the last place at the same moment cannot both get it.
 */
declare(strict_types=1);

namespace Src\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Src\Models\Booking;
use Src\Models\ChatMessage;
use Src\Models\GoogleCalendarIntegration;
use Src\Models\TelegramIntegration;
use Src\Support\Crypto;
use Src\Support\Database;
use Src\Support\FlowLog;
use Throwable;

final class BookingService
{
    private const CLOSED_WORDS = '/\b(closed|close|tutup|cuti|holiday|public holiday|fully booked|full|off day)\b/iu';
    private const MAX_DAYS_AHEAD = 90;

    private GoogleCalendarIntegration $model;
    private ?string $token = null;

    public function __construct()
    {
        $this->model = new GoogleCalendarIntegration();
    }

    /** The calendar settings, or a RuntimeException that says what to set up. */
    public function settings(int $userId): array
    {
        $row = $this->model->find($userId);
        if ($row === null || ($row['connection_status'] ?? '') !== 'connected' || empty($row['refresh_token_encrypted'])) {
            throw new RuntimeException('Bookings are not available: the business has not connected Google Calendar yet.');
        }
        if (array_key_exists('is_enabled', $row) && $row['is_enabled'] === false) {
            throw new RuntimeException('Bookings are not available: Google Calendar is switched off in AI Settings.');
        }
        if (empty($row['calendar_id'])) {
            throw new RuntimeException('Bookings are not available: no calendar is chosen in AI Settings > Google Calendar.');
        }

        $row['booking_mode']  = $row['booking_mode'] ?? 'single';
        $row['slot_capacity'] = max(1, (int) ($row['slot_capacity'] ?? 1));
        $row['capacity_unit'] = $row['capacity_unit'] ?? 'bookings';
        $row['open_time']     = substr((string) ($row['open_time'] ?? '09:00'), 0, 5);
        $row['close_time']    = substr((string) ($row['close_time'] ?? '18:00'), 0, 5);
        $row['open_days']     = array_map('intval', array_filter(explode(',', (string) ($row['open_days'] ?? '1,2,3,4,5,6,7'))));
        $row['minutes']       = max(15, (int) ($row['default_appointment_duration_minutes'] ?? 60));
        $row['zone']          = new DateTimeZone((string) ($row['timezone'] ?? 'Asia/Kuala_Lumpur'));

        return $row;
    }

    /**
     * Parse "2026-10-22" + "21:00" / "9pm" / "9.30 pm" (or one ISO datetime) in the calendar timezone.
     */
    public function parseStart(string $date, string $time, DateTimeZone $zone): ?DateTimeImmutable
    {
        $date = trim($date);
        $time = trim(mb_strtolower($time));

        if ($time === '' && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{1,2}:\d{2}/', $date)) {
            try {
                return (new DateTimeImmutable($date, $zone))->setTimezone($zone);
            } catch (Throwable) {
                return null;
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        // "12 noon", "noon", "tengah hari" = 12:00; "midnight" = 00:00
        $time = preg_replace('/^(12\s*)?(noon|tengah\s*hari)$/u', '12pm', $time);
        $time = preg_replace('/^(12\s*)?midnight$/u', '12am', $time);
        $time = str_replace(['.', ' '], [':', ''], $time);
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?(am|pm)?$/', $time, $m)) {
            $hour = (int) $m[1];
            $min  = (int) ($m[2] ?? 0);
            if (($m[3] ?? '') === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if (($m[3] ?? '') === 'am' && $hour === 12) {
                $hour = 0;
            }
            if ($hour > 23 || $min > 59) {
                return null;
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            return $start ? $start->setTime($hour, $min) : null;
        }

        return null;
    }

    /**
     * Is there room at $start for $pax?
     *
     * @return array{available: bool, reason: string, start: DateTimeImmutable, end: DateTimeImmutable,
     *               used: int, capacity: int, alternatives: list<string>}
     */
    public function check(int $userId, array $s, DateTimeImmutable $start, int $pax): array
    {
        $end    = $start->add(new DateInterval('PT' . $s['minutes'] . 'M'));
        $result = ['available' => false, 'reason' => '', 'start' => $start, 'end' => $end, 'used' => 0,
                   'capacity' => $s['booking_mode'] === 'multi' ? $s['slot_capacity'] : 1, 'alternatives' => []];

        $now = new DateTimeImmutable('now', $s['zone']);
        $rule = $this->ruleProblem($s, $start, $end, $now, $pax);

        $token  = $this->accessToken($userId, $s);
        $google = new GoogleCalendarService();
        $dayStart = $start->setTime(0, 0);
        $events   = $google->events($token, (string) $s['calendar_id'], $dayStart, $dayStart->add(new DateInterval('P2D')));

        if ($rule === null) {
            [$ok, $used, $why] = $this->fits($s, $events, $start, $end, $pax);
            $result['used'] = $used;
            if ($ok) {
                $result['available'] = true;
                return $result;
            }
            $result['reason'] = $why;
        } else {
            $result['reason'] = $rule;
        }

        $result['alternatives'] = $this->alternatives($s, $events, $start, $pax, $now);
        return $result;
    }

    /**
     * Book it: check again inside a lock, write the Google event, save the row.
     *
     * @param array $customer channel, customer_chat_id, customer_name, customer_username, customer_phone, note
     * @return array{ok: bool, booking?: array, message: string, duplicate?: bool, alternatives?: list<string>}
     */
    public function book(int $userId, DateTimeImmutable $start, int $pax, array $customer): array
    {
        $s  = $this->settings($userId);
        $this->accessToken($userId, $s); // refresh before the lock, so a revoked token is saved as "error"
        $db = Database::connection();
        $bookings = new Booking();

        $db->beginTransaction();
        try {
            $db->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([$userId, 7_000_001]);

            // The AI repeating the same call: return the booking already made.
            $same = $bookings->findSame($userId, $customer['channel'], $customer['customer_chat_id'], $start->format(DATE_ATOM),
                $pax, (string) $customer['customer_name']);
            if ($same !== null) {
                $db->commit();
                return ['ok' => true, 'booking' => $same, 'duplicate' => true, 'message' => 'This booking already exists.'];
            }

            $check = $this->check($userId, $s, $start, $pax);
            if (!$check['available']) {
                $db->commit();
                return ['ok' => false, 'message' => $check['reason'], 'alternatives' => $check['alternatives']];
            }

            $title = trim(($customer['customer_name'] ?: 'Customer') . ' - ' . $pax . ' pax');
            $event = (new GoogleCalendarService())->insertEvent($this->accessToken($userId, $s), (string) $s['calendar_id'], [
                'summary'     => 'Booking: ' . $title,
                'description' => implode("\n", array_filter([
                    'Booked by the AI chatbot (' . $customer['channel'] . ').',
                    'Customer: ' . ($customer['customer_name'] ?: '-') . ($customer['customer_username'] ? ' (@' . $customer['customer_username'] . ')' : ''),
                    $customer['customer_phone'] ? 'Phone: ' . $customer['customer_phone'] : null,
                    'Pax: ' . $pax,
                    $customer['note'] ? 'Note: ' . $customer['note'] : null,
                ])),
                'start' => ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => $s['zone']->getName()],
                'end'   => ['dateTime' => $check['end']->format(DATE_RFC3339), 'timeZone' => $s['zone']->getName()],
                'extendedProperties' => ['private' => ['smartsme_booking' => '1', 'pax' => (string) $pax]],
            ]);

            $row = $bookings->create($userId, $customer + [
                'pax'             => $pax,
                'starts_at'       => $start->format(DATE_ATOM),
                'ends_at'         => $check['end']->format(DATE_ATOM),
                'calendar_id'     => (string) $s['calendar_id'],
                'google_event_id' => (string) ($event['id'] ?? ''),
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        FlowLog::write('booking.created', ['booking_no' => $row['booking_no'], 'start' => $row['starts_at'], 'pax' => $pax]);

        $when = self::label($start, $check['end']);
        $this->alertStaff($userId, $row, "📅 New booking {$row['booking_no']}\n"
            . 'Customer: ' . self::customerLabel($row) . "\n"
            . "When: {$when}\nPax: {$pax}"
            . ($row['note'] ? "\nNote: {$row['note']}" : ''));

        return ['ok' => true, 'booking' => $row, 'message' => 'Booked.'];
    }

    /** Cancel a booking and remove its Google Calendar event. */
    public function cancel(int $userId, array $booking): array
    {
        $row = (new Booking())->cancel($userId, (int) $booking['id']);
        if ($row === null) {
            throw new RuntimeException('Only confirmed bookings can be cancelled.');
        }
        if (!empty($row['google_event_id']) && !empty($row['calendar_id'])) {
            try {
                $s = $this->settings($userId);
                (new GoogleCalendarService())->deleteEvent($this->accessToken($userId, $s), (string) $row['calendar_id'], (string) $row['google_event_id']);
            } catch (Throwable $e) {
                FlowLog::write('booking.cancel_calendar_failed', ['booking_no' => $row['booking_no'], 'error' => $e->getMessage()]);
            }
        }
        $start = new DateTimeImmutable((string) $row['starts_at']);
        $zone  = new DateTimeZone((string) (($this->model->find($userId) ?? [])['timezone'] ?? 'Asia/Kuala_Lumpur'));
        $text  = "Your booking {$row['booking_no']} on " . $start->setTimezone($zone)->format('j F Y, g:i A')
               . ' has been cancelled by our staff. Reply here if you would like another time.';
        $sent  = $this->notifyCustomer($userId, $row, $text);
        $this->remember($userId, $row, 'assistant', $text);

        FlowLog::write('booking.cancelled', ['booking_no' => $row['booking_no'], 'customer_notified' => $sent]);
        return $row + ['notified' => $sent];
    }

    public static function customerLabel(array $b): string
    {
        $label = trim((string) ($b['customer_name'] ?? '')) ?: 'Customer';
        if (!empty($b['customer_username'])) {
            $label .= ' (@' . $b['customer_username'] . ')';
        }
        if (!empty($b['customer_phone'])) {
            $label .= ', ' . $b['customer_phone'];
        }
        return $label;
    }

    /** "Thursday, 22 October 2026, 9:00 PM - 10:00 PM" */
    public static function label(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return $start->format('j F Y, g:i A') . ' - ' . $end->format('g:i A');
    }

    /** Opening hours as text for the AI, e.g. "Mon-Sun 09:00-18:00, 60-minute slots, up to 10 bookings per slot". */
    public static function rulesText(array $s): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days  = implode(', ', array_map(static fn (int $d): string => $names[$d] ?? '', $s['open_days']));
        $cap   = $s['booking_mode'] === 'multi'
            ? 'up to ' . $s['slot_capacity'] . ' ' . ($s['capacity_unit'] === 'pax' ? 'people' : 'bookings') . ' per slot'
            : 'one customer per slot';
        return 'Open ' . $days . ', ' . $s['open_time'] . '-' . $s['close_time'] . ' (' . $s['zone']->getName() . '), '
            . $s['minutes'] . '-minute slots, ' . $cap . '.';
    }

    /* ---------------------------------------------------------- private ---- */

    /** Opening hours, days, past time, too far ahead, too many pax. Null when fine. */
    private function ruleProblem(array $s, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now, int $pax): ?string
    {
        if ($start <= $now) {
            return 'That time has already passed.';
        }
        if ($start > $now->add(new DateInterval('P' . self::MAX_DAYS_AHEAD . 'D'))) {
            return 'Bookings can only be made up to ' . self::MAX_DAYS_AHEAD . ' days ahead.';
        }
        if (!in_array((int) $start->format('N'), $s['open_days'], true)) {
            return 'The business is closed on ' . $start->format('j M Y') . '. Ask the customer for another date.';
        }
        [$oh, $om] = array_map('intval', explode(':', $s['open_time']));
        [$ch, $cm] = array_map('intval', explode(':', $s['close_time']));
        if ($start < $start->setTime($oh, $om) || $end > $start->setTime($ch, $cm)) {
            return 'That is outside opening hours (' . $s['open_time'] . '-' . $s['close_time'] . '; the last booking starts '
                . $start->setTime($ch, $cm)->sub(new DateInterval('PT' . $s['minutes'] . 'M'))->format('H:i') . ').';
        }
        if ($s['booking_mode'] === 'multi' && $s['capacity_unit'] === 'pax' && $pax > $s['slot_capacity']) {
            return 'A slot holds at most ' . $s['slot_capacity'] . ' people.';
        }
        return null;
    }

    /** @return array{0: bool, 1: int, 2: string} [fits, already used, reason when not] */
    private function fits(array $s, array $events, DateTimeImmutable $start, DateTimeImmutable $end, int $pax): array
    {
        $used = 0;
        foreach ($events as $event) {
            [$evStart, $evEnd, $allDay] = $this->times($event, $s['zone']);
            if ($evStart === null) {
                continue;
            }
            $title = (string) ($event['summary'] ?? '');

            if ($allDay) {
                if ($evStart <= $start && $evEnd > $start && preg_match(self::CLOSED_WORDS, $title)) {
                    return [false, $used, 'The business is closed that day ("' . $title . '").'];
                }
                continue;
            }
            if (!($evStart < $end && $evEnd > $start)) {
                continue; // no overlap
            }
            if (($event['transparency'] ?? '') === 'transparent') {
                continue; // marked "free" in Google Calendar
            }
            if ($s['booking_mode'] === 'single') {
                return [false, 1, 'That time is already booked.'];
            }
            if (preg_match(self::CLOSED_WORDS, $title) && empty($event['extendedProperties']['private']['smartsme_booking'])) {
                return [false, $used, 'That time is blocked ("' . $title . '").'];
            }
            $used += $s['capacity_unit'] === 'pax' ? $this->paxOf($event) : 1;
        }

        $need = $s['capacity_unit'] === 'pax' ? $pax : 1;
        if ($s['booking_mode'] === 'multi' && $used + $need > $s['slot_capacity']) {
            $left = max(0, $s['slot_capacity'] - $used);
            return [false, $used, $left === 0
                ? 'That time is fully booked.'
                : 'Only ' . $left . ($s['capacity_unit'] === 'pax' ? ' seat(s)' : ' booking(s)') . ' left at that time.'];
        }
        return [true, $used, ''];
    }

    /**
     * Free start times from a date on (that day first, then the next open days),
     * for "any time is fine" / "what times do you have?". Real slots only:
     * opening hours, slot length, capacity and the Google Calendar.
     *
     * @return list<string> e.g. "Thursday 22 Oct, 4:00 PM"
     */
    public function freeTimes(int $userId, array $s, DateTimeImmutable $from, int $pax, int $limit = 6): array
    {
        $now    = new DateTimeImmutable('now', $s['zone']);
        $google = new GoogleCalendarService();
        $step   = new DateInterval('PT' . $s['minutes'] . 'M');
        [$oh, $om] = array_map('intval', explode(':', $s['open_time']));
        $out = [];

        for ($day = 0; $day < 14 && count($out) < $limit; $day++) {
            $date = $from->setTime(0, 0)->add(new DateInterval('P' . $day . 'D'));
            if (!in_array((int) $date->format('N'), $s['open_days'], true)) {
                continue;
            }
            $events = $google->events($this->accessToken($userId, $s), (string) $s['calendar_id'], $date, $date->add(new DateInterval('P1D')));
            for ($slot = $date->setTime($oh, $om); $slot->format('Y-m-d') === $date->format('Y-m-d') && $slot->format('H:i') < $s['close_time'] && count($out) < $limit; $slot = $slot->add($step)) {
                $end = $slot->add($step);
                if ($this->ruleProblem($s, $slot, $end, $now, $pax) !== null) {
                    continue;
                }
                [$ok] = $this->fits($s, $events, $slot, $end, $pax);
                if ($ok) {
                    $out[] = $slot->format('j M Y, g:i A');
                }
            }
        }
        return $out;
    }

    /** Up to 3 free start times closest to the wanted time: same day first, then the next days. */
    private function alternatives(array $s, array $events, DateTimeImmutable $wanted, int $pax, DateTimeImmutable $now): array
    {
        $out    = [];
        $google = null;
        $step   = new DateInterval('PT' . $s['minutes'] . 'M');
        [$oh, $om] = array_map('intval', explode(':', $s['open_time']));
        $wantedMin = (int) $wanted->format('G') * 60 + (int) $wanted->format('i');

        for ($day = 0; $day < 14 && count($out) < 3; $day++) {
            $date = $wanted->setTime(0, 0)->add(new DateInterval('P' . $day . 'D'));
            if (!in_array((int) $date->format('N'), $s['open_days'], true)) {
                continue;
            }
            if ($day > 0) {
                try {
                    $google ??= new GoogleCalendarService();
                    $events = $google->events($this->accessToken((int) $s['user_id'], $s), (string) $s['calendar_id'], $date, $date->add(new DateInterval('P1D')));
                } catch (Throwable) {
                    break;
                }
            }

            $free = [];
            for ($slot = $date->setTime($oh, $om); $slot->format('Y-m-d') === $date->format('Y-m-d') && $slot->format('H:i') < $s['close_time']; $slot = $slot->add($step)) {
                $slotEnd = $slot->add($step);
                if ($slot->format('Y-m-d H:i') === $wanted->format('Y-m-d H:i')
                    || $this->ruleProblem($s, $slot, $slotEnd, $now, $pax) !== null) {
                    continue;
                }
                [$ok] = $this->fits($s, $events, $slot, $slotEnd, $pax);
                if ($ok) {
                    $free[] = $slot;
                }
            }
            usort($free, static function (DateTimeImmutable $a, DateTimeImmutable $b) use ($wantedMin): int {
                $da = abs((int) $a->format('G') * 60 + (int) $a->format('i') - $wantedMin);
                $db = abs((int) $b->format('G') * 60 + (int) $b->format('i') - $wantedMin);
                return $da <=> $db ?: $a <=> $b;
            });
            foreach (array_slice($free, 0, 3 - count($out)) as $slot) {
                $out[] = $slot->format('j M Y, g:i A');
            }
        }
        return $out;
    }

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable, 2: bool} */
    private function times(array $event, DateTimeZone $zone): array
    {
        try {
            if (isset($event['start']['dateTime'], $event['end']['dateTime'])) {
                return [new DateTimeImmutable((string) $event['start']['dateTime']), new DateTimeImmutable((string) $event['end']['dateTime']), false];
            }
            if (isset($event['start']['date'], $event['end']['date'])) {
                return [new DateTimeImmutable((string) $event['start']['date'], $zone), new DateTimeImmutable((string) $event['end']['date'], $zone), true];
            }
        } catch (Throwable) {
        }
        return [null, null, false];
    }

    private function paxOf(array $event): int
    {
        $tagged = $event['extendedProperties']['private']['pax'] ?? null;
        if ($tagged !== null && (int) $tagged > 0) {
            return (int) $tagged;
        }
        if (preg_match('/(\d{1,3})\s*(pax|people|persons?|orang|guests?|ppl|人)/iu', (string) ($event['summary'] ?? ''), $m)) {
            return max(1, (int) $m[1]);
        }
        return 1;
    }

    /** Keep booking events in the conversation history. */
    private function remember(int $userId, array $b, string $role, string $text): void
    {
        try {
            (new ChatMessage())->add($userId, (string) $b['channel'], (string) $b['customer_chat_id'], $role, $text, 'ok');
        } catch (Throwable) {
        }
    }

    /** @return array{0: string, 1: array}|null [bot token, integration] */
    private function bot(int $userId): ?array
    {
        $integration = (new TelegramIntegration())->find($userId);
        if ($integration === null || empty($integration['bot_token_encrypted'])) {
            return null;
        }
        try {
            return [Crypto::decrypt((string) $integration['bot_token_encrypted']), $integration];
        } catch (RuntimeException) {
            return null;
        }
    }

    private function notifyCustomer(int $userId, array $b, string $text): bool
    {
        if ($b['channel'] !== 'telegram' || ($bot = $this->bot($userId)) === null) {
            return false;
        }
        return (new TelegramService())->sendText($bot[0], (string) $b['customer_chat_id'], $text);
    }

    /** Staff alert, never into the customer's own chat. */
    private function alertStaff(int $userId, array $b, string $text): void
    {
        try {
            if (($bot = $this->bot($userId)) === null) {
                return;
            }
            $staff = (string) ($bot[1]['staff_telegram_chat_id'] ?? '');
            if ($staff !== '' && $staff !== (string) $b['customer_chat_id']) {
                (new TelegramService())->sendText($bot[0], $staff, $text);
            }
        } catch (Throwable $e) {
            FlowLog::write('booking.staff_alert_failed', ['error' => $e->getMessage()]);
        }
    }

    /** A valid Google access token for this business (refreshed when needed). */
    private function accessToken(int $userId, array $s): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        try {
            $access = json_decode(Crypto::decrypt((string) ($s['access_token_encrypted'] ?? '')), true);
        } catch (RuntimeException) {
            $access = null;
        }
        if (is_array($access) && (int) ($access['expires_at'] ?? 0) > time() + 120 && !empty($access['token'])) {
            return $this->token = (string) $access['token'];
        }

        try {
            $fresh = (new GoogleCalendarService())->refresh(Crypto::decrypt((string) $s['refresh_token_encrypted']));
        } catch (GoogleAuthRevoked $e) {
            $this->model->save($userId, ['connection_status' => 'error', 'access_token_encrypted' => null]);
            throw new RuntimeException('Bookings are not available: Google Calendar needs to be connected again.');
        }
        $encrypted = Crypto::encrypt((string) json_encode(['token' => $fresh['access_token'], 'expires_at' => time() + $fresh['expires_in'] - 60]));
        $this->model->save($userId, ['access_token_encrypted' => $encrypted]);

        return $this->token = $fresh['access_token'];
    }
}
