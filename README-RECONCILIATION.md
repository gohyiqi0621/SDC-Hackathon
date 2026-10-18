# README reconciliation — local project vs upstream `SDC-Hackathon` README

Compared on 2026-10-15 against <https://github.com/gohyiqi0621/SDC-Hackathon>.

Upstream = the team README (the contract every teammate follows).
Local = `README.md` in this project.

---

## Summary

The local README documents the app well but was written **before** the central
workflow was agreed. The two describe **different project layouts and different
database-connection mechanisms**, so a teammate following the upstream README
cannot run this codebase, and vice versa. This is the single most important
discrepancy to resolve.

---

## A. Blocking discrepancies

### A1. Database connection mechanism — different, and incompatible

**Upstream README (§5) says the connection file reads Railway's variables with a
`getenv()` fallback:**

## real? 

```php
$host = getenv('MYSQLHOST')     ?: '127.0.0.1';
$port = getenv('MYSQLPORT')     ?: '3306';
$db   = getenv('MYSQLDATABASE') ?: 'YOUR_LOCAL_DB';
$user = getenv('MYSQLUSER')     ?: 'root';
$pass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';
```

**This project does not do that.** `config.php` reads a `.env` file through a
hand-rolled loader and defines constants; `db.php` builds the DSN from them:

```php
// config.php
define('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (string) env('DB_PORT', '3306'));
define('DB_NAME', (string) env('DB_NAME', 'sdc_app'));
define('DB_USER', (string) env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));

// db.php
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
```

Verified: `grep -nE "MYSQLHOST|getenv" config.php db.php` returns **nothing**.

**Why it matters:** on Railway the upstream pattern works with zero config (the
`MYSQL*` vars exist automatically). This project instead needs five `DB_*`
variables set on the Railway service, `DB_NAME` pointed at Railway's actual
database name, and the `.env` file present — deployed as a real file, not an
env-only setup.

**Options (pick one and make the README match):**
- **(a) Adapt the code** — have `config.php` prefer `getenv('MYSQLHOST')` etc.
  when present, falling back to `.env`. Small change, then the upstream README
  is accurate as-is. Recommended.
- **(b) Adapt the README** — document that this app uses `.env` + `DB_*` and
  list the five variables to set on Railway. No code change.

Either way, the README and the code must agree.

### A2. Migration folder layout — flat vs per-teammate

**Upstream (§4) mandates:**

```
database/
  migrations/
    jocelyn/migration_20261015_143000.sql
    yiqi/migration_20261016_091500.sql
  seed.sql
```

**This project has:**

```
database.sql          <- single source of truth: creates DB + every table
migrations/README.md  <- convention doc (no SQL files yet)
```

Differences: there is no `database/` wrapper, no per-teammate subfolders, and no
`seed.sql` (demo data lives inside `database.sql`). The two migration files that
previously sat here (`migration_2026_10_14.sql`,
`migration_2026_10_14_notifications.sql`) have been **folded into
`database.sql`** — every column, index and enum they added was already defined
there, so a fresh import never needed them.

I added `migrations/README.md` documenting the per-teammate convention for
*future* changes: `migrations/` now holds additions only, never a `CREATE TABLE`
copy of something already in `database.sql`.

### A3. Local database name

**Upstream (§1)** says create a database named `YOUR_LOCAL_DB` (a placeholder).
**This project** hardcodes `sdc_app` (`config.php` default and `database.sql`).

A teammate who creates `YOUR_LOCAL_DB` literally, or any other name, gets a
connection failure — and the migrations' `USE \`sdc_app\`` line will fail or
switch to the wrong database. The README should state `sdc_app` explicitly.

---

## B. Missing steps in the upstream README (needed for this project)

1. **The Python AI service is never mentioned.** `ai-service/app.py` (Flask,
   `127.0.0.1:5000`) must run for the AI panel to work. The local README covers
   it (§2.4); upstream does not. Without it the AI panel shows an offline banner.
2. **`.env` setup is never mentioned.** Upstream implies env vars only. This
   project **exits with "Missing .env file"** if `.env` is absent
   (`config.php`). The copy step (`cp .env.example .env`) must be documented.
3. **`APP_KEY` is never mentioned.** The admin Key panel encrypts secrets with
   AES-256-GCM; without a 32-byte base64 `APP_KEY`, saving a key is refused.
   Generate with `php -r "echo base64_encode(random_bytes(32));"`.
   Also: the **same** `APP_KEY` must be used locally and on Railway, or migrated
   encrypted secrets cannot be decrypted.
4. **Document root is `public/`, not the project root.** Upstream links to
   `http://localhost/REPO-NAME`. This app is served from
   `http://localhost/sdc-project/public`. Opening the project root would expose
   `config.php` and `.env`. Upstream only references `config/db.php`, which does
   not exist here.
5. **No `composer install` / `npm install`** — worth stating explicitly, since
   the upstream stack line does not say the project is build-tool-free.
6. **`RewriteBase` must match the folder name.** `public/.htaccess` hardcodes
   `/sdc-project/public/`. Cloning into a different folder name breaks every
   route until that line is updated.
7. **Port 3306 conflict.** Upstream says start MySQL in XAMPP and also connect
   to Railway. If local MySQL holds 3306, that is fine, but the README should
   note that local and Railway are separate and never auto-sync.

---

## C. Contradictions to fix in the upstream README

| # | Upstream says | Reality here |
|---|---|---|
| C1 | "Import the SQL files from `database/`" (§1) | No `database/` dir — it is `database.sql` + `migrations/` |
| C2 | Connection file is `config/db.php` (§5) | Files are `config.php` + `db.php` at the repo root |
| C3 | DB vars `MYSQL*` via `getenv` (§5, §6) | `DB_*` via `.env` |
| C4 | Migrations in `migrations/<name>/` (§4) | Flat `migrations/` |
| C5 | `seed.sql` for dummy data (§4) | Demo data is inside `database.sql` |
| C6 | Delete `CREATE DATABASE`/`USE` before Railway import (§5) | Correct and still required — confirm before importing |
| C7 | Open app at `http://localhost/REPO-NAME` (§1) | Served at `.../public` |
| C8 | AI API key in `settings.ai_api_key` (§1) | The Key panel is generic; OneSignal + Google keys are the real entries |

---

## D. Rules the local README should adopt

- `database.sql` is the single source of truth for the schema — it creates the
  database and every table. A fresh import needs nothing else.
- After the first import, ship each change as a new file in `migrations/`
  (additions only, never a `CREATE TABLE` copy of an existing table).
- Run migration files in timestamp order, oldest first.
- Record each applied file in `schema_migrations`.
- Never edit an applied migration — add a new one.
- Never change a table in phpMyAdmin only — write the SQL file too.
- **Do not use MariaDB-only syntax.** XAMPP runs MariaDB, Railway runs MySQL 8.
  `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS` are MariaDB-only and error
  on MySQL 8 — those clauses were removed from the migrations before they were
  folded into `database.sql`.
- Never commit a dump containing real user data or an API key.

---

## E. Verified this session

- `database.sql` imports cleanly and is re-runnable (tested against a scratch DB;
  all 10 tables created, `schema_migrations` pre-populated, re-import succeeded).
- All PHP files lint clean on PHP 8.3; all JS passes `node --check`.
- App smoke test after cleanup: login + dashboard/items/key/admin/notification/
  profile all return 200.
- `.env` is **not** staged; staged content scanned for key material — clean.
