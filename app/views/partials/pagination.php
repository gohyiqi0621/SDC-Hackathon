<?php
/**
 * partials/pagination.php - page links that keep the current filters.
 * Expects: $page, $pages, $total, $query (extra query params), $label (what is counted).
 *
 * Rendered as the v2 .pager row that sits inside a .card--flush.
 */
$page  = (int) $page;
$pages = (int) $pages;

if ($pages <= 1) {
    return;
}

$link = static fn(int $n): string => APP_URL . '/?' . http_build_query($query + ['p' => $n]);

// First, last, and a couple of pages either side of the current one.
$numbers = [];
for ($n = 1; $n <= $pages; $n++) {
    if ($n === 1 || $n === $pages || abs($n - $page) <= 2) {
        $numbers[] = $n;
    }
}
?>
<div class="pager">
    <span><?= e($total) ?> <?= e($label) ?> &middot; page <?= e($page) ?> of <?= e($pages) ?></span>
    <div class="pager__nav">
        <?php if ($page > 1): ?>
            <a class="btn btn--sm" href="<?= e($link($page - 1)) ?>" rel="prev">Previous</a>
        <?php else: ?>
            <button class="btn btn--sm" type="button" disabled>Previous</button>
        <?php endif; ?>

        <?php $previous = 0; ?>
        <?php foreach ($numbers as $n): ?>
            <?php if ($previous !== 0 && $n - $previous > 1): ?>
                <span class="btn btn--sm" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
            <?php if ($n === $page): ?>
                <button class="btn btn--sm btn--primary" type="button" aria-current="page"><?= e($n) ?></button>
            <?php else: ?>
                <a class="btn btn--sm" href="<?= e($link($n)) ?>"><?= e($n) ?></a>
            <?php endif; ?>
            <?php $previous = $n; ?>
        <?php endforeach; ?>

        <?php if ($page < $pages): ?>
            <a class="btn btn--sm" href="<?= e($link($page + 1)) ?>" rel="next">Next</a>
        <?php else: ?>
            <button class="btn btn--sm" type="button" disabled>Next</button>
        <?php endif; ?>
    </div>
</div>
