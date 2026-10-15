<?php
/**
 * layouts/app_footer.php — closes <main>, adds footer, tab bar, dialogs, scripts.
 *
 * $nav and $pageKey are recomputed here rather than inherited: the footer is a
 * separate view() call, so the header's locals are out of scope. nav_links() is
 * the single source of truth for both bars.
 *
 * Optional: ['scripts' => ['https://cdn.../chart.js', asset('js/dashboard.js')]]
 *   Order is preserved: CDN scripts load first, then page scripts.
 *   Files under public/js/ may be given as a bare name: 'dashboard.js'.
 */
$nav     = $nav ?? nav_links(current_user());
$pageKey = $pageKey ?? current_page_key();
$scripts = $scripts ?? [];
?>
    </main>

    <footer class="footer"><span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span><span>SDC Hackathon 2026</span></footer>
  </div>

  <nav class="tabbar liquid-glass-strong" aria-label="Primary">
    <span class="tabbar__pill" aria-hidden="true"></span>
    <?php foreach ($nav as $link): ?>
      <a class="tabbar__item" href="<?= e($link['url']) ?>"<?= $link['page'] === $pageKey ? ' aria-current="page"' : '' ?>><?= icon($link['icon']) ?><span><?= e($link['label']) ?></span></a>
    <?php endforeach; ?>
  </nav>
</div>

<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title">
  <form method="dialog" class="modal__panel">
    <div class="modal__head"><h3 id="confirm-title" data-confirm-title>Are you sure?</h3></div>
    <div class="modal__body"><p data-confirm-message></p></div>
    <div class="modal__foot">
      <button class="btn" type="submit" value="cancel">Cancel</button>
      <button class="btn btn--danger-solid" type="submit" value="confirm" data-confirm-ok>Confirm</button>
    </div>
  </form>
</dialog>
<div class="toast-region" id="toast-stack" aria-live="polite"></div>

<script src="<?= e(asset('js/main.js')) ?>"></script>
<script src="<?= e(asset('js/ui.js')) ?>"></script>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<?php if (ONESIGNAL_APP_ID !== ''): ?>
<script src="<?= e(asset('js/onesignal.js')) ?>" defer></script>
<?php endif; ?>
<?php foreach ($scripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
