<?php
/**
 * layouts/auth_footer.php - closes the auth screen, with the links to the
 * public Terms and Privacy Policy under it.
 */
?>
        <nav class="auth-legal" aria-label="Legal">
            <a href="<?= e(url('terms')) ?>">Terms of Service</a>
            <span aria-hidden="true">&middot;</span>
            <a href="<?= e(url('privacy')) ?>">Privacy Policy</a>
        </nav>
    </div>
</main>

<div class="toast-region" id="toastRegion" aria-live="polite"></div>

<script src="<?= e(asset_versioned('assets/js/app.js')) ?>"></script>

</body>
</html>
