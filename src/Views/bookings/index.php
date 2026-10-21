<?php
/**
 * bookings/index.php - bookings made by the AI chatbot (Google Calendar).
 *
 * Expects: $bookings, $stats, $when, $q, $total, $page, $pages, $perPage,
 * $zone (DateTimeZone), $rules (?string), $missingTable.
 */
use Src\Controllers\BookingController;

view('layouts/header', ['title' => 'Bookings', 'styles' => ['assets/css/orders.css']]);

$listQuery = array_filter(['when' => $when === 'upcoming' ? '' : $when, 'q' => $q], static fn ($v) => $v !== '');
$listUrl   = url('bookings', $listQuery + ($page > 1 ? ['p' => $page] : []));
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
$local     = static fn (string $ts): DateTimeImmutable => (new DateTimeImmutable($ts))->setTimezone($zone);
$today     = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Bookings</h1>
        <p>Time slots customers booked with your AI chatbot. Each booking is also in your Google Calendar.</p>
    </div>
</section>

<div class="page-body">

    <?php if ($missingTable): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>The bookings table does not exist yet</strong>
                <span>Run <code>C:\xampp\php\php.exe bin\migrate.php</code> in the project folder, then reload this page.</span>
            </div>
        </div>
    <?php elseif ($rules === null): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-regular fa-calendar-xmark"></i>
            <div>
                <strong>The AI cannot take bookings yet</strong>
                <span>Connect Google Calendar and choose a calendar in <a href="<?= e(url('ai_settings', ['action' => 'google_calendar'])) ?>">AI Settings &amp; Integration › Google Calendar</a>.</span>
            </div>
        </div>
    <?php endif; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-regular fa-calendar-check"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['today'] ?></h2><p>Today (<?= (int) $stats['today_pax'] ?> pax)</p></div>
        </div>
        <a class="stat-card green order-stat" href="<?= e(url('bookings')) ?>">
            <div class="stat-icon"><i class="fa-regular fa-clock"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['upcoming'] ?></h2><p>Upcoming</p></div>
        </a>
        <a class="stat-card pink order-stat" href="<?= e(url('bookings', ['when' => 'cancelled'])) ?>">
            <div class="stat-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['cancelled'] ?></h2><p>Cancelled</p></div>
        </a>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2><?= e(BookingController::VIEWS[$when]) ?> bookings</h2>
                <p>
                    <?php if ($total > 0): ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= (int) $total ?>. Times are in <?= e(str_replace('_', ' ', $zone->getName())) ?>.
                    <?php else: ?>
                        Nothing here yet.
                    <?php endif; ?>
                </p>
            </div>
            <a class="secondary-btn btn-sm" href="<?= e(url('ai_settings', ['action' => 'google_calendar'])) ?>"><i class="fa-solid fa-sliders"></i> Booking rules</a>
        </div>

        <?php if ($rules !== null): ?>
            <p class="booking-rules-line"><i class="fa-regular fa-calendar"></i> <?= e($rules) ?></p>
        <?php endif; ?>

        <nav class="order-tabs" aria-label="Filter bookings">
            <?php foreach (BookingController::VIEWS as $key => $label): ?>
                <a href="<?= e(url('bookings', array_filter(['when' => $key === 'upcoming' ? '' : $key, 'q' => $q]))) ?>"<?= $when === $key ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <form class="toolbar" method="get" action="<?= e(url('bookings')) ?>">
            <input type="hidden" name="page" value="bookings">
            <?php if ($when !== 'upcoming'): ?><input type="hidden" name="when" value="<?= e($when) ?>"><?php endif; ?>
            <div class="toolbar-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <label class="sr-only" for="q">Search</label>
                <input id="q" name="q" type="search" placeholder="Search booking no., name or phone..." value="<?= e($q) ?>">
            </div>
            <button class="secondary-btn" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
            <?php if ($q !== ''): ?>
                <a class="toolbar-reset" href="<?= e(url('bookings', array_filter(['when' => $when === 'upcoming' ? '' : $when]))) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($bookings === []): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-regular fa-calendar"></i></div>
                <h3>No bookings</h3>
                <p><?= $q !== '' ? 'No bookings match your search.' : 'When a customer books a time with your AI chatbot, it appears here and in your Google Calendar.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table order-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Customer</th>
                            <th class="num">Pax</th>
                            <th>Booking</th>
                            <th>Status</th>
                            <th class="actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <?php
                            $start    = $local((string) $b['starts_at']);
                            $end      = $local((string) $b['ends_at']);
                            $isToday  = $start->format('Y-m-d') === $today;
                            $upcoming = $b['status'] === 'confirmed' && $end->getTimestamp() > time();
                            ?>
                            <tr>
                                <td data-label="When">
                                    <strong><?= $isToday ? 'Today' : e($start->format('D, j M Y')) ?></strong>
                                    <small class="muted"><?= e($start->format('g:i a')) ?> – <?= e($end->format('g:i a')) ?></small>
                                </td>
                                <td data-label="Customer">
                                    <?= e($b['customer_name'] ?: 'Telegram chat ' . $b['customer_chat_id']) ?>
                                    <?php if (!empty($b['customer_username'])): ?>
                                        <small class="muted">@<?= e($b['customer_username']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($b['customer_phone'])): ?>
                                        <small class="muted"><i class="fa-solid fa-phone"></i> <?= e($b['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Pax" class="num"><strong><?= (int) $b['pax'] ?></strong></td>
                                <td data-label="Booking">
                                    <strong class="order-no"><?= e($b['booking_no']) ?></strong>
                                    <?php if (!empty($b['note'])): ?>
                                        <small class="muted"><i class="fa-regular fa-note-sticky"></i> <?= e($b['note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($b['status'] === 'cancelled'): ?>
                                        <span class="badge badge--muted">Cancelled</span>
                                    <?php elseif ($upcoming): ?>
                                        <span class="badge badge--success">Confirmed</span>
                                    <?php else: ?>
                                        <span class="badge badge--muted">Done</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions order-actions">
                                    <?php if ($upcoming): ?>
                                        <form method="post" action="<?= e(url('bookings', ['action' => 'cancel'])) ?>"
                                              data-confirm="Cancel <?= e($b['booking_no']) ?>? It is removed from Google Calendar and the customer is told on Telegram."
                                              data-confirm-title="Cancel booking?" data-confirm-ok="Cancel booking">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="icon-btn icon-btn--danger" type="submit" title="Cancel booking" aria-label="Cancel <?= e($b['booking_no']) ?>">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="Pages">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="<?= e(url('bookings', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="active" aria-current="page"' : '' ?>><?= $i ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php view('layouts/footer'); ?>
