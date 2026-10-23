# Project Name

> A one-sentence description of what your project does and who it is for.

---

## Team Information

| Field                 | Details                                          |
| --------------------- | ------------------------------------------------ |
| **Team Name**         | CTRL + Future                                    |
| **Team Domain**       | Domain 2                                         |
| **SDG Track (1–17)**  | e.g. SDG 11 — Sustainable Cities and Communities |
| **Team Leader**       | Teh Sze Yu Jocelyn                               |
| **University**        | Universiti Technologi Malaysia                   |
| **Number of Members** | 5                                                |

## Links

| Resource          | Link                                           |
| ----------------- | ---------------------------------------------- |
| GitHub Repository | https://github.com/gohyiqi0621/SDC-Hackathon   |
| Replit Project    | https://replit.com/@your-username/your-project |
| Live Demo         | https://your-project.replit.app                |
| RunPod Endpoint   | https://api.runpod.ai/v2/your-endpoint-id      |
| Slide Deck        | https://example.com/slides                     |
| Demo Video        | https://example.com/demo-video                 |

---

## Overview

### Problem

Describe the real-world problem you are solving in 2–4 sentences. Who is affected, and why does it matter?

### Solution

Explain how your project uses AI to solve the problem and what makes it valuable.

### Key Features

- **Feature 1** — Short description.
- **Feature 2** — Short description.
- **Feature 3** — Short description.

### Tech Stack

| Layer         | Technology                    |
| ------------- | ----------------------------- |
| Frontend      | HTML, CSS, JS                 |
| Backend       | PHP 8.2 (portal), Python (RunPod) |
| Database      | PostgreSQL                    |
| AI / Compute  | RunPod (GPU)                  |
| Hosting / Dev | XAMPP, Replit                 |

---

## Project Structure

```
.
├── bin/                         # CLI tools: migrate, create-user, generate-key, telegram-webhook,
│                                #   flow-log, check, plus the local / production server launchers
├── database/
│   ├── schema.sql               # base PostgreSQL schema (users + password resets)
│   ├── all_migrations.sql       # every migration in one file (paste into a fresh database)
│   └── migrations/              # numbered migrations 001_ ... 024_, run by bin/migrate.php
├── deployment/                  # Nginx + PHP-FPM configuration for the Replit deployment
├── docs/
│   ├── AUTH.md                  # sign-in, sessions and profile
│   ├── FINANCIAL.md             # invoices, receipts, payments, claims, SST
│   └── FLOWISE.md               # AI chatflows for the Customer / Staff assistants
├── public/                      # web root - the only folder the web server serves
│   ├── index.php                # front controller (loads src/bootstrap.php + src/routes.php)
│   ├── router.php               # clean URLs for PHP's built-in server (Replit)
│   ├── .htaccess                # clean URLs under Apache / XAMPP
│   ├── uploads/                 # logos, QR codes, product images (scripts blocked)
│   └── assets/
│       ├── brand/               # SME Smart logo set and favicons
│       ├── css/                 # app.css (shared design), dashboard, orders, ai-settings ...
│       └── js/                  # app.js (sidebar, AJAX forms, toasts), dashboard, orders,
│                                #   ai-settings, payment-method, product-import ...
├── runpod/
│   ├── handler.py               # RunPod serverless handler template
│   └── requirements.txt         # dependencies for the RunPod endpoint
├── src/                         # all application source code
│   ├── bootstrap.php            # env, constants, autoloader, database sessions, security headers
│   ├── routes.php               # every URL -> controller + middleware
│   ├── company.php              # default company details (overridden by Company Profile)
│   ├── Controllers/             # Auth, Dashboard, Gmail, AiSettings, Chatbot, Order, Financial,
│   │                            #   Claim, Product, Category, Booking, Profile, Legal, Tool,
│   │                            #   TelegramWebhook, System
│   ├── Middleware/              # Auth, Guest, Csrf
│   ├── Models/                  # User, CompanyProfile, Product, Category, Order, Invoice,
│   │                            #   InvoiceItem, Receipt, Claim, Booking, ChatMessage,
│   │                            #   assistant settings, Gmail / Calendar / Telegram connections
│   ├── Services/                # business logic: Auth, Order, OrderInvoice, Invoice + PDFs,
│   │                            #   Claim, Product, Booking, Gmail, GoogleCalendar, Telegram,
│   │                            #   Flowise, AI (text + document extraction), SetupStatus, Brevo mail
│   ├── Support/                 # Database, Router, View, HttpClient, Crypto, DbSessionHandler,
│   │                            #   FileBackup, FlowLog, helpers
│   ├── Prompts/                 # system prompts for the Customer and Staff assistants
│   ├── Lib/tfpdf/               # PDF library for invoices and receipts
│   ├── Views/
│   │   ├── layouts/             # head, header / footer, app + auth shells
│   │   ├── partials/            # sidebar, topbar, flashes, setup checklist
│   │   ├── auth/                # register, login, forgot, reset
│   │   ├── legal/               # public Privacy Policy + Terms of Service
│   │   ├── dashboard/           # Gmail inbox, AI summary, team task planner
│   │   ├── ai_settings/         # company profile, assistant setup, connections
│   │   ├── chatbot/             # Customer + Staff assistant: setup, connections, chats
│   │   ├── products/            # products & services, categories, import
│   │   ├── orders/              # customer orders from chat, payment review
│   │   ├── financial/           # invoices (create, payments, receipts) and claims
│   │   ├── bookings/            # appointments booked through the assistant
│   │   ├── profile/             # my profile, password
│   │   ├── email/ emails/       # email pages + password-reset email bodies
│   │   ├── system/              # flow log, system settings
│   │   └── errors/
│   ├── main.py                  # Python entry point (hackathon template)
│   └── utils.py                 # Python helpers (hackathon template)
├── storage/                     # logs, cache, private uploads (git-ignored content)
├── submission/                  # required submission documents (PDF)
├── tests/
│   └── test_utils.py            # unit tests
├── .env.example                 # every setting the app reads, with safe defaults
├── .htaccess                    # blocks src/, .env etc. from the web
├── .replit                      # Replit run + deployment configuration
├── LICENSE
└── README.md
```

> Older files from the first prototype are still in the repository (`app/`, root `config.php`,
> `db.php`, `helpers.php`, `setup.php`, `database.sql`, `migrations/`, `ai-service/`). The running
> app does not use them; everything current is in `src/` and `public/`.

### Architecture

```
User ──► Replit (App / API) ──► RunPod Endpoint (GPU model)
                │
                └──► Response returned to user
```

---

## Authentication & Profile Portal

The project now includes a **user-only** web portal (PHP 8.2 + PostgreSQL).
It has registration, login, logout, forgot password, reset password and profile management.
There is **no admin portal and no admin authentication** anywhere in it.

### What is included

- Sign in redirects straight to the dashboard; visiting the login page while
  signed in redirects to the dashboard too.
- Passwords are hashed with `password_hash`; every query is a prepared
  statement against PostgreSQL.
- Sessions use HTTP-only, `SameSite=Lax` cookies with ID regeneration and
  idle/absolute timeouts.
- Every POST is protected by CSRF; logout is POST-only.
- Password reset uses a 32-byte random token, stored as a SHA-256 hash,
  expiring after 60 minutes and consumed exactly once inside a transaction.
- The profile page lets the signed-in user update their name and password.
- Anyone can create an account at `/auth/register`; after registering they are
  signed in and sent to the dashboard.
- The **dashboard is the Smart SME AI email dashboard** (inbox, AI summary,
  AI reply draft, smart notifications), and every page — sign in, Financial,
  AI Chatbot, Profile — uses the same design.
- Brevo sends password-reset email (bodies in `src/Views/emails/`). The
  dashboard shows whether Brevo is connected and can send a test email.
- Sidebar: **Dashboard · Financial (Invoice, Claims, Settlement, Upload
  Invoice) · AI Chatbot (Customer, Staff) · Profile · Logout**.

Full details: [`docs/AUTH.md`](docs/AUTH.md).

## Dashboard email: Gmail, AI summary and follow-ups

The dashboard is the Smart SME AI email dashboard:

- **Gmail inbox (read-only).** Click **Connect Gmail** on the dashboard and
  sign in with Google. The latest inbox and spam emails then load into the
  inbox, with categories (Finance, Meeting, Orders, …) picked from keywords.
  **Sync** reloads, **Disconnect** forgets and revokes the access. Without a
  connection the dashboard shows demo emails.
- **AI summary and reply draft** for the selected email (generated in the
  browser for now).
- **Suggested follow-up** with **Approve** (creates a follow-up task) and
  **Dismiss**, plus a **History** popup listing created tasks and the
  activity log. These are kept in the browser, separately per user.

Gmail is implemented with plain HTTPS calls (`src/Services/GmailService.php`),
so no Composer package is needed. Tokens are stored per user in the
`gmail_connections` table (migration `003`).

### Set up Gmail

1. In `.env`, set `GMAIL_CLIENT_ID` and `GMAIL_CLIENT_SECRET` from Google
   Cloud Console → APIs & Services → Credentials → your OAuth client (Web).
2. In the same OAuth client, add this to **Authorized redirect URIs**:
   `http://localhost/SDC-Hackathon/public/gmail/callback`
   (it must match `GMAIL_REDIRECT_URI` in `.env` exactly).
3. Make sure the **Gmail API** is enabled for the project, and while the
   app is in "Testing", add your Google account under **Test users**.
4. Run `C:\xampp\php\php.exe bin\migrate.php` to create `gmail_connections`.

## AI replies: Telegram → Website → Flowise

Customer messages to a business's Telegram bot are answered by its AI
assistant: the website loads the company profile and AI settings from
PostgreSQL, fills `src/Prompts/system_message.md`, asks Flowise and sends the
reply back to the chat. The Customer **Chat Interface** page runs the same
pipeline for testing. Setup, the exact Flowise request and the custom tool
code are in [`docs/FLOWISE.md`](docs/FLOWISE.md).

New `.env` settings: `PUBLIC_APP_URL`, `FLOWISE_API_URL`, `FLOWISE_CHATFLOW_ID`,
`FLOWISE_API_KEY` (see `.env.example`). New table migration: `007`.

## Product & Service (AI Chatbot → Customer)

Each signed-in user manages their own products and services at
`/products` (categories at `/categories`):

- List with image, name, SKU, category, price, promo price and status;
  search by name/SKU, filter by category and status, sort by name, price or
  date, 10 per page.
- Add / edit with name, SKU, category, description, free-text
  characteristics, image (JPG/PNG/WebP/GIF, 2 MB), price, promotional price,
  currency (default MYR) and Active/Inactive status.
- Remove with a confirmation dialog. Categories can be added, edited and
  deleted, but a category that still has products cannot be deleted.
- Every query is scoped to the signed-in user; the database also enforces
  that a product's category belongs to the same user.

Sidebar: **AI Chatbot → Customer** (Setting & Integration, Product & Service,
Chat Interface) and **Staff** (Setting & Integration, Chat Interface).

### Apply the database migrations

```bat
cd C:\xampp\htdocs\SDC-Hackathon
C:\xampp\php\php.exe bin\migrate.php
```

or with `psql` (adjust the PostgreSQL version folder):

```bat
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -h 127.0.0.1 -U yiqi -d sme_assistant -f database\migrations\001_create_product_categories.sql
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -h 127.0.0.1 -U yiqi -d sme_assistant -f database\migrations\002_create_products.sql
```

Both files are safe to run again and never touch the `users` table.

### Local setup (XAMPP + PostgreSQL)

1. Start **Apache** and **PostgreSQL** in the XAMPP Control Panel.
2. Copy `.env.example` to `.env` and set the `DB_*` values (the included `.env`
   already points at `sme_assistant` on `127.0.0.1:5432`).
3. Create the tables (idempotent — it never drops data):

   ```bash
   C:\xampp\php\php.exe bin\migrate.php
   ```

   Or import the schema with psql:

   ```bash
   psql -U yiqi -h 127.0.0.1 -d sme_assistant -f database/schema.sql
   ```

4. Create an account (there is no public registration):

   ```bash
   C:\xampp\php\php.exe bin\create-user.php "Your Name" you@example.com
   ```

   The generated password is printed once.

5. Open <http://localhost/SDC-Hackathon/public/>.

6. For password-reset email, add the Brevo credentials to `.env`:

   ```dotenv
   BREVO_API_KEY=xkeysib-...
   BREVO_SENDER_EMAIL=verified-sender@example.com
   BREVO_SENDER_NAME="Smart SME"
   ```

   `BREVO_SENDER_EMAIL` must be a verified sender in Brevo. The dashboard
   shows the connection status and can send a test message.

### Health checks

```bash
C:\xampp\php\php.exe bin\check.php     # database connection + tables
C:\xampp\php\php.exe -l src\Controllers\AuthController.php
```

---

## Run the Python / RunPod Scaffold

The original Python entry point (`src/main.py`), `src/utils.py`, tests and the
RunPod handler are preserved.

### Option 1 — Run on Replit (Recommended)

1. Open the [Replit Project](https://replit.com/@your-username/your-project), or import this repository via **Create Repl → Import from GitHub**.
2. Open the **Secrets** tab (🔒) and add the variables listed in `.env.example`:
   - `RUNPOD_API_KEY`
   - `RUNPOD_ENDPOINT_ID`
3. Click **Run**. Replit uses the `.replit` file to start `python src/main.py`.

### Option 2 — Run Locally

```bash
# 1. Clone the repository
git clone https://github.com/your-username/your-project.git
cd your-project

# 2. Create and activate a virtual environment
python -m venv venv
source venv/bin/activate        # Windows: venv\Scripts\activate

# 3. Install the RunPod handler dependency only if you are setting up its separate endpoint
pip install -r runpod/requirements.txt

# 4. Configure environment variables
cp .env.example .env            # then fill in your values

# 5. Run the app
python src/main.py
```

### Run Tests

```bash
python -m unittest discover tests
```

---

## How We Use Replit _(Required)_

Describe how your team used Replit. For example:

- **Development:** Collaborative coding in a shared Repl using Multiplayer.
- **Hosting:** The app is deployed with Replit Deployments at the Live Demo link above.
- **Secrets:** API keys are stored in Replit Secrets, never in the code.

📄 Proof of use: [`submission/REPLIT PROOF OF USE.pdf`](submission/REPLIT%20PROOF%20OF%20USE.pdf)

## How We Use RunPod _(Required)_

Describe how your team used RunPod. For example:

- **Workload:** Model inference (e.g. text generation, image processing) runs on a RunPod GPU.
- **Setup:** `runpod/handler.py` and `runpod/requirements.txt` are deployed as a **RunPod Serverless Endpoint**. Its Python dependencies are kept separate from the PHP portal so the Replit portal build does not try to install them.
- **Integration:** The Replit app sends requests to the endpoint using `RUNPOD_API_KEY` and `RUNPOD_ENDPOINT_ID`.

Example request:

```bash
curl -X POST https://api.runpod.ai/v2/$RUNPOD_ENDPOINT_ID/runsync \
  -H "Authorization: Bearer $RUNPOD_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"input": {"prompt": "Hello"}}'
```

📄 Proof of use: [`submission/RUNPOD PROOF OF USE.pdf`](submission/RUNPOD%20PROOF%20OF%20USE.pdf)

---

## Challenges & Learnings

- What challenges did you face, and how did you overcome them?
- What did your team learn?

## Future Improvements

- [ ] Improvement 1
- [ ] Improvement 2

## Team

| Name        | Role                | GitHub                                   |
| ----------- | ------------------- | ---------------------------------------- |
| Member Name | Team Lead / Backend | [@username](https://github.com/username) |
| Member Name | Frontend            | [@username](https://github.com/username) |
| Member Name | AI / RunPod         | [@username](https://github.com/username) |

---

## Submission Checklist

- [ ] Team name, domain (Domain 1 / Domain 2) and SDG track filled in
- [ ] Slide deck link added
- [ ] Demo video link added
- [ ] Replit used, with proof of use in `submission/`
- [ ] RunPod used, with proof of use in `submission/`
- [ ] Submission template completed in `submission/`
- [ ] "How to Run" steps verified
- [ ] No API keys or secrets committed to the repository

## License

This project is licensed under the [Apache License 2.0](LICENSE).
