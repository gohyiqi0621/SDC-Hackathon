<?php
/**
 * InvoiceService.php - validation and file handling for invoices.
 *
 * Controllers pass raw $_POST / $_FILES in and get back either clean data or
 * field errors. Invoice files are private (see PrivateFiles): stored under
 * storage/uploads/invoices/<user id>/ and only ever sent back through
 * FinancialController::file() after an ownership check.
 */
declare(strict_types=1);

namespace Src\Services;

use DateTimeImmutable;
use Src\Models\Invoice;

final class InvoiceService
{
    public const FILE_MAX_BYTES = PrivateFiles::MAX_BYTES;
    private const MAX_AMOUNT    = 9999999999.99; // NUMERIC(12,2)

    /**
     * Validate the invoice form.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean data, errors]
     */
    public function validate(int $userId, array $input, ?int $invoiceId = null): array
    {
        $errors = [];

        $number = trim((string) ($input['invoice_number'] ?? ''));
        if ($number === '') {
            $errors['invoice_number'] = 'Enter the invoice number.';
        } elseif (mb_strlen($number) > 100) {
            $errors['invoice_number'] = 'Keep the invoice number under 100 characters.';
        } elseif ((new Invoice())->numberExists($userId, $number, $invoiceId)) {
            $errors['invoice_number'] = 'You already have an invoice with this number.';
        }

        $party = trim((string) ($input['party_name'] ?? ''));
        if ($party === '') {
            $errors['party_name'] = 'Enter the customer or supplier name.';
        } elseif (mb_strlen($party) > 200) {
            $errors['party_name'] = 'Keep the name under 200 characters.';
        }

        $issueDate = self::date($input['issue_date'] ?? '');
        if ($issueDate === null) {
            $errors['issue_date'] = 'Enter the invoice date.';
        }

        $dueRaw  = trim((string) ($input['due_date'] ?? ''));
        $dueDate = $dueRaw === '' ? null : self::date($dueRaw);
        if ($dueRaw !== '' && $dueDate === null) {
            $errors['due_date'] = 'Enter a valid due date.';
        } elseif ($dueDate !== null && $issueDate !== null && $dueDate < $issueDate) {
            $errors['due_date'] = 'The due date cannot be before the invoice date.';
        }

        [$amount, $amountError] = self::money($input['amount'] ?? '', 'Enter the invoice amount.');
        if ($amountError !== null) {
            $errors['amount'] = $amountError;
        }

        $currency = strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR';
        if (!in_array($currency, Invoice::CURRENCIES, true)) {
            $errors['currency'] = 'Choose a currency from the list.';
        }

        $status = (string) ($input['status'] ?? 'unpaid');
        if (!in_array($status, Invoice::STATUSES, true)) {
            $errors['status'] = 'Choose Unpaid, Partially paid or Paid.';
        }

        $notes = trim(str_replace("\r\n", "\n", (string) ($input['notes'] ?? '')));
        if (mb_strlen($notes) > 2000) {
            $errors['notes'] = 'Keep the notes under 2,000 characters.';
        }

        $data = [
            'invoice_number' => $number,
            'party_name'     => $party,
            'issue_date'     => $issueDate,
            'due_date'       => $dueDate,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => $status,
            'notes'          => $notes !== '' ? $notes : null,
        ];

        return [$data, $errors];
    }

    /* ------------------------------------------------------------- files --- */

    /** @return string|null an error message, or null when there is no file or it is fine */
    public function fileError(?array $file): ?string
    {
        return $this->files()->error($file);
    }

    /** True when the form actually carried a file. */
    public function hasFile(?array $file): bool
    {
        return $this->files()->hasFile($file);
    }

    /** @return array{file_path: string, file_name: string, file_mime: string, file_size: int}|null */
    public function storeFile(int $userId, array $file): ?array
    {
        return $this->files()->store($userId, $file);
    }

    /** Absolute path of a file this app stored for this user, or null. */
    public function pathFor(int $userId, ?string $relative): ?string
    {
        return $this->files()->pathFor($userId, $relative);
    }

    public function deleteFile(int $userId, ?string $relative): void
    {
        $this->files()->delete($userId, $relative);
    }

    public function files(): PrivateFiles
    {
        return new PrivateFiles('invoices');
    }

    /* ----------------------------------------------------------- helpers --- */

    /** "YYYY-MM-DD" when the input is a real calendar date, otherwise null. Shared with ClaimService. */
    public static function date(mixed $raw): ?string
    {
        $value = trim((string) $raw);
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * A money field ("1,250.5") as exact text ("1250.50"). Shared with ClaimService.
     *
     * @return array{0: ?string, 1: ?string} [amount or null, error]
     */
    public static function money(mixed $raw, string $requiredMessage): array
    {
        $value = str_replace([',', ' '], '', trim((string) $raw));

        if ($value === '') {
            return [null, $requiredMessage];
        }

        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            return [null, 'Enter an amount like 250 or 250.90 (no negative numbers).'];
        }

        if ((float) $value > self::MAX_AMOUNT) {
            return [null, 'That amount is too large.'];
        }

        // Format as text (no float rounding): "25" -> "25.00", "007.5" -> "7.50".
        [$whole, $cents] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        return [$whole . '.' . str_pad($cents, 2, '0'), null];
    }
}
