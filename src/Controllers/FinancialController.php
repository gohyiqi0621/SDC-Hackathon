<?php
/**
 * FinancialController.php - Invoice, Claims, Settlement and Upload Invoice.
 *
 * The pages are wired into the portal and protected by login; their data
 * sources are the next integration step, so they show honest empty states
 * instead of made-up numbers.
 */
declare(strict_types=1);

namespace Src\Controllers;

final class FinancialController
{
    /** GET /?page=financial&action=invoice */
    public function invoice(): void
    {
        view('financial/invoice', ['user' => require_login()]);
    }

    /** GET /?page=financial&action=claims */
    public function claims(): void
    {
        view('financial/claims', ['user' => require_login()]);
    }

    /** GET /?page=financial&action=settlement */
    public function settlement(): void
    {
        view('financial/settlement', ['user' => require_login()]);
    }

    /** GET /?page=financial&action=upload */
    public function upload(): void
    {
        view('financial/upload', ['user' => require_login()]);
    }
}
