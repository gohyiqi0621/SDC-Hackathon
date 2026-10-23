# Financial module (Invoice & Claims)

Read this before changing anything under Financial. It explains the flow,
what exists, and where it lives.

The app is multi-company: every account is its own business with its own
Company Profile, Products & Services, invoices and claims (everything is
scoped by `user_id`).

## The invoice flow

```
Setup (once)       Company Profile (name, SSM no., address, phone, email, bank)
                   Products & Services (what the business sells, with prices)
                   SST setting (Invoice page, Supervisor): registered? rate?

Create invoice     popup: customer (suggested from earlier invoices)
                   + tick products, set quantities (prices come from the catalog)
                   "Save as draft"  -> status draft, no number, still editable
                   "Create invoice" -> status unpaid, numbered INV-2026-0001

Get paid           Supervisor records each payment (partial allowed)
                   -> receipt RCPT-2026-0001, status partial -> paid

Print              invoice PDF (any non-draft) and receipt PDF (each payment)
```

Invoices are **not uploaded** any more (that was the old flow). Older uploaded
invoices still display, keep their file link, and cannot be edited.

## Pages

| Page | URL | What it does |
|---|---|---|
| Invoice | `?page=financial&action=invoice` | List + **Create invoice** popup + **Payments** popup + SST setting |
| Claims | `?page=financial&action=claims` | Staff expense claims: **New claim** popup (AI reads the receipt), approve / reject / reimburse |

Popup URLs: `&new=1`, `&edit=N` (draft, or unpaid for a Supervisor),
`&from_order=N` (filled from an order; the Orders page has an "Invoice" button),
`&payments=N`.

### Roles (Financial pages only)

A demo switch at the top of both pages ("Viewing as **User** / **Supervisor**"),
kept in the session: `finance_role()`, `is_supervisor()`, `require_supervisor()`
in `src/Support/helpers.php`.

| | User | Supervisor |
|---|---|---|
| Create invoices, save / edit drafts | yes (confirm popup before "Create") | yes |
| Print invoices and receipts, view payments | yes | yes |
| Record payments (receipts) | no | yes |
| Edit an unpaid invoice with no payments | no | yes |
| Delete a draft / an unpaid invoice with no payments | no | yes |
| Delete anything that has payments | no | **no** (money history is kept) |
| SST setting | no | yes |
| Claims: submit | yes | yes |
| Claims: edit, approve / reject / reimburse, delete | no | yes |

## Data model (PostgreSQL)

Migrations: `005_create_invoices`, `006_create_claims`, `016_add_invoice_details_and_items`,
`018_reconcile_invoice_items_print_columns`, `022_invoice_create_flow_and_receipts`
(also in `database/all_migrations.sql`). After pulling run:
`C:\xampp\php\php.exe bin\migrate.php`

### `invoices`

| Column | Notes |
|---|---|
| `status` | `draft` / `unpaid` / `partial` / `paid` |
| `invoice_number` | NULL for drafts; `INV-<year>-<0001>` per company when created |
| `type` | `sales` for everything created now (`purchase` only on old uploads) |
| `party_name`, `party_address`, `party_phone` | the customer |
| `payment_terms`, `reference_no`, `issue_date`, `due_date`, `notes` | |
| `subtotal`, `sst_rate`, `sst_amount`, `amount` | `amount` = subtotal + SST (grand total); SST rate copied from the setting when saved |
| `amount_paid` | sum of its receipts |
| `order_id` | the order it was made from (one invoice per order) |
| `issued_at` | when it was created (left draft) |

### `invoice_items`

`product_id`, `sku`, `description`, `quantity`, `unit_price`, `amount`
(+ `uom`, `discount`, unused now). Name, SKU and price are **copied** from the
product, so later price changes never alter a sent invoice.

### `receipts`

One row per payment: `receipt_number` (`RCPT-<year>-<0001>`), `amount`,
`payment_date`, `method` (cash / bank_transfer / duitnow / card / cheque / other),
`reference`, `notes`. Never edited or deleted. `invoices.id` is `ON DELETE RESTRICT`.

### `document_counters`, `finance_settings`

Running numbers per company / kind / year (one atomic UPSERT), and the
company's SST setting (`sst_registered`, `sst_rate`).

All totals are calculated on the server (`InvoiceService::validate`): the
browser sends only `lines[<product id>] = quantity`.

## Where the code is

| Purpose | File |
|---|---|
| Routes | `src/routes.php` (section "financial") |
| Invoice controller | `src/Controllers/FinancialController.php` |
| Claims controller | `src/Controllers/ClaimController.php` |
| Models | `Invoice`, `InvoiceItem`, `Receipt`, `DocumentCounter`, `FinanceSetting`, `Claim` (`src/Models/`) |
| Validation + totals | `src/Services/InvoiceService.php`, `src/Services/ClaimService.php` |
| Invoice PDF | `src/Services/InvoicePdfService.php` (template owner) |
| Receipt PDF | `src/Services/InvoiceReceiptPdf.php` (tFPDF, same look as the orders' official receipt) |
| Company details for PDFs | `company()` in `src/Support/helpers.php` = the user's Company Profile, `src/company.php` fills gaps |
| Invoice list | `src/Views/financial/invoice.php` |
| Create popup | `src/Views/financial/_invoice_modal.php` |
| Payments popup | `src/Views/financial/_payments_modal.php` |
| Claims list / popup | `src/Views/financial/claims.php`, `_claim_modal.php` |
| JS | `public/assets/js/app.js`: 15 popups, 16 file preview, 17 submit confirm + intent buttons, 18 product picker + customer suggestions, 19 AI receipt reading |

## AI auto-fill (Claims only)

Choosing a receipt in the New claim popup reads it with AI and fills the form
(title, date, amount, currency, category). Fields the user already typed are
kept; "Read again" overwrites. Nothing is saved until the user submits.

- Browser (`app.js` section 19): PDF pages rendered to images with pdf.js
  (cdnjs, max 4 pages); photos shrunk to 1600px.
- Server: `POST ?page=financial&action=extract` -> `src/Services/DocumentExtractService.php`.
- Which AI: the model + key saved in AI Settings (Staff first, then Customer);
  otherwise `ANTHROPIC_API_KEY` (Claude Haiku 5.5) or `AGNES_API_KEY` from `.env`.
- Limit: 40 reads per hour per session.

## Planned next

- Optional: printable "claim voucher" for reimbursed claims.
- Optional (not decided): editable prices / custom items on an invoice.
