<?php
/*
 * Closes the auth screen.
 *
 * The identity footer is deliberate. Sign-in is the only page an anonymous
 * visitor ever sees, so it is the page that has to state plainly what this
 * site is and who runs it. An unattributed credential form on an unrecognised
 * domain is exactly the shape a phishing kit takes, and Search Console flagged
 * /?page=auth&action=login (and /, which redirects there) for social
 * engineering. The footer removes the ambiguity without changing any flow.
 */
?>
<footer class="auth-footer">
    <p class="auth-footer__brand"><strong><?= e(APP_NAME) ?></strong> &middot; SDC Hackathon 2026</p>
    <p class="auth-footer__note">
        You are signing in to <?= e(APP_NAME) ?>, a student project built for the SDC Hackathon 2026.
        Accounts exist only on this site. This project is not affiliated with, endorsed by, or
        connected to Google or any other company.
    </p>
</footer>
</main>
<div class="toast-region" id="toast-stack" aria-live="polite"></div>

<script src="<?= e(asset('js/main.js')) ?>"></script>
<script src="<?= e(asset('js/ui.js')) ?>"></script>
<script src="<?= e(asset('js/api.js')) ?>"></script>
</body>
</html>
