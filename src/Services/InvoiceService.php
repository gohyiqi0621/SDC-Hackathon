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

        $type = (string) ($input['type'] ?? 'purchase');
        if (!isset(Invoice::TYPES[$type])) {
            $errors['type'] = 'Choose Purchase or Sales.';
            $type = 'purchase';
        }

        $party = trim((string) ($input['party_name'] ?? ''));
        if ($party === '') {
            $errors['party_name'] = 'Enter the ' . strtolower(Invoice::TYPES[$type][1]) . ' name.';
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

        $address = self::text($input['party_address'] ?? '', 1000, 'party_address', 'Keep the address under 1,000 characters.', $errors);
        $phone   = self::text($input['party_phone'] ?? '', 50, 'party_phone', 'Keep the phone number under 50 characters.', $errors);
        $terms   = self::text($input['payment_terms'] ?? '', 100, 'payment_terms', 'Keep the payment terms under 100 characters.', $errors);
        $ref     = self::text($input['reference_no'] ?? '', 100, 'reference_no', 'Keep the reference under 100 characters.', $errors);

        // Line items. With items, the totals are worked out here (never trusted
        // from the browser): amount = subtotal - discount + SST. Without items,
        // the typed amount is the total.
        [$items, $itemsError] = $this->items($input['items'] ?? []);
        if ($itemsError !== null) {
            $errors['items'] = $itemsError;
        }

        $subtotal = null;
        $discount = '0.00';
        $sstRate  = '0.00';
        $sstCents = 0;

        if ($items !== []) {
            $subtotalCents = array_sum(array_map(static fn (array $i) => self::cents($i['amount']), $items));

            [$discount, $discountError] = self::money($input['discount_amount'] ?? '', '');
            if ($discountError !== null && trim((string) ($input['discount_amount'] ?? '')) !== '') {
                $errors['discount_amount'] = $discountError;
            }
            $discount      = $discount ?? '0.00';
            $discountCents = self::cents($discount);
            if ($discountCents > $subtotalCents) {
                $errors['discount_amount'] = 'The discount cannot be more than the subtotal.';
                $discountCents = 0;
            }

            $rateRaw = str_replace([',', ' ', '%'], '', trim((string) ($input['sst_rate'] ?? '')));
            if ($rateRaw !== '' && (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rateRaw) || (float) $rateRaw > 100)) {
                $errors['sst_rate'] = 'Enter an SST rate between 0 and 100, e.g. 6 or 8.';
                $rateRaw = '';
            }
            $sstRate   = number_format((float) ($rateRaw === '' ? 0 : $rateRaw), 2, '.', '');
            $rateHund  = (int) round((float) $sstRate * 100);
            $taxable   = $subtotalCents - $discountCents;
            $sstCents  = intdiv($taxable * $rateHund + 5000, 10000);

            $subtotal = self::fromCents($subtotalCents);
            $amount   = self::fromCents($taxable + $sstCents);
            if ((float) $amount > self::MAX_AMOUNT) {
                $errors['items'] = 'The invoice total is too large.';
            }
        } else {
            [$amount, $amountError] = self::money($input['amount'] ?? '', 'Enter the invoice amount, or add the items.');
            // A broken item row is the real problem; don't also complain about the total.
            if ($amountError !== null && $itemsError === null) {
                $errors['amount'] = $amountError;
            }
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
            'type'            => $type,
            'invoice_number'  => $number,
            'party_name'      => $party,
            'party_address'   => $address,
            'party_phone'     => $phone,
            'payment_terms'   => $terms,
            'reference_no'    => $ref,
            'issue_date'      => $issueDate,
            'due_date'        => $dueDate,
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'sst_rate'        => $sstRate,
            'sst_amount'      => self::fromCents($sstCents),
            'amount'          => $amount,
            'currency'        => $currency,
            'status'          => $status,
            'notes'           => $notes !== '' ? $notes : null,
            'items'           => $items,
        ];

        return [$data, $errors];
    }

    /**
     * The submitted line items, cleaned. Fully blank rows are dropped.
     * Each line's amount = quantity x unit price - discount, worked out here.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string} [items, one error for the whole table]
     */
    private function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [[], null];
        }

        $items = [];
        $row   = 0;

        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }

            $sku   = trim((string) ($line['sku'] ?? ''));
            $desc  = trim((string) ($line['description'] ?? ''));
            $uom   = trim((string) ($line['uom'] ?? ''));
            $qty   = str_replace([',', ' '], '', trim((string) ($line['quantity'] ?? '')));
            $price = trim((string) ($line['unit_price'] ?? ''));
            $disc  = trim((string) ($line['discount'] ?? ''));

            if ($sku === '' && $desc === '' && $uom === '' && $qty === '' && $price === '' && $disc === '') {
                continue;
            }

            $row++;
            $at = 'Item ' . $row . ': ';

            if (count($items) >= 100) {
                return [$items, 'An invoice can have at most 100 items.'];
            }
            if ($desc === '') {
                return [$items, $at . 'enter the item name.'];
            }
            if (mb_strlen($desc) > 255 || mb_strlen($sku) > 100 || mb_strlen($uom) > 20) {
                return [$items, $at . 'the name, SKU or unit is too long.'];
            }
            if (!preg_match('/^\d{1,6}(\.\d{1,3})?$/', $qty) || (float) $qty <= 0) {
                return [$items, $at . 'enter a quantity above 0 (up to 3 decimals).'];
            }

            [$unitPrice, $priceError] = self::money($price, 'enter the unit price.');
            if ($priceError !== null) {
                return [$items, $at . lcfirst($priceError)];
            }
            if ((float) $unitPrice > 99999999.99) {
                return [$items, $at . 'the unit price is too large.'];
            }

            [$lineDisc, $discError] = self::money($disc === '' ? '0' : $disc, '');
            if ($discError !== null) {
                return [$items, $at . lcfirst($discError)];
            }

            // Exact cents: quantity in thousandths x price in cents, rounded half up.
            $qtyMilli  = (int) round((float) $qty * 1000);
            $gross     = intdiv($qtyMilli * self::cents($unitPrice) + 500, 1000);
            $discCents = self::cents($lineDisc);
            if ($discCents > $gross) {
                return [$items, $at . 'the discount cannot be more than quantity x unit price.'];
            }

            $items[] = [
                'sku'         => $sku !== '' ? $sku : null,
                'description' => $desc,
                'uom'         => $uom !== '' ? $uom : null,
                'quantity'    => rtrim(rtrim(number_format($qtyMilli / 1000, 3, '.', ''), '0'), '.'),
                'unit_price'  => $unitPrice,
                'discount'    => $lineDisc,
                'amount'      => self::fromCents($gross - $discCents),
            ];
        }

        return [$items, null];
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

    /** "123.45" -> 12345 */
    private static function cents(string $money): int
    {
        [$whole, $part] = array_pad(explode('.', $money, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($part, 0, 2), 2, '0');
    }

    /** 12345 -> "123.45" */
    private static function fromCents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Optional trimmed text: null when empty, an error under $field when too long. */
    private static function text(mixed $raw, int $max, string $field, string $tooLong, array &$errors): ?string
    {
        $value = trim(str_replace("\r\n", "\n", (string) $raw));
        if (mb_strlen($value) > $max) {
            $errors[$field] = $tooLong;
        }

        return $value === '' ? null : $value;
    }

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
