# Running on Replit

This imported project keeps its original PHP 8.2 / PostgreSQL structure.
The active portal starts at `public/index.php`; the legacy Python sample is
not the web application's entry point. No Composer packages are required.

## Start

Click **Run** to start the **Start application** workflow, or run:

```sh
php -S 0.0.0.0:5000 -t public bin/serve.php
```

Only `public/` is served. `bin/serve.php` provides the clean-URL rewrites
normally supplied by Apache's `public/.htaccess`. Register your own account
on the sign-up page; no default account or password is installed.

## Database

The development database has been initialized using the existing schema and
numbered migrations. The application uses Replit's managed `PGHOST`, `PGPORT`,
`PGDATABASE`, `PGUSER`, and `PGPASSWORD` variables automatically. Explicit
`DB_*` settings still take precedence for existing non-Replit installations.
Do not copy credentials into source files or commit a `.env`.

Health check:

```sh
php bin/check.php
```

To initialize a fresh development database or apply later development changes:

```sh
php bin/migrate.php
```

Migrations are a separate development operation, not part of server startup.
For Replit-managed production databases, use Publish's schema synchronization.

## Preview and optional services

Development has `APP_ENV=development` so the portal can be embedded in Replit's
preview. Other environments retain the existing anti-framing protection.
Leave `APP_URL` unset to derive URLs from the incoming request, including
the forwarded HTTPS scheme.

Registration and local portal pages do not require external integrations.
Gmail and Google Calendar need Google OAuth configuration; password-reset email
needs Brevo; AI, Telegram, and Flowise features need their respective services
and credentials. These services are not configured by the import setup.
The existing dashboard may show demo email content until Gmail is connected.
Use Replit Secrets for credentials rather than adding them to this file.

The local preview uses PHP's built-in server. Published builds use Nginx and
PHP-FPM through `bin/start-production.sh`; the Nginx configuration serves
`public/` only and retains the app's clean-URL routing.

The Python AI service and RunPod handler are separate optional components.
They are not started by the portal workflow.
The RunPod handler's Python dependency list lives at `runpod/requirements.txt`
and should be installed in the separate RunPod endpoint environment, not in
the PHP portal's Replit publish build.
