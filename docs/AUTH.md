# Authentication & Profile Portal

This document describes the user-only web portal added to the SDC-Hackathon
project. It is implemented in PHP 8.2 under `src/`, uses PostgreSQL, and its
home screen is the Smart SME AI email dashboard, whose design the whole portal follows.

## What this portal does

- **Sign in** with email + password, then land directly on the dashboard.
- **Already signed in?** `/auth/login`, `/auth/forgot` and `/auth/reset` redirect
  to the dashboard.
- **Forgot password** sends a one-time reset link through Brevo.
- **Reset password** changes the password with an expiring, single-use token.
- **Profile** lets the signed-in user update their display name and password.
- **Logout** is POST-only and protected by CSRF.
- **No admin portal and no admin authentication.** The `users` table has no role
  column, and there is no administrator controller, route or navigation entry.
- **Financial** and **AI Chatbot** navigation sections are wired in, with the
  authenticated shell and empty states ready for the feature work.

## Routes

| Method | URL (query form) | Page | Guard |
| --- | --- | --- | --- |
| GET | `?page=auth&action=login` | Sign in | guest |
| POST | `?page=auth&action=login` | Verify and redirect to dashboard | guest + CSRF |
| GET | `?page=auth&action=forgot` | Forgot password | guest |
| POST | `?page=auth&action=forgot` | Request a reset link | guest + CSRF |
| GET | `?page=auth&action=reset&token=…` | Reset form | guest |
| POST | `?page=auth&action=reset` | Set the new password | guest + CSRF |
| POST | `?page=auth&action=logout` | Sign out | auth + CSRF |
| GET | `?page=dashboard` | Dashboard | auth |
| GET | `?page=profile` | Profile | auth |
| POST | `?page=profile&action=update` | Save display name | auth + CSRF |
| POST | `?page=profile&action=password` | Change password | auth + CSRF |
| GET | `?page=financial&action=invoice` | Invoice | auth |
| GET | `?page=financial&action=claims` | Claims | auth |
| GET | `?page=financial&action=settlement` | Settlement | auth |
| GET | `?page=financial&action=upload` | Upload Invoice | auth |
| GET | `?page=chatbot&action=customer` | Customer chatbot | auth |
| GET | `?page=chatbot&action=staff` | Staff chatbot | auth |
| GET | `?page=email` | Redirects to the email dashboard | — |

`public/.htaccess` also maps clean paths (`/financial/invoice`, `/profile`, …)
onto the same parameters. Both URL styles work.

## Directory layout

```
src/
├── bootstrap.php              # env, autoloader, constants, session, headers
├── routes.php                 # URL table
├── Controllers/
│   ├── AuthController.php
│   ├── ProfileController.php
│   ├── DashboardController.php
│   ├── FinancialController.php
│   ├── ChatbotController.php
│   ├── ProductController.php
│   └── CategoryController.php
├── Middleware/
│   ├── Middleware.php
│   ├── AuthMiddleware.php
│   ├── GuestMiddleware.php
│   └── CsrfMiddleware.php
├── Models/
│   ├── User.php
│   ├── PasswordReset.php
│   ├── Category.php
│   └── Product.php
├── Services/
│   ├── AuthService.php
│   ├── PasswordResetService.php
│   ├── BrevoMailer.php
│   └── ProductService.php
├── Support/
│   ├── Database.php
│   ├── Router.php
│   ├── View.php
│   └── helpers.php
└── Views/
    ├── auth/                  # register, login, forgot, reset
    ├── dashboard/             # Smart SME AI email dashboard (home)
    ├── financial/             # invoice, claims, settlement, upload
    ├── chatbot/               # settings + chat (Customer and Staff)
    ├── products/              # product list, form, categories
    ├── profile/
    ├── emails/                # password-reset + test email bodies
    ├── layouts/               # head, app + auth shells
    ├── partials/              # sidebar, topbar, flashes
    └── errors/
public/
├── index.php                  # front controller
├── .htaccess                  # clean URLs
└── assets/                    # css/app.css, css/dashboard.css, js/app.js, js/dashboard.js, logo
database/
├── schema.sql                 # PostgreSQL: users + password_resets
└── migrations/                # 001 product_categories, 002 products
bin/
├── migrate.php
├── create-user.php
└── check.php
```

## Database

PostgreSQL only. The connection is built in `src/Support/Database.php` as a
`pgsql:` DSN from the same `DB_*` variables used elsewhere in the project.

```dotenv
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=sme_assistant
DB_USER=yiqi
DB_PASS=...
DB_SSLMODE=prefer
```

Import the schema (idempotent; it never drops data):

```bash
php bin/migrate.php
# or
psql -U yiqi -h 127.0.0.1 -d sme_assistant -f database/schema.sql
```

Tables:

- `users` — `id, name, email, password_hash, status, last_login_at, created_at,
  updated_at`. No role column.
- `password_resets` — `id, user_id, email, token_hash, expires_at, used_at,
  requested_ip, created_at`.

## Creating accounts

Users can sign up at `/auth/register` (name, email, password + confirmation).
A new account is signed in immediately and sent to the dashboard. There is no
admin panel. Accounts can also be created from the CLI:

```bash
php bin/create-user.php "Full Name" user@example.com
```

It generates a random password and prints it once. Pass a third argument to
choose the password yourself.

## Password reset lifecycle

1. `POST ?page=auth&action=forgot` looks up the address.
2. For a known, active account it generates a 32-byte random token, stores only
   its SHA-256 hash, and emails
   `?page=auth&action=reset&token=<token>` through Brevo.
3. The row expires after **60 minutes**; older unused links for that account are
   retired when a new one is issued.
4. At most **3 requests per 15 minutes** are accepted per account. The public
   response is the same whether the address exists or not.
5. On submit, the token row is selected `FOR UPDATE` inside a transaction. It is
   rejected if used or expired, the password hash is updated, the token is
   marked used, and every sibling token is deleted. The same token cannot be
   redeemed twice, even under concurrent requests.
6. After success the user is sent to the sign-in page — a reset proves inbox
   access, not knowledge of the new password, so they are not auto-signed-in.

### Brevo configuration

Set these in `.env`:

```dotenv
BREVO_API_KEY=xkeysib-...
BREVO_SENDER_EMAIL=verified-sender@example.com
BREVO_SENDER_NAME="Smart SME"
```

`BREVO_SENDER_EMAIL` must be a verified sender in Brevo. The dashboard shows the connection status and offers a **Send test
email** button; password-reset mail uses the same `BrevoMailer` service and the
templates in `src/Views/emails/`. Delivery results are logged to
`storage/logs/mail.log` (never the API key).

On Windows, PHP has no CA store; `MAIL_CA_BUNDLE` defaults to XAMPP's
`curl-ca-bundle.crt`.

## Security notes

| Requirement | Implementation |
| --- | --- |
| Password hashing | `password_hash(PASSWORD_DEFAULT)` / `password_verify`; transparent re-hash on login when PHP's default changes. |
| Prepared statements | Every query in `Models/` uses PDO prepared statements with `ATTR_EMULATE_PREPARES => false`. |
| Secure sessions | HTTP-only cookie, `SameSite=Lax`, strict mode, `Secure` when HTTPS, ID regenerated on sign-in/out and password change, 30-minute idle timeout, 8-hour absolute lifetime. |
| CSRF | Session token, hidden field + `X-CSRF-Token` header, enforced centrally by `CsrfMiddleware` for every POST. |
| Reset tokens | 32 random bytes; stored as SHA-256; 60-minute expiry; transactionally single-use; 3 requests per 15-minute window; anti-enumeration responses. |
| Login throttling | 5 failed attempts per session start a 15-minute cooldown. |
| Error handling | No database access while rendering an error page; a re-entrant abort falls back to a static page. |
| No admin | No role column, no admin controller/route, no admin navigation. |

## Preserved parts of the project

- `src/main.py`, `src/utils.py`, `tests/test_utils.py`, `runpod/handler.py`,
  `requirements.txt` and `.replit` were left untouched.
- The email dashboard UI and mock data from the old top-level `email/` folder
  are now the main dashboard (`src/Views/dashboard/index.php`), with its styles
  and script in `public/assets/css/dashboard.css` and
  `public/assets/js/dashboard.js`. Its design (`public/assets/css/app.css`) is
  used across the whole portal.
- The logo and favicon are reused from the existing project.
