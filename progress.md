# Google Safe Browsing — "Social engineering content" audit & remediation

**Status:** Audit complete, targeted fixes implemented, automated validation passed.
**Google review request: NOT submitted.** I cannot and do not claim the warning is cleared — that
requires a review by Google after the fixes are deployed. Live visual verification and the review
request are left to the developer.

**Date:** 2026-10-17
**Production URL:** `https://sdc-hackathon-production-5b7a.up.railway.app/`
**Flagged URLs:** `/` and `/?page=auth&action=login`

---

## 1. Immediate finding: production is not currently serving the app

Before anything else, the live host was checked. Every path returns Railway's fallback response:

```
GET https://sdc-hackathon-production-5b7a.up.railway.app/            -> 404
GET https://sdc-hackathon-production-5b7a.up.railway.app/?page=auth&action=login -> 404
GET https://sdc-hackathon-production-5b7a.up.railway.app/googlefaeecc3268cb03e3.html -> 404

x-railway-fallback: true
{"status":"error","code":404,"message":"Application not found","request_id":"..."}
```

`x-railway-fallback: true` + `Application not found` means the domain is not attached to a running
Railway service. **Consequences:**

- The content Google flagged is no longer being served, so the live site cannot be inspected to
  confirm the trigger directly — the audit below is of the source tree.
- Whatever Google indexed is stale. Any fix must be **deployed** before a review request, or the
  reviewer will see a 404 rather than the corrected page.

---

## 2. Method

Read-only audit of the repository, then targeted changes, then automated validation.

- Full source read of every visitor-facing surface: homepage/dashboard, login, register,
  set-password, OAuth start/callback, profile, error pages, the AI chat panel, and all shared
  layouts (`head.php`, `auth_header/footer.php`, `app_header/footer.php`, `header/footer.php`).
- Static search for obfuscated or unauthorised code, external endpoints, iframes, forced downloads,
  credential collection and cloaking.
- Git history review of the flagged views and of the demo-data commits.
- Database inspection for demo accounts and stored credentials (metadata only — no secret values
  were read or printed).
- HTTP + real-browser (headless Chrome) validation of every route, capturing status codes, redirect
  chains, console output and all network requests.

---

## 3. Audit results

### 3.1 Flows inspected

| Flow | Exists? | Finding |
|---|---|---|
| Homepage `/` | yes | 302 → `/?page=auth&action=login` for an anonymous visitor |
| Login | yes | email + password only; posts to its own endpoint |
| Registration | yes | name + email + password; new accounts get the `user` role |
| OTP / one-time code | **no** | no such flow exists anywhere in the codebase |
| Password recovery / reset | **no** | no such flow exists anywhere in the codebase |
| Google OAuth | yes | official authorization-code flow (see 3.4) |

The absence of OTP and password-recovery flows matters: those are the two flows most often abused
for credential theft, and neither is present.

### 3.2 Clean — no unauthorised or obfuscated code

- No `eval`, `new Function`, `atob`, `btoa`, `fromCharCode`, `unescape`, `document.write`,
  dynamically-injected `<script>`, `srcdoc`, `javascript:` URLs, or base64 payloads in `app/` or
  `public/`. (The only `createElement('script')` is the OneSignal SDK loader, which points at
  `cdn.onesignal.com` and only runs when an App ID is configured.)
- No `<iframe>` anywhere. Confirmed at runtime: 0 iframes on all flagged routes.
- No user-agent sniffing or cloaking — nothing reads `HTTP_USER_AGENT`, and no crawler/bot branching.
- The only `innerHTML` writes are the XSS-escaped markdown renderer (`chat.js`), the toast component
  (`ui.js`, which escapes via `escapeHtml`), and the notification list.
- **No forced downloads.** No `download` attribute, no `Content-Disposition`, no
  `application/octet-stream` responses.

### 3.3 External endpoints — the complete list

Every outbound destination in the whole application:

| Destination | Where | Notes |
|---|---|---|
| `accounts.google.com/o/oauth2/v2/auth` | `AuthController::google` | OAuth start (browser redirect) |
| `oauth2.googleapis.com/token` | `app/google.php` | code → token exchange (server-side) |
| `openidconnect.googleapis.com/v1/userinfo` | `app/google.php` | profile fetch (server-side) |
| `api.onesignal.com` | `app/onesignal.php` | push send / device list (server-side) |
| `cdn.onesignal.com/sdks/web/v16/...` | `public/js/onesignal.js` + 2 worker files | push SDK |
| `cdn.jsdelivr.net/npm/chart.js@4.4.1` | `app/views/dashboard/index.php` | dashboard chart, signed-in only |
| `apihub.agnes-ai.com/v1` | `ai-service/agnes.py` | AI replies, server-side only |

On the **auth pages specifically there are zero external scripts and zero off-origin requests** —
verified in a real browser (§5). No trackers, no analytics, no ad code, no pixel.

### 3.4 Google OAuth uses the official flow and never collects Google passwords

`AuthController::google()` redirects the browser to `https://accounts.google.com/o/oauth2/v2/auth`
with `response_type=code`, `scope=openid email profile`, a random `state` stored in the session, and
`prompt=select_account`. The password is typed **on Google's own domain**; the app only ever receives
an authorization code, which `google_exchange_code()` swaps for a token server-side. There is no
password field in the OAuth path, and no code path asks for a Google password.

### 3.5 Demo Login — synthetic data, standard permissions, now deleted

- The demo credentials were `admin@sdc.local / admin123` and `user@sdc.local / user123`.
- **Synthetic:** `.local` is a reserved TLD that cannot resolve publicly; the addresses were
  hard-coded placeholders, not real people, and no real third-party data was ever attached.
- **Standard permissions:** they held the app's ordinary `admin` and `user` roles — the same roles
  a real account gets. They did not impersonate any external service or grant anything unusual.
- **They are gone.** The accounts were deleted from the database, and `database.sql` now seeds no
  accounts at all (`-- No demo accounts and no sample rows are seeded`). A local DB query returns
  only two real accounts.
- **The published-credentials block on the login page is the key finding — see §4.**

### 3.6 Git history

The history is small and matches the working tree; nothing unexpected. Relevant commits:

```
5105e74  refactor: Remove demo login section from authentication view and related styles
3c19ad6  feat: Enhance flash messages with optional titles and improve notification messages
19294ac  Initial commit: SDC Hackathon PHP app (lite MVC, PDO, notifications, AI panel)
```

No commit introduces third-party code, remote script tags, redirects to external domains, or
credential collection. The two files that ever changed on the auth path are `login.php` and
`layout.css`, both only in `5105e74`.

### 3.7 Secrets and environment configuration

- **No credential is committed.** Searching all tracked files for Google API keys (`AIza…`), OAuth
  client secrets (`GOCSPX-…`), `sk-…` keys and a populated `APP_KEY` returns **0 matches**.
- `.env` is git-ignored; `storage/backups/` (DB dumps) is git-ignored; the only tracked file under
  `storage/` is `logs/.gitkeep`.
- Integration credentials live in the `settings` table and are **DB-only — there is no `.env`
  fallback**. Secrets are AES-256-GCM encrypted (`APP_KEY`) and are never sent to the browser;
  the local DB currently holds **no secret rows at all**, only public IDs and password-policy values.
- Verified at runtime: after storing a dummy secret, the rendered login page contained **0
  occurrences** of its plaintext.

### 3.8 Upload surface

`public/uploads/` is empty and hardened: `php_flag engine off`, every script extension denied
(`php|phtml|php3..8|phps|pl|py|cgi|asp|aspx|sh|shtml`), `Options -Indexes -ExecCGI`, and
`AddType text/plain` for those extensions. The AI chat's "attach" control is a client-side preview
only — the controller has no `move_uploaded_file` / `$_FILES` handling at all. There is therefore no
path by which a user could host a file on this origin.

---

## 4. Root-cause assessment

Google does not publish what its classifier reacted to, and the production site is currently down,
so no claim of proof is possible. Ranked by evidence:

### Primary candidate — the published demo credentials on the login page

This is the only content that ever existed on the flagged URLs which matches a credential-harvesting
pattern, and it sat on the **exact flagged page**:

- `/?page=auth&action=login` displayed, directly beneath the email/password form:
  `Demo logins` / `admin@sdc.local · admin123` / `user@sdc.local · user123`.
- `/` was flagged too, and `/` 302-redirects anonymous visitors to that same login page (§5), so
  both flagged URLs served the identical content.
- The combination — a password form that simultaneously advertises ready-made credentials for a
  reserved-TLD domain — is the shape of a phishing / credential-harvesting page. It is the single
  anomalous element on the page.
- Timeline: present from the initial commit `19294ac`, removed only on 2026-10-16 in `5105e74`.
  It was live for essentially the whole period Google was crawling.
- **The fix is in the source but was almost certainly never deployed** — the production host now
  returns "Application not found" (§1), and the removal commit is the second-newest on the branch.

### Contributing factor — brand impersonation signal

The same page carries a **Google-branded "Continue with Google" button** on an unrecognised shared
PaaS subdomain (`*.up.railway.app`). A Google-branded sign-in control on a domain Google has no
trust history for is a classic social-engineering/impersonation signal. Note the button was
*disabled* while Google was unconfigured, which is the safer state, but the Google logo and wordmark
still rendered.

### Contributing factor — no site identity on the credential page

The auth shell had **no footer and no statement of what the site is or who runs it**. A bare
credential form with no attribution is indistinguishable from a phishing kit. The app shell
(signed-in pages) has a footer; the auth shell did not.

### Ruled out, with evidence

- **Demo account *permissions*** — the accounts used synthetic data on a non-resolving TLD and held
  the app's ordinary roles (§3.5). Nothing privileged or impersonating.
- **Obfuscated / unauthorised / injected code** — none found (§3.2).
- **Third-party data exfiltration** — no off-origin requests on the auth pages, and every outbound
  endpoint in the app is an expected vendor API (§3.3).
- **Forced downloads / unwanted software** — none (§3.2).
- **Credential collection beyond the app's own login** — none; no OTP or recovery flows exist.
- **Google password collection** — the OAuth flow is official and never sees a Google password (§3.4).
- **Cloaking** — no user-agent branching (§3.2).

### Not determinable from outside

Whether Safe Browsing reacted to page content, to the reputation of the shared `up.railway.app`
domain (frequently abused for phishing, and a known source of false positives for PaaS subdomains),
or to both. The changes below address the content-side signals; if the flag persists after
deployment, domain reputation is the remaining explanation and the remedy is a review request with
the corrected site live.

---

## 5. Changes made

Five files changed. Nothing was deleted; no routing, authentication or application behaviour was
altered; no domain was changed; no browser protection was touched.

| File | Change | Reason |
|---|---|---|
| `app/views/auth_footer.php` | Added an identity footer to the auth shell: project name, what the site is, and an explicit non-affiliation statement. | §4 "no site identity" — an unattributed credential form is the shape of a phishing kit. This is the page Google flagged. |
| `public/css/layout.css` | Added `.auth-footer`, `.auth-footer__brand`, `.auth-footer__note`, `.auth__oauth-note` styles. | Styles for the two additions; reuses existing tokens, no layout change to the card. |
| `app/views/auth/login.php` | Added a line under the Google button (live/configured branch only): Google handles the sign-in end to end and the app never sees, asks for, or stores the Google password. | §4 "brand impersonation" — states the app's actual, limited role in that flow. |
| `.dockerignore` | **New.** Excludes `.env`, `.git`, `storage/backups/`, logs, `__pycache__`, the AI venv and docs from the image. | `Dockerfile` does `COPY . /var/www/html`, so a local build baked the developer's `.env` (APP_KEY, client secret, DB password) and DB backups into the image. Closes a credential-leak path. |
| `.env.example` | Corrected stale comments that claimed Google/OneSignal values are read from `.env` with a DB override (the code is DB-only), and added a warning to set `APP_ENV=production` / `APP_DEBUG=false` on the production host. | Misleading configuration docs; and debug mode prints internal error details to visitors. |
| `app/models/Setting.php` | Docblock only — the class comment still described a `.env` fallback that no longer exists. | Keeps the documented contract matching the enforced one. |

The demo-credentials block itself was **already removed** in commit `5105e74` and is not present in
the working tree — verified below. No further change was needed there.

---

## 6. Validation performed

All checks were executed; results are the actual output.

### 6.1 Static checks

- **PHP lint, PHP 8.3.35:** 48 files across `app/`, `public/` and the root — **0 failures**.
- **Python compile:** `ai-service/app.py`, `ai-service/agnes.py` — **OK**.
- No automated test suite, CI config or linter exists in the repository (no `composer.json`,
  `package.json`, `phpunit.xml`, `tests/`, `.github/`). The checks above plus §6.2–6.4 are the
  available automated coverage.

### 6.2 Route matrix (through Apache, HTTP)

| Route | Status | Redirect target |
|---|---|---|
| `/` | 302 | `/?page=auth&action=login` |
| `/?page=auth&action=login` | 200 | — |
| `/?page=auth&action=register` | 200 | — |
| `/?page=auth` | 302 | `/?page=auth&action=login` |
| `/?page=auth&action=google` | 302 | `/?page=auth&action=login` (graceful: not configured) |
| `/?page=auth&action=set_password` | 302 | `/?page=auth&action=login` (no pending sign-in) |
| `/?page=auth&action=google-callback` | 302 | `/?page=auth&action=login` |
| `/?page=auth&action=logout` (GET) | 405 | — (POST-only, as designed) |
| `/?page=dashboard` | 302 | `/?page=auth&action=login` |
| `/?page=profile` | 302 | `/?page=auth&action=login` |
| `/?page=key` | 302 | `/?page=auth&action=login` |
| `/?page=nope` | 404 | — |
| `/googlefaeecc3268cb03e3.html` | 200 | — |

All redirects are same-origin; no redirect leaves the site.

### 6.3 Real-browser check (headless Chrome, both flagged URLs + register)

| Check | `/` | `/?page=auth&action=login` | `/?page=auth&action=register` |
|---|---|---|---|
| HTTP status | 200 | 200 | 200 |
| Lands on | login page | login page | register page |
| Demo credentials in DOM | **false** | **false** | **false** |
| `google-site-verification` meta | present | present | present |
| Identity footer | present | present | present |
| Iframes | 0 | 0 | 0 |
| External `<script>` | none | none | none |
| Off-origin requests | none | none | none |
| Requests ≥ 400 | none | none | none |
| Console errors/warnings | none | none | none |
| Uncaught page errors | none | none | none |

**Result: PASS (0 hard failures).** Visually confirmed the login page renders correctly with the new
identity footer below the card, centred and legible, with the form layout unchanged.

### 6.4 Google verification file

`public/googlefaeecc3268cb03e3.html` is present, contains the correct single line
(`google-site-verification: googlefaeecc3268cb03e3.html`), and serves **200**. The
`<meta name="google-site-verification" …>` tag in `app/views/layouts/head.php` is intact and appears
on every rendered page — confirmed in the browser check above. Neither was modified.

### 6.5 Secret-leak re-check

With a dummy secret stored, the rendered login page contained **0** occurrences of its plaintext;
the row was then deleted and the page re-rendered to confirm the original state was restored (button
back to disabled, settings table back to public IDs only).

---

## 7. Remaining concerns and follow-ups

Not claimed as resolved, and not done here:

1. **The Safe Browsing flag itself.** No review request was submitted. After deploying, submit a
   review in Search Console → Security Issues. Google re-evaluates; the warning is not cleared by
   these changes alone.
2. **The production site is not running.** `x-railway-fallback: true` / "Application not found".
   Deploy before requesting review, and confirm `/`, `/?page=auth&action=login` and
   `/googlefaeecc3268cb03e3.html` all return the app rather than Railway's fallback.
3. **Set `APP_ENV=production` and `APP_DEBUG=false` on the Railway service.** Debug mode prints
   internal error details to visitors.
4. **Three integration credentials are unset in the database** (Google client secret, OneSignal REST
   key, Agnes API key). They must be re-entered on the Key page (`?page=key`) — the Google button
   stays disabled and AI chat stays unavailable until then.
5. **Shared-domain reputation cannot be fixed from the code.** If the flag persists after deployment
   with the corrected page live, the remaining explanation is the reputation of the shared
   `*.up.railway.app` subdomain. The remedy is the Search Console review request; a custom domain
   would remove the shared-host factor, but changing domains is explicitly out of scope here.
6. **45 notifications titled "test"** remain in the database. Not a security issue, but they are
   visible demo debris.
7. **Live visual verification is left to the developer**, per the brief.
8. The Agnes AI happy path remains unverified (no valid key), and Agnes streaming is not implemented.

---

## 8. Files changed in this task

```
app/views/layouts/auth_footer.php   modified  identity footer on the sign-in screen
app/views/auth/login.php            modified  Google-flow reassurance line (configured branch)
public/css/layout.css               modified  styles for the two additions
app/models/Setting.php              modified  docblock only (stale .env-fallback claim)
.env.example                        modified  corrected config docs + production debug warning
.dockerignore                       added     keep secrets, VCS and runtime data out of the image
progress.md                         added     this report
```

No application logic, routing, authentication behaviour or existing feature was removed or
reconfigured.
