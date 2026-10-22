# Financial module (Invoice & Claims)

Read this before changing anything under Financial. It explains what exists,
where it lives, and how the **printable invoice template** should plug in.

## What exists

| Page | URL | What it does |
|---|---|---|
| Invoice | `?page=financial&action=invoice` | List (search, filter by type/status, sort, paginate) + **Upload invoice** popup |
| Claims | `?page=financial&action=claims` | List + **New claim** popup, approve / reject / reimburse |

The old *Upload Invoice* and *Settlement* pages were removed; their URLs redirect.

### Roles (Financial pages only)

A demo switch at the top of both pages ("Viewing as **User** / **Supervisor**"),
kept in the session: `finance_role()`, `is_supervisor()`, `require_supervisor()`
in `src/Support/helpers.php`.

| | User | Supervisor |
|---|---|---|
| View lists, open files | yes | yes |
| Upload invoice / submit claim | yes (confirm popup; cannot change it afterwards) | yes |
| Edit, delete, mark paid, approve / reject / reimburse | no (server returns 403) | yes |
| **Print invoice** | **yes** | **yes** |

## Data model (PostgreSQL)

Migrations: `database/migrations/005_create_invoices.sql`, `006_create_claims.sql`,
`016_add_invoice_details_and_items.sql` (all also in `database/all_migrations.sql`).
After pulling, run: `C:\xampp\php\php.exe bin\migrate.php`

### `invoices` (one row per invoice, owned by `user_id`)

| Column | Notes |
|---|---|
| `type` | `purchase` (a supplier billed us) or `sales` (we billed a customer) |
| `invoice_number` | unique per user, case-insensitive |
| `party_name`, `party_address`, `party_phone` | the supplier (purchase) or customer (sales) |
| `payment_terms` | e.g. "Net 45 days" |
| `reference_no` | PO / GRN number |
| `issue_date`, `due_date` | |
| `subtotal` | sum of item amounts; **NULL when the invoice has no items** (total only) |
| `discount_amount`, `sst_rate` (%), `sst_amount` | invoice-level |
| `amount` | **grand total** = subtotal - discount_amount + sst_amount |
| `currency` | MYR, SGD, USD, ... |
| `status` | `unpaid` / `partial` / `paid` |
| `notes` | |
| `file_path`, `file_name`, `file_mime`, `file_size` | the uploaded original (PDF / image), stored privately in `storage/uploads/invoices/<user>/` |

### `invoice_items` (the lines; `ON DELETE CASCADE` with the invoice)

`position`, `sku`, `description`, `uom`, `quantity`, `unit_price`, `discount`, `amount`
(`amount` = quantity x unit_price - discount). Ordered by `position`.

All totals are recalculated on the server (`InvoiceService::validate`); never
trust totals sent from the browser.

### Our own company (template header)

`company_profiles` currently has `company_name`, `company_logo_url`,
`company_description` only. **Address, SSM registration no., phone, email and
website are not stored yet** - add them there if the template needs them.

## Where the code is

| Purpose | File |
|---|---|
| Routes | `src/routes.php` (section "financial") |
| Invoice controller | `src/Controllers/FinancialController.php` |
| Claims controller | `src/Controllers/ClaimController.php` |
| Queries | `src/Models/Invoice.php`, `src/Models/InvoiceItem.php`, `src/Models/Claim.php` |
| Validation + totals | `src/Services/InvoiceService.php`, `src/Services/ClaimService.php` |
| Private file storage | `src/Services/PrivateFiles.php` |
| Invoice list | `src/Views/financial/invoice.php` |
| Upload popup | `src/Views/financial/_invoice_modal.php` + `_invoice_items.php` |
| Claims list / popup | `src/Views/financial/claims.php`, `_claim_modal.php` |
| JS (popups, items, money format) | `public/assets/js/app.js` sections 15-18 |

## Printing an invoice (to be built)

Decision: **browser print**, no PDF library. An HTML page styled with the
company template; it opens in a new tab and calls `window.print()`, and the
user chooses **Save as PDF**. Nothing is stored: the page is rebuilt from the
database every time, so it is always up to date.

Suggested wiring (both roles may print):

1. **Route** in `src/routes.php`:
   `$router->get('financial/print', [FinancialController::class, 'print'], $auth);`
2. **Controller method** `print()` in `FinancialController`:
   ```php
   $user    = require_login();
   $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));   // ownership check
   $items   = (new InvoiceItem())->forInvoice((int) $invoice['id']);
   // + the company profile for the header
   view('financial/print', ['invoice' => $invoice, 'items' => $items, /* company */]);
   ```
   Do **not** use `require_supervisor()` here - users can print too.
3. **View** `src/Views/financial/print.php`: a standalone page (do not use
   `layouts/header`), A4 `@media print` CSS, title "PURCHASE INVOICE" or
   "INVOICE" from `$invoice['type']`, items table, totals, amount in words.
   Escape every value with `e()`.
4. **Button**: in `src/Views/financial/invoice.php` there is a comment
   `PRINT BUTTON (template owner)` inside the actions cell - put the button
   there (before the Supervisor-only buttons).

Invoices saved without items (total only) have `subtotal = NULL` and no rows
in `invoice_items`; the template should still print (show the total only).

## Planned next (in progress elsewhere)

- **AI auto-fill**: when a file is chosen in the upload popup, AI reads it and
  fills the header and items (`window.App.setInvoiceItems(rows)` fills the
  table). Uses the AI provider/key from AI Settings.
- Optional: printable "claim voucher" for reimbursed claims.
