<?php
/**
 * DashboardController.php - stat cards, chart series and recent activity.
 */
declare(strict_types=1);

class DashboardController
{
    /** Days of history embedded in the page; the chart slices this client-side. */
    private const SERIES_DAYS = 30;

    public function index(): void
    {
        $user  = require_login();
        $items = new Item();
        $logs  = new AiLog();

        // Both roles get the chart, but the numbers behind it differ: a normal
        // user sees only their own activity, an admin sees the whole app.
        $stats = [
            'items_total'  => $items->count($user),
            'items_active' => $items->countByStatus('active', $user),
            'ai_calls'     => $logs->count($user),
            'users'        => (new User())->count(),
        ];

        // Both series share one label axis, so build them together.
        $itemsPerDay = $items->countCreatedPerDay(self::SERIES_DAYS, $user);
        $aiPerDay    = $logs->countPerDay(self::SERIES_DAYS, $user);

        $series = ['labels' => [], 'items' => [], 'ai' => []];
        foreach ($itemsPerDay as $day => $count) {
            $series['labels'][] = date('j M', strtotime($day));
            $series['items'][]  = $count;
            $series['ai'][]     = $aiPerDay[$day] ?? 0;
        }

        // Admin dashboard shows app-wide extras; the user dashboard does not.
        $admin = [
            'admins'   => (new User())->countByRole('admin'),
            'disabled' => (new User())->countByStatus('disabled'),
            'keys'     => (new ApiKey())->count(),
            'chats'    => (new ChatSession())->allSessions(5),
        ];

        view('dashboard/index', [
            'title'   => 'Dashboard',
            'user'    => $user,
            'stats'   => $stats,
            'series'  => $series,
            'recent'  => $items->recent($user, 5),
            'admin'   => $admin,
        ]);
    }
}
