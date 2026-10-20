<?php
/**
 * layouts/app_footer.php - closes the signed-in shell and loads the scripts.
 */
?>
        </main>

        <footer class="footer">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
            <span>SDC Hackathon 2026</span>
        </footer>

    </div>

</div>

<div class="toast-region" id="toastRegion" aria-live="polite"></div>

<!-- Confirmation for destructive actions: <form data-confirm="Message"> -->
<dialog class="confirm-dialog" id="confirmDialog" aria-labelledby="confirmTitle">
    <form method="dialog">
        <div class="confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3 id="confirmTitle" data-confirm-title>Are you sure?</h3>
        <p data-confirm-message></p>
        <div class="confirm-actions">
            <button class="secondary-btn" type="submit" value="cancel">Cancel</button>
            <button class="danger-btn" type="submit" value="confirm" data-confirm-button>Confirm</button>
        </div>
    </form>
</dialog>

<!-- JavaScript -->
<script src="<?= e(asset_versioned('assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset_versioned($script)) ?>"></script>
<?php endforeach; ?>

</body>
</html>
