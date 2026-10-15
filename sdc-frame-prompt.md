# Prompt: Build the Generic System Frame (Dashboard Shell) for SDC Hackathon

> Copy everything below the line into your AI assistant. Edit the `[PLACEHOLDERS]` first.
> Goal: a reusable, generic frame we can rename and fill with features once the problem statement is revealed.

---

## 1. Role

You are a senior full-stack developer helping me build a **generic web system frame** for a 7-day student hackathon (SDC Hackathon 2026, AI for UN SDGs). The problem statement is **not revealed yet**, so the frame must be **generic and easy to adapt**, with no domain-specific features.

## 2. Tech Stack (strict)

- **Frontend:** HTML, CSS, vanilla JavaScript (no React/Vue, no build tools)
- **Backend:** plain PHP 8+ (no Laravel, no Composer required) using **lite MVC**
- **Database:** MySQL via **PDO with prepared statements**
- **AI service:** Python (Flask) running separately on `http://localhost:5000`, called from PHP with cURL
- **Environment:** XAMPP on Windows (Apache + MySQL), project inside `htdocs`
- **Secrets:** stored in `.env` (git-ignored). Provide `.env.example`. Never hardcode keys.

## 3. Folder Structure (use exactly this)

```
sdc-project/
├── public/
│   ├── index.php            # Entry point + router
│   ├── .htaccess
│   ├── css/
│   │   ├── base.css         # variables, reset, typography
│   │   ├── layout.css       # sidebar, topbar, grid
│   │   └── components.css   # cards, tables, buttons, forms, modals, toasts
│   └── js/
│       ├── main.js          # sidebar toggle, theme, init
│       ├── api.js           # ALL fetch() calls live here
│       └── ui.js            # toast, modal, table helpers, loading states
│
├── app/
│   ├── controllers/         # AuthController, DashboardController, ItemController, AiController, ProfileController
│   ├── models/              # User.php, Item.php, AiLog.php
│   ├── views/
│   │   ├── layouts/         # header.php, sidebar.php, topbar.php, footer.php
│   │   ├── auth/            # login.php, register.php
│   │   ├── dashboard/       # index.php
│   │   ├── items/           # list.php, form.php
│   │   ├── ai/              # panel.php
│   │   └── profile/         # index.php
│   └── ai.php               # callAiService($endpoint, $payload) using cURL
│
├── ai-service/
│   ├── app.py               # Flask, /health and /predict (dummy logic)
│   └── requirements.txt
│
├── config.php               # loads .env, defines constants
├── db.php                   # PDO connection (singleton function)
├── helpers.php              # json_response(), redirect(), e() escape, csrf helpers, flash messages
├── database.sql
├── .env.example
├── .gitignore
└── README.md
```

## 4. Frame Features (build only these)

### 4.1 Authentication
- Register, login, logout
- Password hashing (`password_hash`, `password_verify`)
- Session-based auth; a route guard so dashboard pages require login
- Roles: `admin` and `user` (simple column on users table)
- CSRF token on all POST forms

### 4.2 Layout (the main "frame")
- **Left sidebar** with logo/app name `[APP_NAME]`, nav links, active-state highlight, collapsible on mobile
- **Top bar** with page title, search box (UI only), notifications icon (UI only), user dropdown (Profile, Logout)
- **Content area** with consistent padding and a breadcrumb
- **Footer** (small)
- Light/dark theme toggle saved in `localStorage`
- Fully responsive (desktop, tablet, mobile)

### 4.3 Pages
| Page | Purpose |
|---|---|
| **Dashboard** | 4 stat cards (placeholders pulled from DB counts), 1 line/bar chart (Chart.js via CDN), recent activity table, quick-action buttons |
| **Items (generic CRUD)** | List with search, filter, pagination, create/edit via modal or form, delete with confirmation. "Item" is a generic entity we rename later |
| **AI Panel** | Text input (and optional file upload) → sends to PHP → PHP calls Python → shows result card with loading state and error state. Saves each call to `ai_logs` |
| **Profile** | Edit name, change password |
| **Admin (admin only)** | User list with role change/disable |
| **404 / 403** | Simple error pages |

### 4.4 Reusable UI components (in CSS + JS)
Stat card, data table, pagination, modal, toast notification, form inputs with validation messages, loading spinner, empty state, badge/status pill, confirm dialog.

## 5. Backend Rules

1. **Router** in `public/index.php`: read `?page=...&action=...` (or clean URLs via `.htaccess`), map to `Controller::method`. Return 404 for unknown routes.
2. **Controllers stay thin.** No SQL inside controllers or views.
3. **Models** contain all PDO queries (prepared statements only).
4. **Views** are PHP templates with `e()` escaping on all output.
5. **JSON endpoints** (for fetch calls) always return:
   ```json
   { "success": true, "data": {}, "message": "" }
   ```
6. Validate all input server-side. Never trust JS validation.
7. `callAiService()` must have a timeout, handle connection errors, and return a clean error message if Python is down (so the UI never crashes).
8. No email feature. Skip anything related to sending email.

## 6. Database (generic, minimal)

Create `database.sql` with:
- `users` (id, name, email, password_hash, role, status, created_at)
- `items` (id, user_id, title, description, status, created_at, updated_at)  ← generic, rename later
- `ai_logs` (id, user_id, input_text, output_text, status, created_at)
- Insert one default admin user and 5 sample items for the demo

Use `snake_case` for columns. Include `utf8mb4`.

## 7. Python AI Service

- Flask app with CORS disabled (only PHP calls it)
- `GET /health` → `{ "status": "ok" }`
- `POST /predict` → accepts `{ "text": "..." }`, returns `{ "result": "...", "confidence": 0.0 }` using **dummy logic for now** (we swap in the real model later)
- Clear comment marking where the real AI code goes
- `requirements.txt` included

## 8. Design Direction

- Clean, modern, professional dashboard look (think admin template, not flashy)
- CSS variables for colors, spacing, and radius so the theme can be changed in one place
- Primary color: `[PRIMARY_COLOR e.g. #2E7D32 green for sustainability]`
- Font: system font stack or one Google Font
- Use inline SVG or a CDN icon set (no local image dependencies)
- Accessible: labels on inputs, good contrast, keyboard-friendly modals

## 9. Output Format (important)

Work in **this order**, and wait for me to say "next" between stages so I can test:

1. **Stage 1:** `config.php`, `db.php`, `helpers.php`, `.env.example`, `.gitignore`, `database.sql`, `public/index.php` (router), `.htaccess`
2. **Stage 2:** Auth (controller, model, views) + layout views (header, sidebar, topbar, footer) + base CSS
3. **Stage 3:** Dashboard page + Chart.js + reusable components (`components.css`, `ui.js`)
4. **Stage 4:** Items CRUD with `api.js`
5. **Stage 5:** Python `ai-service` + `app/ai.php` + AI Panel page
6. **Stage 6:** Profile, Admin page, error pages, `README.md` (XAMPP setup steps, how to run Flask, how to copy `.env.example` to `.env`)

For each file: show the **full path as a heading**, then the **complete code**. No "rest of code here" shortcuts. Add short comments only where logic is not obvious.

## 10. Constraints

- Beginner-friendly code; no advanced patterns or over-engineering
- Everything must work on **XAMPP with default settings** (PHP cURL extension enabled, mod_rewrite on)
- Mention anything I need to enable or install
- Keep names generic (`Item`, `[APP_NAME]`) so I can rename after the problem statement is revealed
- At the end of each stage, give me a **short test checklist** (what to open, what should happen)

## 11. My Placeholders (fill before sending)

- `[APP_NAME]` = SDC App
- `[PRIMARY_COLOR]` = #2E7D32
- Project folder name = `sdc-project`
- Local URL = `http://localhost/sdc-project/public`

---

*Start with Stage 1 now.*
