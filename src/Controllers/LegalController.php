<?php
/**
 * LegalController.php - the Privacy Policy and Terms of Service.
 *
 * Both are public (no sign-in): Google's OAuth consent screen links to them,
 * and visitors must be able to read them before they create an account.
 */
declare(strict_types=1);

namespace Src\Controllers;

final class LegalController
{
    /** The date shown as "Last updated" on both documents. */
    public const UPDATED = '24 October 2026';

    /** The address people write to about privacy, data deletion or these terms. */
    public const CONTACT_EMAIL = 'ctrlfuture.orbyte@gmail.com';

    /** GET /privacy */
    public function privacy(): void
    {
        view('legal/privacy', ['title' => 'Privacy Policy']);
    }

    /** GET /terms */
    public function terms(): void
    {
        view('legal/terms', ['title' => 'Terms of Service']);
    }
}
