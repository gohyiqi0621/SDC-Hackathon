<?php
/**
 * InvoicePdfService.php - the business's invoice (A4 PDF).
 *
 * Layout: DocumentPdf, the same formal black-and-white design as the Official
 * Receipt. The issuer is the invoice owner's Company Profile (company_profiles,
 * looked up by invoices.user_id), so an invoice sent from Telegram (no one
 * signed in) still shows the right business.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\CompanyProfile;
use Throwable;

final class InvoicePdfService
{
    /** @param int|null $userId the invoice owner (invoices.user_id); null = the signed-in user */
    public function render(array $invoice, array $items, ?int $userId = null): string
    {
        $co  = $this->company($userId ?? (int) ($invoice['user_id'] ?? 0));
        $cur = (string) ($invoice['currency'] ?? 'MYR');
        $no  = (string) ($invoice['invoice_number'] ?? '');

        $doc = new DocumentPdf('Invoice ' . $no, $co,
            'This is a computer-generated invoice. No signature is required.');
        $doc->footerRef($no . '  ·  Generated ' . date('d-m-Y, g:i a'));
        $doc->header(($invoice['type'] ?? '') === 'sales' ? 'Sales Invoice' : 'Invoice');

        $status = ['unpaid' => 'Unpaid', 'partial' => 'Partially paid', 'paid' => 'Paid', 'voided' => 'Voided']
            [$invoice['status'] ?? ''] ?? ucfirst((string) ($invoice['status'] ?? ''));
        $phone  = trim((string) preg_replace('/^\s*(tel|phone|hp|h\/p)\s*[:.]\s*/i', '', (string) ($invoice['party_phone'] ?? '')));
        $facts  = [
            ['Invoice No.',   $no],
            ['Invoice Date',  $this->date($invoice['issue_date'] ?? null)],
            ['Due Date',      $this->date($invoice['due_date'] ?? null)],
        ];
        if (trim((string) ($invoice['reference_no'] ?? '')) !== '') {
            $facts[] = ['Reference',  (string) $invoice['reference_no']];
        }
        if (trim((string) ($invoice['payment_terms'] ?? '')) !== '') {
            $facts[] = ['Terms',      (string) $invoice['payment_terms']];
        }
        $facts[] = ['Currency', $cur];
        $facts[] = ['Status',   $status];

        $doc->parties('Bill To', (string) ($invoice['party_name'] ?? '') ?: 'Customer', [
            (string) ($invoice['party_address'] ?? ''),
            $phone !== '' ? 'Tel: ' . $phone : '',
        ], $facts);

        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $uom  = trim((string) ($item['uom'] ?? ''));
            $rows[] = [
                (string) ($i + 1),
                (string) ($item['description'] ?? ''),
                trim((string) ($item['sku'] ?? '')) ?: '-',
                $this->quantity($item['quantity'] ?? null) . ($uom !== '' ? ' ' . $uom : ''),
                $this->money($item['unit_price'] ?? null),
                (float) ($item['discount'] ?? 0) > 0 ? $this->money($item['discount']) : '-',
                $this->money($item['amount'] ?? null),
            ];
        }
        $doc->table([
            ['No.', 11, 'C'],
            ['Description', 59, 'L', true],
            ['SKU', 22, 'L'],
            ['Qty', 18, 'C'],
            ['Unit Price', 22, 'R'],
            ['Disc.', 18, 'R'],
            ['Amount', 24, 'R'],
        ], $rows, 'No item details were saved for this invoice.');

        $subtotal = (float) ($invoice['subtotal'] ?? $invoice['amount'] ?? 0);
        $discount = (float) ($invoice['discount_amount'] ?? 0);
        $sst      = (float) ($invoice['sst_amount'] ?? 0);
        $rate     = rtrim(rtrim(number_format((float) ($invoice['sst_rate'] ?? 0), 2, '.', ''), '0'), '.');
        $totals   = [['Subtotal', $this->money($subtotal)]];
        if ($discount > 0) {
            $totals[] = ['Less: Discount', '(' . $this->money($discount) . ')'];
            $totals[] = ['Taxable Amount', $this->money(max(0, $subtotal - $discount))];
        }
        $totals[] = ['SST ' . ($rate === '' ? '0' : $rate) . '%', $this->money($sst)];
        $paid = (float) ($invoice['amount_paid'] ?? 0);
        $doc->totals($totals, 'Total (' . $cur . ')', $this->money($invoice['amount'] ?? 0));
        if ($paid > 0 && $paid < (float) ($invoice['amount'] ?? 0)) {
            $doc->labelled('Paid to date:', $cur . ' ' . $this->money($paid) . '    Balance due: ' . $cur . ' '
                . $this->money(max(0, (float) $invoice['amount'] - $paid)));
        }

        if ($cur === 'MYR') {
            $doc->labelled('Amount in words:', OfficialReceiptPdf::ringgitInWords((float) ($invoice['amount'] ?? 0)));
        }

        if (($invoice['status'] ?? '') !== 'paid' && $co['bank'] !== '' && $co['account_no'] !== '') {
            $doc->section('Payment Details', [
                'Bank: ' . $co['bank'],
                'Account No.: ' . $co['account_no'] . ($co['account_name'] !== '' ? '    Account Name: ' . $co['account_name'] : ''),
                'Please quote invoice no. ' . $no . ' as the payment reference.',
            ]);
        }

        $contact = array_filter([$co['email'], $co['tel']]);
        $doc->section('Notes', [
            trim((string) ($invoice['notes'] ?? '')),
            'Terms: ' . (trim((string) ($invoice['payment_terms'] ?? '')) ?: 'As agreed.'),
            $contact !== [] ? 'For questions, contact us at ' . implode(' or ', $contact) . '.' : '',
            'Thank you for your business.',
        ]);

        return $doc->output();
    }

    /** The invoice owner's Company Profile; the signed-in company() details when no profile row exists. */
    private function company(int $userId): array
    {
        $row = [];
        if ($userId <= 0) {
            $userId = (int) (\current_user()['id'] ?? 0);
        }
        try {
            if ($userId > 0) {
                $row = (new CompanyProfile())->find($userId) ?? [];
            }
        } catch (Throwable) {
            $row = [];
        }
        if ($row === []) {
            $c   = \company();
            $row = [
                'company_name'    => $c['name'] ?? '',
                'company_address' => $c['address'] ?? '',
                'registration_no' => $c['reg_no'] ?? '',
                'company_phone'   => $c['tel'] ?? '',
                'company_email'   => $c['email'] ?? '',
                'payment_bank'    => $c['payment_bank'] ?? '',
                'payment_account_no'   => $c['payment_account_no'] ?? '',
                'payment_account_name' => $c['payment_account_name'] ?? '',
            ];
        }
        return DocumentPdf::company($row);
    }

    private function date(mixed $value): string
    {
        return $value ? \fmt_datetime((string) $value, 'd-m-Y') : '-';
    }

    private function quantity(mixed $value): string
    {
        return ($value === null || $value === '') ? '-' : rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
