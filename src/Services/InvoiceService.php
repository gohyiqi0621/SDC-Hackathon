<?php
/**
 * InvoiceService.php - validation and totals for creating invoices.
 *
 * The business builds an invoice from its own Products & Services: the form
 * sends lines[<product id>] = quantity, and the name, SKU and PRICE are read
 * here from the database (never from the browser), then copied onto the
 * invoice. Totals: subtotal + SST (the company's fixed rate) = amount.
 *
 * Also: payment validation for receipts, and the shared date / money parsers
 * used by ClaimService.
 */
declare(strict_types=1);

namespace Src\Services;

use DateTimeImmutable;
use Src\Models\Invoice;
use Src\Models\Product;
use Src\Models\Receipt;

final class InvoiceService
{
    private const MAX_AMOUNT   = 9999999999.99; // NUMERIC(12,2)
    private const MAX_QUANTITY = 99999;

    /**
     * Validate the create-invoice form.
     *
     * @param bool   $issue   true = "Create invoice" (needs at least one item), false = "Save as draft"
     * @param string $sstRate the company's rate, e.g. "8.00" ("0.00" when not SST-registered)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean data, errors]
     */
    public function validate(int $userId, array $input, bool $issue, string $sstRate): array
    {
        $errors = [];

        $customer = trim((string) ($input['party_name'] ?? ''));
        if ($customer === '') {
            $errors['party_name'] = 'Enter the customer name.';
        } elseif (mb_strlen($customer) > 200) {
            $errors['party_name'] = 'Keep the name under 200 characters.';
        }

        $address = self::text($input['party_address'] ?? '', 1000, 'party_address', 'Keep the address under 1,000 characters.', $errors);
        $phone   = self::text($input['party_phone'] ?? '', 50, 'party_phone', 'Keep the phone number under 50 characters.', $errors);
        // Payment terms: one of Invoice::PAYMENT_TERMS, or "__custom" + free text.
        $termsRaw = (string) ($input['payment_terms'] ?? '');
        if ($termsRaw === '__custom') {
            $termsRaw = (string) ($input['payment_terms_custom'] ?? '');
        }
        $terms = self::text($termsRaw, 100, 'payment_terms', 'Keep the payment terms under 100 characters.', $errors);
        $ref     = self::text($input['reference_no'] ?? '', 100, 'reference_no', 'Keep the reference under 100 characters.', $errors);
        $notes   = self::text($input['notes'] ?? '', 2000, 'notes', 'Keep the notes under 2,000 characters.', $errors);

        $issueDate = self::date($input['issue_date'] ?? '');
        if ($issueDate === null) {
            $errors['issue_date'] = 'Enter the invoice date.';
        }

        $dueRaw  = trim((string) ($input['due_date'] ?? ''));
        $dueDate = $dueRaw === '' ? null : self::date($dueRaw);

        // A standard term with no due date given: work it out (invoice date + days).
        if ($dueRaw === '' && $issueDate !== null && $terms !== null && isset(Invoice::PAYMENT_TERMS[$terms])) {
            $dueDate = (new DateTimeImmutable($issueDate))->modify('+' . Invoice::PAYMENT_TERMS[$terms] . ' days')->format('Y-m-d');
        }
        if ($dueRaw !== '' && $dueDate === null) {
            $errors['due_date'] = 'Enter a valid due date.';
        } elseif ($dueDate !== null && $issueDate !== null && $dueDate < $issueDate) {
            $errors['due_date'] = 'The due date cannot be before the invoice date.';
        }

        $currency = strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR';
        if (!in_array($currency, Invoice::CURRENCIES, true)) {
            $errors['currency'] = 'Choose a currency from the list.';
        }

        [$items, $itemsError] = $this->items($userId, $input['lines'] ?? [], $currency);
        if ($itemsError !== null) {
            $errors['items'] = $itemsError;
        } elseif ($issue && $items === []) {
            $errors['items'] = 'Tick at least one product or service to invoice.';
        }

        // Exact cents: subtotal, then SST on it, rounded half up.
        $subtotalCents = array_sum(array_map(static fn (array $i) => self::cents($i['amount']), $items));
        $rateHundredth = (int) round((float) $sstRate * 100);
        $sstCents      = intdiv($subtotalCents * $rateHundredth + 5000, 10000);
        $amount        = self::fromCents($subtotalCents + $sstCents);

        if ((float) $amount > self::MAX_AMOUNT) {
            $errors['items'] = 'The invoice total is too large.';
        }

        $data = [
            'party_name'      => $customer,
            'party_address'   => $address,
            'party_phone'     => $phone,
            'payment_terms'   => $terms,
            'reference_no'    => $ref,
            'issue_date'      => $issueDate,
            'due_date'        => $dueDate,
            'subtotal'        => self::fromCents($subtotalCents),
            'discount_amount' => '0.00',
            'sst_rate'        => number_format((float) $sstRate, 2, '.', ''),
            'sst_amount'      => self::fromCents($sstCents),
            'amount'          => $amount,
            'currency'        => $currency,
            'notes'           => $notes,
            'order_id'        => null,
            'items'           => $items,
        ];

        return [$data, $errors];
    }

    /**
     * lines[<product id>] = quantity  ->  invoice items priced from the catalog.
     * Lines with no quantity (unticked) are ignored.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string} [items, one error for the whole table]
     */
    private function items(int $userId, mixed $lines, string $currency): array
    {
        if (!is_array($lines)) {
            return [[], null];
        }

        $wanted = [];
        foreach ($lines as $productId => $qtyRaw) {
            $qty = str_replace([',', ' '], '', trim((string) (is_scalar($qtyRaw) ? $qtyRaw : '')));
            if ($qty === '' || (int) $productId <= 0) {
                continue;
            }
            $wanted[(int) $productId] = $qty;
        }

        if ($wanted === []) {
            return [[], null];
        }
        if (count($wanted) > 100) {
            return [[], 'An invoice can have at most 100 items.'];
        }

        $products = [];
        foreach ((new Product())->forInvoicing($userId, array_keys($wanted)) as $p) {
            $products[(int) $p['id']] = $p;
        }

        $items = [];
        foreach ($wanted as $productId => $qty) {
            $product = $products[$productId] ?? null;
            if ($product === null) {
                return [[], 'One of the ticked products is no longer available. Refresh the page and try again.'];
            }

            $name = (string) $product['name'];
            if (!preg_match('/^\d{1,5}(\.\d{1,3})?$/', $qty) || (float) $qty <= 0 || (float) $qty > self::MAX_QUANTITY) {
                return [[], '"' . $name . '": enter a quantity above 0 (up to 3 decimals).'];
            }
            if (strtoupper((string) $product['currency']) !== $currency) {
                return [[], '"' . $name . '" is priced in ' . $product['currency'] . ', but this invoice is in ' . $currency . '.'];
            }

            // Exact cents: quantity in thousandths x price in cents, rounded half up.
            $qtyMilli = (int) round((float) $qty * 1000);
            $line     = intdiv($qtyMilli * self::cents(number_format((float) $product['price'], 2, '.', '')) + 500, 1000);

            $items[] = [
                'product_id'  => $productId,
                'sku'         => $product['sku'] !== null && $product['sku'] !== '' ? (string) $product['sku'] : null,
                'description' => mb_substr($name, 0, 255),
                'uom'         => null,
                'quantity'    => rtrim(rtrim(number_format($qtyMilli / 1000, 3, '.', ''), '0'), '.'),
                'unit_price'  => number_format((float) $product['price'], 2, '.', ''),
                'discount'    => '0.00',
                'amount'      => self::fromCents($line),
            ];
        }

        return [$items, null];
    }

    /**
     * Validate a payment against the balance due.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean data, errors]
     */
    public function validatePayment(array $input, array $invoice): array
    {
        $errors  = [];
        $balance = (float) $invoice['amount'] - (float) $invoice['amount_paid'];

        [$amount, $amountError] = self::money($input['amount'] ?? '', 'Enter the amount received.');
        if ($amountError !== null) {
            $errors['amount'] = $amountError;
        } elseif ((float) $amount <= 0) {
            $errors['amount'] = 'Enter an amount above 0.';
        } elseif ((float) $amount - $balance > 0.001) {
            $errors['amount'] = 'That is more than the balance due (' . number_format($balance, 2) . ').';
        }

        $date = self::date($input['payment_date'] ?? '');
        if ($date === null) {
            $errors['payment_date'] = 'Enter the date the money was received.';
        } elseif ($date > date('Y-m-d')) {
            $errors['payment_date'] = 'The payment date cannot be in the future.';
        }

        $method = (string) ($input['method'] ?? '');
        if (!isset(Receipt::METHODS[$method])) {
            $errors['method'] = 'Choose how the customer paid.';
        }

        $reference = self::text($input['reference'] ?? '', 100, 'reference', 'Keep the reference under 100 characters.', $errors);
        $notes     = self::text($input['notes'] ?? '', 500, 'notes', 'Keep the notes under 500 characters.', $errors);

        return [[
            'amount'       => $amount,
            'payment_date' => $date,
            'method'       => $method,
            'reference'    => $reference,
            'notes'        => $notes,
        ], $errors];
    }

    /** Files uploaded with older (uploaded) invoices are still served from here. */
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
