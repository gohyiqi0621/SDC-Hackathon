<?php
/**
 * NotificationController.php - push notifications.
 *
 * Admins get ?page=notification: a composer (title/body/link/recipients/schedule)
 * and the history table that doubles as the audit log. Users get the same page
 * as a read-only feed, which also backs the bell in the top bar.
 *
 * Sending is a fetch call: the composer modal closes on the outcome and the
 * history row is inserted in place, so a send never costs a page reload.
 */
declare(strict_types=1);

class NotificationController
{
    /** GET ?page=notification */
    public function index(): void
    {
        $user    = require_login();
        $notif   = new Notification();
        $isAdmin = ($user['role'] ?? '') === 'admin';

        if ($isAdmin) {
            // A schedule OneSignal already fired is not still 'scheduled'.
            $notif->reconcileScheduled();
        }

        $recipients = $isAdmin ? $notif->recipients() : ['rows' => [], 'error' => null];

        $data = [
            'title'    => 'Notification',
            'user'     => $user,
            'isAdmin'  => $isAdmin,
            'history'  => $isAdmin ? $notif->paginate((int) ($_GET['p'] ?? 1)) : null,
            'feed'     => $notif->forUser((int) $user['id'], (string) ($user['role'] ?? 'user')),
            'recipients' => $recipients['rows'],
            'recipientsError' => $recipients['error'],
            'configured' => onesignal_configured(),
            'stats'    => $isAdmin ? [
                'total'     => $notif->count(),
                'sent'      => $notif->countByStatus('sent'),
                'scheduled' => $notif->countByStatus('scheduled'),
                'failed'    => $notif->countByStatus('failed'),
            ] : [],
        ];

        view('notification/index', $data);
    }

    /** POST ?page=notification&action=send - admin composer. */
    public function send(): void
    {
        $admin  = require_admin();
        $notif  = new Notification();
        $in = [
            'title'        => trim((string) ($_POST['title'] ?? '')),
            'body'         => trim((string) ($_POST['body'] ?? '')),
            'url'          => trim((string) ($_POST['url'] ?? '')),
            'audience'     => (string) ($_POST['audience'] ?? 'all'),
            'scheduled_at' => trim((string) ($_POST['scheduled_at'] ?? '')),
        ];
        $errors = [];

        if ($in['title'] === '' || mb_strlen($in['title']) > 190) {
            $errors['title'] = 'Give the notification a title under 190 characters.';
        }
        if ($in['body'] === '') {
            $errors['body'] = 'Write a message to send.';
        }
        if (!in_array($in['audience'], Notification::AUDIENCES, true)) {
            $errors['audience'] = 'Choose who should receive this.';
        }

        // Recipients arrive as recipients[] - one id per chosen user.
        $chosen = [];
        if ($in['audience'] === 'users') {
            $raw    = $_POST['recipients'] ?? [];
            $raw    = is_array($raw) ? $raw : [$raw];
            $chosen = array_values(array_unique(array_filter(
                array_map('intval', $raw),
                static fn (int $id): bool => $id > 0
            )));
            if ($chosen === []) {
                $errors['recipients'] = 'Choose at least one recipient.';
            }
        }

        // A datetime-local value is already the app's wall clock, which is how
        // every other datetime column is stored. It becomes UTC only on the way
        // out to OneSignal (see schedule_to_iso()).
        $scheduledAt = null;
        if ($in['scheduled_at'] !== '') {
            $db = schedule_to_db($in['scheduled_at']);
            if ($db === null) {
                $errors['scheduled_at'] = 'That date and time could not be read.';
            } elseif (schedule_passed($db)) {
                $errors['scheduled_at'] = 'Pick a time in the future, or leave it blank to send now.';
            } else {
                $scheduledAt = $db;
            }
        }

        if ($errors !== []) {
            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
            }
            flash('error', implode(' ', $errors));
            redirect(url('notification'));
        }

        $in['scheduled_at']   = $scheduledAt;
        $in['audience_value'] = $chosen === [] ? null : implode(',', $chosen);
        $in['audience_label'] = $this->labelFor($in['audience'], $chosen);

        [$id, $error, $row] = $notif->send($in, (int) $admin['id']);

        if ($error !== '') {
            if (wants_json()) {
                // No field-level errors here: the send itself failed, so the
                // composer should close and report it as a toast. The failed
                // attempt is still returned, because it is audit history and
                // the counters must move with it.
                json_response(false, [
                    'row'   => $this->rowHtml($row),
                    'stats' => $this->stats(),
                    'empty' => false,
                ], $error, 422);
            }
            flash('error', $error);
            redirect(url('notification'));
        }

        $message = $scheduledAt
            ? 'Notification scheduled for ' . db_time($scheduledAt) . '.'
            : 'Notification sent.';

        if (wants_json()) {
            json_response(true, [
                'row'   => $this->rowHtml($row),
                'stats' => $this->stats(),
                'empty' => $this->isEmpty(),
            ], $message);
        }

        flash('success', $message);
        redirect(url('notification'));
    }

    /**
     * POST ?page=notification&action=test - push to the admin who clicked, so
     * setup can be confirmed without bothering every user.
     */
    public function test(): void
    {
        $admin = require_admin();
        $id    = (int) $admin['id'];

        $recipients = (new Notification())->recipients();
        $mine = null;
        foreach ($recipients['rows'] as $r) {
            if ($r['id'] === $id) {
                $mine = $r;
                break;
            }
        }

        if ($mine === null) {
            $error = $recipients['error']
                ?? 'This account has no subscribed device yet. Open the site in a normal browser, '
                 . 'go to Profile, and turn on Push notifications first.';
            if (wants_json()) {
                json_response(false, [], $error, 422);
            }
            flash('error', $error);
            redirect(url('notification'));
        }

        [, $error] = (new Notification())->send([
            'title'          => 'Test notification',
            'body'           => 'If you can read this, push is working.',
            'url'            => url('notification'),
            'audience'       => 'users',
            'audience_value' => (string) $id,
            'audience_label' => (string) $mine['name'],
            'scheduled_at'   => null,
        ], $id);

        if ($error !== '') {
            if (wants_json()) {
                json_response(false, [], $error, 422);
            }
            flash('error', $error);
            redirect(url('notification'));
        }

        if (wants_json()) {
            json_response(true, ['stats' => $this->stats()], 'Test push sent to this account.');
        }
        flash('success', 'Test push sent to this account.');
        redirect(url('notification'));
    }

    /** GET ?page=notification&action=feed - JSON for the bell (no page reload). */
    public function feed(): void
    {
        $user  = require_login();
        $notif = new Notification();
        $role  = (string) ($user['role'] ?? 'user');
        json_response(true, [
            'items'  => $notif->forUser((int) $user['id'], $role),
            'unread' => $notif->unreadCount((int) $user['id'], $role),
        ]);
    }

    /** POST ?page=notification&action=read - clear the bell's unread count. */
    public function read(): void
    {
        $user = require_login();
        (new Notification())->markAllRead((int) $user['id'], (string) ($user['role'] ?? 'user'));
        respond_ok('Marked as read.', url('notification'));
    }

    /**
     * Render one history row. Shared by the initial table and the send response
     * so the audit markup lives in exactly one place.
     */
    private function rowHtml(?array $row): string
    {
        if ($row === null) {
            return '';
        }
        ob_start();
        view('notification/_row', ['n' => $row]);
        return (string) ob_get_clean();
    }

    /** @return array<string,int> */
    private function stats(): array
    {
        $notif = new Notification();
        return [
            'total'     => $notif->count(),
            'sent'      => $notif->countByStatus('sent'),
            'scheduled' => $notif->countByStatus('scheduled'),
            'failed'    => $notif->countByStatus('failed'),
        ];
    }

    /** Is the history still empty (so the client knows to drop the empty state)? */
    private function isEmpty(): bool
    {
        return (new Notification())->count() === 1;
    }

    /**
     * The human label frozen into the audit row: "Everyone", or the recipients'
     * names. Names are read from the database rather than the browser so the
     * audit cannot be forged by the client.
     *
     * @param list<int> $ids
     */
    private function labelFor(string $audience, array $ids): string
    {
        if ($audience !== 'users' || $ids === []) {
            return 'Everyone';
        }

        $lookup = [];
        foreach ((new Notification())->recipients()['rows'] as $r) {
            $lookup[$r['id']] = (string) $r['name'];
        }

        $names = array_map(static fn (int $id): string => $lookup[$id] ?? 'User #' . $id, $ids);
        $label = implode(', ', array_slice($names, 0, 3));
        if (count($names) > 3) {
            $label .= ' +' . (count($names) - 3) . ' more';
        }
        return mb_strimwidth($label, 0, 250, '…');
    }
}
