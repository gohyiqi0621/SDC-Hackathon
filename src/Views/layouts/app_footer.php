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

<!-- JavaScript -->
<script src="<?= e(asset_versioned('assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset_versioned($script)) ?>"></script>
<?php endforeach; ?>

</body>
</html>
