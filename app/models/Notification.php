<?php
/**
 * Notification.php - the notifications table (sent + scheduled pushes) and the
 * per-user read marks that drive the bell's unread count.
 *
 * The table doubles as the audit log: who sent it, to whom, when, and what
 * OneSignal said back.
 */
declare(strict_types=1);

class Notification
{
    /** Audience modes the composer offers. */
    public const AUDIENCES = ['all', 'users'];

    /**
     * Who can be picked as a recipient: our users who currently hold at least
     * one healthy push subscription, newest activity first.
     *
     * The picker is built from this and nothing else, so an admin can never
     * choose somebody a push cannot reach - which is the single most confusing
     * thing about web push ("I sent it, why did nobody get it?").
     *
     * @return array{rows:list<array<string,mixed>>,error:?string}
     */
    public function recipients(): array
    {
        $lookup = onesignal_subscribed_users();
        if ($lookup['error'] !== null) {
            return ['rows' => [], 'error' => $lookup['error']];
        }
        if ($lookup['users'] === []) {
            return ['rows' => [], 'error' => null];
        }

        $ids  = array_map('intval', array_keys($lookup['users']));
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT id, name, email, role FROM users
              WHERE id IN ($in) AND status = 'active'
              ORDER BY name"
        );
        $stmt->execute($ids);

        $rows = [];
        foreach ($stmt->fetchAll() as $u) {
            $device = $lookup['users'][(int) $u['id']];
            $rows[] = [
                'id'          => (int) $u['id'],
                'name'        => (string) $u['name'],
                'email'       => (string) $u['email'],
                'role'        => (string) $u['role'],
                'devices'     => (int) $device['devices'],
                'last_active' => (int) $device['last_active'],
            ];
        }
        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Queue a push: store the row, hand it to OneSignal, then record the result.
     * A scheduled send is held by OneSignal, so no cron is needed here.
     *
     * The row is written BEFORE the API call so a failed push still leaves an
     * audit trail, and it carries the sender's name plus a human label for the
     * recipients - the audit must survive a renamed or deleted account.
     *
     * @param array{title:string,body:string,url:?string,audience:string,audience_value:?string,audience_label:string,scheduled_at:?string} $in
     * @return array{0:int,1:string,2:?array<string,mixed>} [id, errorMessage, storedRow]
     */
    public function send(array $in, ?int $by): array
    {
        $senderName = $by === null ? null : (new User())->nameOf($by);
        $scheduled  = $in['scheduled_at'] ?: null;
        $target     = $this->targetFor($in['audience'], $in['audience_value']);

        if (function_exists('onesignal_log')) {
            onesignal_log('send attempt', [
                'title'     => $in['title'],
                'audience'  => $in['audience'],
                'value'     => $in['audience_value'],
                'label'     => $in['audience_label'],
                'schedule'  => $scheduled,
                'target'    => $target,
                'by'        => $by,
                'configured' => onesignal_configured(),
            ]);
        }

        $stmt = db()->prepare(
            'INSERT INTO notifications
                (title, body, url, audience, audience_value, audience_label,
                 scheduled_at, status, created_by, created_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $in['title'], $in['body'], $in['url'] ?: null,
            $in['audience'], $in['audience_value'] ?: null, $in['audience_label'],
            $scheduled, $scheduled ? 'scheduled' : 'queued',
            $by, $senderName,
        ]);
        $id = (int) db()->lastInsertId();

        $result = onesignal_send(
            $in['title'],
            $in['body'],
            $in['url'] ?: null,
            $target,
            $scheduled
        );

        if (!$result['ok']) {
            db()->prepare('UPDATE notifications SET status = ?, error = ? WHERE id = ?')
                ->execute(['failed', $result['error'], $id]);
            // The row is returned too: a failed attempt is still audit history.
            return [$id, $result['error'], $this->find($id)];
        }

        // A scheduled push is accepted now but fires later -> keep 'scheduled'.
        db()->prepare('UPDATE notifications SET status = ?, onesignal_id = ?, recipients = ?, error = NULL WHERE id = ?')
            ->execute([$scheduled ? 'scheduled' : 'sent', $result['id'], $result['recipients'], $id]);

        return [$id, '', $this->find($id)];
    }

    /** One audit row, shaped the way the history table renders it. */
    public function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT n.*, COALESCE(n.created_by_name, u.name) AS sender_name
               FROM notifications n
               LEFT JOIN users u ON u.id = n.created_by
              WHERE n.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Translate the UI's audience choice into a onesignal_send() target. */
    private function targetFor(string $audience, ?string $value): array
    {
        if ($audience === 'role' && $value !== null && $value !== '') {
            return ['role' => $value];
        }
        if ($audience === 'user' && $value !== null && $value !== '') {
            return ['subscriptions' => onesignal_subscription_ids([(int) $value])];
        }
        if ($audience === 'users' && $value !== null && $value !== '') {
            return ['subscriptions' => onesignal_subscription_ids(explode(',', $value))];
        }
        return ['all' => true];
    }

    /**
     * Retire schedules OneSignal has already fired.
     *
     * A scheduled push is held server-side, so nothing tells us it went out -
     * without this, a fired schedule sits as 'scheduled' forever and both the
     * history and the stat tiles slowly drift away from the truth. One UPDATE
     * per page load, only touching rows whose time has passed.
     *
     * NOW() rather than UTC_TIMESTAMP(): scheduled_at holds the app's wall
     * clock, the same as every other datetime column here.
     */
    public function reconcileScheduled(): int
    {
        $stmt = db()->prepare(
            "UPDATE notifications
                SET status = 'sent'
              WHERE status = 'scheduled'
                AND onesignal_id IS NOT NULL
                AND scheduled_at IS NOT NULL
                AND scheduled_at <= NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** History, newest first, with the stored sender name for the audit column. */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $count = (int) db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        $pages = max(1, (int) ceil($count / $perPage));
        $page  = min(max(1, $page), $pages);

        $stmt = db()->prepare(
            'SELECT n.*, COALESCE(n.created_by_name, u.name) AS sender_name
               FROM notifications n
               LEFT JOIN users u ON u.id = n.created_by
              ORDER BY n.created_at DESC, n.id DESC
              LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $count, 'page' => $page, 'pages' => $pages];
    }

    public function count(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    }

    public function countByStatus(string $status): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The audience WHERE clause shared by forUser() / unreadCount() / markAllRead().
     *
     * `audience_value` means different things per mode, so the comparison has to
     * differ too: 'user' is one id, 'users' is a comma-separated list, 'role' is
     * a role name. Keep the three methods in sync through this one fragment.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function audienceClause(int $userId, string $role): array
    {
        return [
            "(n.audience = 'all'
              OR (n.audience = 'user'  AND n.audience_value = ?)
              OR (n.audience = 'users' AND FIND_IN_SET(?, n.audience_value))
              OR (n.audience = 'role'  AND n.audience_value = ?))",
            [(string) $userId, (string) $userId, $role],
        ];
    }

    /** Recipients for the bell dropdown. */
    public function forUser(int $userId, string $role = 'user', int $limit = 20): array
    {
        [$where, $params] = $this->audienceClause($userId, $role);

        $stmt = db()->prepare(
            'SELECT n.id, n.title, n.body, n.url, n.created_at, n.audience, n.audience_value,
                    (r.notification_id IS NOT NULL) AS is_read
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.id AND r.user_id = ?
              WHERE n.status IN (\'sent\', \'scheduled\')
                AND ' . $where . '
              ORDER BY n.created_at DESC, n.id DESC
              LIMIT ' . $limit
        );
        $stmt->execute(array_merge([$userId], $params));
        return $stmt->fetchAll();
    }

    /** Unread = the same audience filter minus the read marks. NULL user = 0. */
    public function unreadCount(?int $userId, ?string $role = null): int
    {
        if ($userId === null) {
            return 0;
        }
        [$where, $params] = $this->audienceClause($userId, (string) $role);

        $stmt = db()->prepare(
            'SELECT COUNT(*)
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.id AND r.user_id = ?
              WHERE n.status IN (\'sent\', \'scheduled\')
                AND r.notification_id IS NULL
                AND ' . $where
        );
        $stmt->execute(array_merge([$userId], $params));
        return (int) $stmt->fetchColumn();
    }

    /** Mark every notification the user can see as read (bell opened). */
    public function markAllRead(int $userId, string $role): void
    {
        [$where, $params] = $this->audienceClause($userId, $role);

        $stmt = db()->prepare(
            'INSERT IGNORE INTO notification_reads (user_id, notification_id)
             SELECT ?, n.id FROM notifications n
              WHERE n.status IN (\'sent\', \'scheduled\')
                AND ' . $where
        );
        $stmt->execute(array_merge([$userId], $params));
    }
}
