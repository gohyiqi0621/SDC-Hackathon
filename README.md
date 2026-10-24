# Project Name - SME Smart

> SME Smart is an AI-powered business automation platform that helps Malaysian SMEs streamline customer service, order processing, invoicing, appointment scheduling, and daily operations through personalized AI assistants.

---

## Team Information

| Field                 | Details                                                                |
| --------------------- | ---------------------------------------------------------------------- |
| **Team Name**         | Ctrl + Future                                                          |
| **Team Domain**       | Domain 2                                                               |
| **SDG Track (1–17)**  | SDG 8 — Decent Work and Economic Growth                                |
| **Team Leader**       | Teh Sze Yu Jocelyn                                                     |
| **University**        | Universiti Technologi Malaysia (UTM) / Universiti Putra Malaysia (UPM) |
| **Number of Members** | 5                                                                      |

## Links

| Resource          | Link                                          |
| ----------------- | --------------------------------------------- |
| GitHub Repository | https://github.com/gohyiqi0621/SDC-Hackathon/ |
| Replit Project    | https://replit.com/@gohyiqi1/SME-Smart        |
| Live Demo         | https://sme-smart.replit.app/                 |
| RunPod Endpoint   | https://api.runpod.ai/v2/your-endpoint-id     |
| Slide Deck        | https://canva.link/o7vqbu97ji960r8                   |
| Demo Video        | https://youtu.be/5k7ap7hJpZ8                |

---

## Overview

### Problem

Many Malaysian micro and small businesses rely on manual processes and disconnected applications to manage customer enquiries, orders, invoices, appointments, and daily operations. These repetitive tasks often lead to delayed responses, human errors, and inconsistent workflows. SMEs need an affordable, easy-to-learn automation solution that improves productivity while keeping employees in control.

### Solution

SME Smart is an AI-powered business automation platform that allows businesses from different industries to quickly configure personalized AI assistants. It automates customer enquiries, order processing, invoice generation, appointment scheduling, and business data management through connected workflows.

By combining AI automation with human verification, SME Smart reduces repetitive tasks, improves accuracy, and helps businesses operate more efficiently. Its reusable and customizable modules are designed for potential integration with the Orbyte ecosystem, supporting SME digital transformation and SDG 8.

### Key Features

- **Personalized AI Assistants** — Enables SMEs across different industries to quickly configure AI assistants based on their business needs.
- **AI Customer Service & Automation** — Handles customer enquiries, processes orders, generates invoices, and assists with appointment scheduling.
- **AI-Powered Business Management** — Automates product data imports, email management, and internal business information retrieval.
- **Human Verification & Control** — Allows staff to verify payments, manage approvals, and oversee important business operations.

### Tech Stack

| Layer         | Technology            |
| ------------- | --------------------- |
| Frontend      | HTML, CSS, JavaScript |
| Backend       | Python, PHP           |
| Database      | PostgreSQL            |
| AI / Compute  | RunPod (GPU)          |
| Hosting / Dev | Replit                |

---

## Project Structure

```
.
├── bin/                         # CLI tools and server launchers
│   ├── migrate.php              #   create / update the database (runs database/schema.sql)
│   ├── create-user.php          #   add a login from the command line
│   ├── generate-key.php         #   create APP_ENCRYPTION_KEY in .env
│   ├── telegram-webhook.php     #   point the Telegram bot at PUBLIC_APP_URL
│   ├── flow-log.php             #   read the AI / Telegram flow log
│   ├── check.php                #   environment check
│   ├── serve.php                #   PHP built-in server router (Replit dev / local)
│   └── start-production.sh      #   Nginx + PHP-FPM start script (Replit deployment)
├── database/
│   └── schema.sql               # the whole PostgreSQL database in one file (safe to re-run)
├── deployment/
│   ├── nginx.conf.template      # Nginx site for the Replit deployment
│   └── php-fpm.conf             # PHP-FPM pool settings
├── docs/
│   ├── AUTH.md                  # sign-in, sessions and profile
│   ├── FINANCIAL.md             # invoices, receipts, payments, claims, SST
│   └── FLOWISE.md               # AI chatflows for the Customer / Staff assistants
├── public/                      # web root - the only folder the web server serves
│   ├── index.php                # front controller (loads src/bootstrap.php + src/routes.php)
│   ├── router.php               # clean URLs for PHP's built-in server
│   ├── .htaccess                # clean URLs under Apache / XAMPP
│   ├── favicon.ico
│   ├── uploads/                 # company logos, payment QR codes, product images
│   └── assets/
│       ├── brand/               # SME Smart logo set and icons
│       ├── css/                 # app.css (shared design) + one file per page
│       └── js/                  # app.js (sidebar, AJAX forms, toasts) + one file per page
├── runpod/
│   ├── handler.py               # RunPod serverless handler
│   └── requirements.txt         # dependencies for the RunPod endpoint
├── src/                         # all application source code (PHP)
│   ├── bootstrap.php            # env, constants, autoloader, database sessions, security headers
│   ├── routes.php               # every URL -> controller + middleware
│   ├── company.php              # default company details (overridden by Company Profile)
│   ├── Controllers/             # Auth, Dashboard, Gmail, AiSettings, Chatbot, Order, Financial,
│   │                            #   Claim, Product, Category, Booking, Profile, Legal, Tool,
│   │                            #   TelegramWebhook, System, AI
│   ├── Middleware/              # Auth, Guest, Csrf
│   ├── Models/                  # User, CompanyProfile, Product, Category, Order, Invoice,
│   │                            #   InvoiceItem, Receipt, Claim, Booking, ChatMessage,
│   │                            #   assistant settings, Gmail / Calendar / Telegram connections
│   ├── Services/                # business logic: Auth, Order, OrderInvoice, Invoice, Claim,
│   │                            #   Product, Booking, Gmail, GoogleCalendar, Telegram, Flowise,
│   │                            #   AI (text, SOP, document extraction), PDFs (DocumentPdf,
│   │                            #   InvoicePdf, OfficialReceiptPdf), SetupStatus, Brevo mail
│   ├── Support/                 # Database, Router, View, HttpClient, Crypto, DbSessionHandler,
│   │                            #   FileBackup, FlowLog, ToolToken, helpers
│   ├── Prompts/                 # system prompts for the Customer and Staff assistants
│   ├── Lib/tfpdf/               # PDF library for invoices and receipts
│   ├── Views/
│   │   ├── layouts/             # head, header / footer, app + auth shells
│   │   ├── partials/            # sidebar, topbar, flashes, setup checklist
│   │   ├── auth/                # register, login, forgot, reset
│   │   ├── legal/               # public Privacy Policy + Terms of Service
│   │   ├── dashboard/           # Gmail inbox, AI summary and reply, task planner
│   │   ├── ai_settings/         # company profile, assistant setup, connections
│   │   ├── chatbot/             # Customer + Staff assistant: setup, connections, chats
│   │   ├── products/            # products & services, categories, import
│   │   ├── orders/              # customer orders from chat, payment review
│   │   ├── financial/           # invoices and claims
│   │   ├── bookings/            # appointments booked through the assistant
│   │   ├── profile/             # my profile, password
│   │   ├── emails/              # password-reset and test email bodies
│   │   ├── system/              # flow log, system settings
│   │   └── errors/              # error page
│   ├── main.py                  # Python entry point (hackathon template)
│   └── utils.py                 # Python helpers (hackathon template)
├── storage/                     # git-ignored runtime files
│   ├── logs/                    #   flow logs
│   ├── cache/                   #   short-lived cache
│   └── uploads/                 #   private uploads: bank slips, invoices, claim receipts
├── submission/                  # required submission documents (PDF)
│   ├── SDC2026_Ctrl+Future_Preliminary.pdf
│   ├── SDC2026_CtrlFuture_TechDeclaration.pdf
│   ├── SDC2026_CtrlFuture_Replit_POU.pdf
│   └── SDC2026_CtrlFuture_Runpod_POU.pdf
├── tests/
│   └── test_utils.py            # unit tests
├── .agents/
│   └── memory/                  # Replit Agent notes (e.g. the PHP-FPM runtime setup on Replit)
├── .env.example                 # every setting the app reads, with placeholders
├── .gitignore
├── .htaccess                    # blocks src/, .env etc. from the web (XAMPP)
├── .replit                      # Replit run + deployment configuration
├── replit.md                    # notes for the Replit workspace
├── requirements.txt             # Python dependencies (RunPod handler, tests)
├── LICENSE
└── README.md
```

### Architecture

                 Customers / SME Staff
                           |
                           v
                 SME Smart Web Platform
                  (Frontend + Backend)
                           |
          +----------------+----------------+
          |                |                |
          v                v                v
      AI Services       Database       External APIs
       (Flowise)       PostgreSQL      Gmail / Calendar
          |                            Telegram / Claude
          |                            Agnes
          |
          +----> RunPod GPU
          |      (vLLM + Qwen2.5-7B-Instruct)
          |      System Prompt Generation Only
          |
          v
              Business Automation
           - Customer AI Chatbot
           - Order & Invoice Management
           - Appointment Scheduling
           - Internal AI Assistant
           - Email Processing
                           |
                           v
                 Human Verification
                     & Approval
                           |
                           v
                  Updated Business
                   Records & Status

## How to Run

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

# 3. Install dependencies
pip install -r requirements.txt

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

- **Development:** Replit was used to develop, integrate, and test the SME Smart web application.

- **Hosting:** SME Smart is hosted on Replit, allowing users to access the platform through a live web application.

- **Integration:** Replit connects the frontend, backend, database, AI services, and external APIs.

- **Environment Variables:** API credentials and sensitive configuration values are managed through environment variables.

📄 Proof of use: [`submission/SDC2026_CtrlFuture_Replit_POU.pdf`](submission/SDC2026_CtrlFuture_Replit_POU.pdf)

## How We Use RunPod _(Required)_

Describe how your team used RunPod. For example:

- **Workload:** Generate customized SOP prompts based on each company's business requirements.

- **AI Model:** Qwen2.5-7B-Instruct, deployed using vLLM on RunPod GPU.

- **Integration:**The generated prompts are integrated into Flowise to configure personalized AI assistants.

- **Purpose:** Simplify AI setup and automate business workflows across different industries.

Example request:

```bash
curl -X POST https://api.runpod.ai/v2/$RUNPOD_ENDPOINT_ID/runsync \
  -H "Authorization: Bearer $RUNPOD_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"input": {"prompt": "Hello"}}'
```

📄 Proof of use: [`submission/SDC2026_CtrlFuture_Runpod_POU.pdf`](submission/SDC2026_CtrlFuture_Runpod_POU.pdf)

---

## Challenges & Learnings

- **AI Behavior Customization:** Refining customized SOP prompts to ensure consistent and accurate AI responses across different business scenarios.
- **API Authentication:** Managing OAuth authentication and permissions for Gmail and Google Calendar integrations.
- **Time Constraints:** Developing and testing multiple interconnected features within a limited hackathon timeframe.
- Throughout this hackathon, our team learned the importance of teamwork, effective communication, and task delegation based on each member's strengths. We stepped beyond our existing knowledge and comfort zones to explore new technologies, develop innovative and creative solutions, and overcome technical challenges. This experience strengthened our problem-solving skills, adaptability, and ability to work together towards a common goal.

## Future Improvements

- [ ] Expand AI integrations to more customer communication platforms.
- [ ] Introduce advanced business analytics and reporting.
- [ ] Enhance system scalability and security.
- [ ] Expand Team Workspace with employee attendance tracking, clock-in/out, leave applications, and leave balance management.

## Team

| Name                         | Role             | GitHub                                           |
| ---------------------------- | ---------------- | ------------------------------------------------ |
| Teh Sze Yu Jocelyn           | Team Lead / AI   | [@Jocelynsuppp](https://github.com/Jocelynsuppp) |
| Wong Xin Yu                  | AI/Backend       | [@Xinyuuuu0521](https://github.com/Xinyuuuu0521) |
| Goh Yi Qi                    | Backend / RunPod | [@gohyiqi0621](https://github.com/gohyiqi0621/)  |
| Muhammad Naqib Bin Md Salleh | Frontend         | [@Naqib57](https://github.com/Naqib57/)          |
| Aliff Najmi Bin Nor Zamree   | AI / Backend     | [@aliffjammy](https://github.com/aliffjammy)     |

---

## Submission Checklist

- [/] Team name, domain (Domain 1 / Domain 2) and SDG track filled in
- [/] Slide deck link added
- [/] Demo video link added
- [/] Replit used, with proof of use in `submission/`
- [/] RunPod used, with proof of use in `submission/`
- [/] Submission template completed in `submission/`
- [/] "How to Run" steps verified
- [/] No API keys or secrets committed to the repository

## License

This project is licensed under the [Apache License 2.0](LICENSE).

---

## User Guide

A complete walk-through of SME Smart, from signing in to a customer receiving their official receipt.

### Demo account

| Field    | Value                                                |
| -------- | ---------------------------------------------------- |
| Live app | [sme-smart.replit.app](https://sme-smart.replit.app) |
| Email    | `ctrlfuture.orbyte@gmail.com`                        |
| Password | `orbyte1234`                                         |

> **What is ready in the demo account**
>
> - **Gmail** and **Google Calendar** are already connected.
> - The **AI model and key** are already filled in (you can switch to your own Anthropic key).
> - Everything else (company profile, instructions, payment method, products and the Telegram bot)
>   is **yours to set up** in Step 3, so you see the whole setup flow.
>
> **Why Gmail and Google Calendar are pre-connected:** our Google OAuth app is still waiting for Google's
> verification. Until it is approved, Google only allows approved test accounts to connect, so other
> Google accounts cannot be linked yet.

### The full flow at a glance

```
Sign in ─► Dashboard (Gmail + AI reply) ─► Set up Customer Assistant ─► Connect Telegram bot
   ─► Customer chats on Telegram (ask · book · buy) ─► Staff verify payment in Orders
   ─► Customer receives official receipt ─► Staff Assistant & Claims
```

---

### Step 1 · Sign in

1. Open [sme-smart.replit.app](https://sme-smart.replit.app).
2. Sign in with the demo account above. You land on the **Dashboard**.

---

### Step 2 · Dashboard: email with AI

| #   | Feature        | What to do                                                                                          |
| --- | -------------- | --------------------------------------------------------------------------------------------------- |
| 1   | Gmail inbox    | Already connected. Emails are listed and grouped (unread, important, meetings).                     |
| 2   | AI summary     | Click an email: the AI shows its key points beside it.                                              |
| 3   | AI reply       | The AI writes a draft under **AI Reply (Draft)**. Edit it (or **Regenerate**), then **Send Reply**. |
| 4   | Tasks & events | Add tasks and upcoming events in the **Team Workspace Planner**.                                    |

---

### Step 3 · Set up the Customer AI Assistant

Open **AI Assistant › Customer Assistant › Setup**. The checklist shows what is done and what is left;
each step has a button that takes you to the right page.

| #   | Setup step          | In the demo          | What it does                                                    |
| --- | ------------------- | -------------------- | --------------------------------------------------------------- |
| 1   | Company profile     | **To do**            | Business name, category and background. Both assistants use it. |
| 2   | AI model and key    | Done (prefilled)     | The model that writes the assistant's replies.                  |
| 3   | Instructions (SOPs) | **To do**            | How the assistant answers, sells and handles problems.          |
| 4   | Payment method      | **To do**            | Bank account and payment QR code sent to customers.             |
| 5   | Products & services | **To do**            | What the assistant can sell and answer questions about.         |
| 6   | Telegram bot        | **To do**            | Where customers chat with the assistant.                        |
| 7   | Google Calendar     | Done (pre-connected) | Bookings are checked against and added to this calendar.        |

**3.1 Company profile** (Company Profile in the sidebar)

1. Upload your company logo (optional), then fill in the company name, business category and background.
2. Fill in the address, registration no. (SSM), phone and email: they are printed on invoices and receipts.
3. Click **Save changes**.

**3.2 AI model and key** (Customer Assistant › Setup › AI Assistant Settings)

Already filled in. To use your own key, choose **Claude Haiku 4.5**, paste your Anthropic key and save.
Here you can also set the assistant's name, personality and reply language.

**3.3 Products & services** (Sales › Product & Service)

- Click **Add product / service** to add one item, or
- Click **Import products**, download the CSV template, fill it in and upload it to add many at once.

**3.4 Instructions (SOPs)** (Customer Assistant › Setup › System instructions)

1. Click **Generate instruction with AI**: it reads your company profile and products and writes
   three SOPs (for example customer service, ordering / booking, and sales).
2. Review and edit them, then click **Save changes**.
3. Later the button says **Regenerate instruction with AI** if you want a fresh set.

**3.5 Payment method** (Customer Assistant › Setup › Payment method)

1. Choose your bank, then enter the account number and account holder name.
2. Upload your **payment QR code** (DuitNow / bank QR). It is sent to customers with every order.
3. Click **Save**.

**3.6 Connect your Telegram bot** (Customer Assistant › Connection › Telegram bot)

1. Enter a **chatbot name**.
2. In Telegram, open **@BotFather** (the page has an **Open @BotFather** button), send `/newbot`, then send
   the name and a username ending in `bot` (both are shown on the page with **Copy** buttons).
3. BotFather replies with a **token**. Paste it into the page and click **Connect chatbot**.
4. Open your new bot in Telegram and press **Start**. The assistant now replies to customers there.

---

### Step 4 · Try it as a customer (on Telegram)

Chat with your bot as if you were a customer.

| Try                 | Example message                               | What happens                                                                                                    |
| ------------------- | --------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Ask a question      | "What food do you have?"                      | The assistant answers from your products and company profile.                                                   |
| Book an appointment | "Can I book a table on 30 Oct at 2 PM for 2?" | It checks free times in Google Calendar, confirms the details with you, then adds the booking.                  |
| Buy a product       | "I want 2 roti telur"                         | It confirms the items once, creates the **order** and an unpaid **invoice**, and sends the **payment QR code**. |
| Pay                 | Send a photo or PDF of the bank slip          | The slip is attached to the order and staff are told to verify it.                                              |
| Add on              | "One more teh tarik"                          | A new, separate order with its own payment (the earlier order is unchanged).                                    |

Every conversation also appears on the website in **AI Assistant › Customer Assistant › Chat**, with the
QR code, bank slips, invoices and receipts shown as files.

---

### Step 5 · Verify the payment (as staff)

1. Open **Sales › Order**. The **Verify Payment** card shows orders whose bank slip is waiting.
2. Click **Details** to see the items, customer and bank slip.
3. Click **Approve** (or **Reject** with a reason sent to the customer).
4. On approval the customer automatically receives, in Telegram:
   - a "Payment verified" message, and
   - the **official receipt (PDF)**.

   The invoice is marked **Paid** in **Sales › Invoice**.

5. When the order is sent out, click **Mark shipped**. The customer is told on Telegram.

---

### Step 6 · Staff AI Assistant

Open **AI Assistant › Staff Assistant › Setup**.

| #   | Setup step                         | In the demo                   | What it does                                                  |
| --- | ---------------------------------- | ----------------------------- | ------------------------------------------------------------- |
| 1   | Company profile                    | Done in Step 3.1 (shared)     | Both assistants use the same company profile.                 |
| 2   | AI model and key                   | Done (prefilled)              | The model the staff assistant uses.                           |
| 3   | Gmail                              | Done (pre-connected)          | Lets the staff assistant check, read and reply to email.      |
| 4   | Telegram staff alerts _(optional)_ | To do: link your own Telegram | Alerts for new bank slips to review, new orders and bookings. |

**Link Telegram staff alerts:** connect the customer Telegram bot first (Step 3), then in
**Staff Assistant › Connection › Telegram staff alerts** click the link button and press **Start** in Telegram.

Then open **Staff Assistant › Chat** and ask, for example:

- "Show my important emails" / "Reply to email #2"
- "Check the receipts waiting for review": shows the bank slip with **Approve** / **Reject** buttons
- "Which invoices are unpaid?" / "Show claims waiting for approval"

---

### Step 7 · Claims

1. Open **Claims** in the sidebar and click **New claim**.
2. Upload the receipt: AI reads it and fills in the details. Check them and **Submit claim**.
3. Switch **Viewing as** to **Supervisor** to approve or reject the claim, then click **Mark reimbursed** once it is paid back.
