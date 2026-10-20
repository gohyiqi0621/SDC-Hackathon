<?php
/**
 * ClaimService.php - validation and receipt handling for expense claims.
 *
 * Controllers pass raw $_POST / $_FILES in and get back either clean data or
 * field errors. Receipts are private (see PrivateFiles): stored under
 * storage/uploads/claims/<user id>/ and only sent back after an ownership check.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\Claim;

final class ClaimService
{
    /**
     * Validate the claim form.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean data, errors]
     */
    public function validate(array $input): array
    {
        $errors = [];

        $claimant = trim((string) ($input['claimant_name'] ?? ''));
        if ($claimant === '') {
            $errors['claimant_name'] = 'Enter the name of the person claiming.';
        } elseif (mb_strlen($claimant) > 150) {
            $errors['claimant_name'] = 'Keep the name under 150 characters.';
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Say what the claim is for.';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = 'Keep the title under 200 characters.';
        }

        $category = (string) ($input['category'] ?? '');
        if (!isset(Claim::CATEGORIES[$category])) {
            $errors['category'] = 'Choose a category.';
        }

        $expenseDate = InvoiceService::date($input['expense_date'] ?? '');
        if ($expenseDate === null) {
            $errors['expense_date'] = 'Enter the date the money was spent.';
        } elseif ($expenseDate > date('Y-m-d')) {
            $errors['expense_date'] = 'The expense date cannot be in the future.';
        }

        [$amount, $amountError] = InvoiceService::money($input['amount'] ?? '', 'Enter the amount spent.');
        if ($amountError !== null) {
            $errors['amount'] = $amountError;
        }

        $currency = strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR';
        if (!in_array($currency, Claim::CURRENCIES, true)) {
            $errors['currency'] = 'Choose a currency from the list.';
        }

        $notes = trim(str_replace("\r\n", "\n", (string) ($input['notes'] ?? '')));
        if (mb_strlen($notes) > 2000) {
            $errors['notes'] = 'Keep the notes under 2,000 characters.';
        }

        $data = [
            'claimant_name' => $claimant,
            'title'         => $title,
            'category'      => $category,
            'expense_date'  => $expenseDate,
            'amount'        => $amount,
            'currency'      => $currency,
            'notes'         => $notes !== '' ? $notes : null,
        ];

        return [$data, $errors];
    }

    public function files(): PrivateFiles
    {
        return new PrivateFiles('claims');
    }
}
