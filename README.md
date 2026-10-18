# SDC Hackathon Project

> One-line description of what your project does and which SDG it addresses.

**Live demo:** https://YOUR-APP.up.railway.app
**Tech stack:** PHP, PDO, MySQL, XAMPP (local), VS Code, GitHub, Railway (deployment)
**AI service used:** (e.g. Gemini / GPT, integrated through an API key)

---

## 1. First-time setup (each teammate does this once)

### Install
- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL)
- [VS Code](https://code.visualstudio.com/)
- [Git](https://git-scm.com/downloads) (VS Code uses it in the background; skip if you already have it. Check with `git --version`.)

### Clone the project
**In VS Code:**
1. Press `Ctrl+Shift+P` (Mac: `Cmd+Shift+P`) and type **Git: Clone**.
2. Paste the repository URL: `https://github.com/gohyiqi0621/SDC-Hackathon.git`
3. Choose your XAMPP `htdocs` folder (for example `C:\xampp\htdocs`) as the location.
4. Click **Open** when VS Code asks.

**Or in a terminal** (inside `htdocs`):
```
git clone https://github.com/gohyiqi0621/SDC-Hackathon.git
```

The folder is created as `htdocs/SDC-Hackathon`. The commands below use
`cd SDC-Hackathon`; adjust if you cloned it under another name.

### Run the setup script (this is the step people miss)

**A fresh clone has no `.env` file.** That is deliberate: `.env` holds the database
password and the encryption key, so it is listed in `.gitignore` and never reaches
GitHub. Without it the app stops with:

> Missing .env file or DB_HOST variable.

Create it with one command, from the project root:

```
cd C:\xampp\htdocs\SDC-Hackathon
php setup.php
```

It asks for your MySQL details (press Enter for the XAMPP defaults), writes
`.env`, generates `APP_KEY`, and then tells you whether it can reach the database.
Re-running it is safe — it never overwrites an existing `.env` unless you add
`--force`.

### Import the database
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Import `database.sql` — it creates the `sdc_app` database and every table:

```
C:\xampp\mysql\bin\mysql.exe -u root < database.sql
```

   No MySQL on your PATH? Paste `database.sql` into phpMyAdmin → **SQL** instead.
   `database.sql` is destructive: it drops and recreates the tables.

### Open the app
```
http://localhost/SDC-Hackathon/public
```

Note the **`/public`** — the site is served from that folder, not the project root.
Going to `http://localhost/SDC-Hackathon/` gives a directory listing or a 403.

### API keys
No keys are in the repository, and none go in `.env`. They live in the database:
register the first account, promote it once with

```
UPDATE users SET role = 'admin' WHERE email = 'you@example.com';
```

then sign in and open **Key** to paste the Google, OneSignal, Agnes and Brevo
credentials. AI Chat additionally needs the Python service (`cd ai-service` and
`python app.py`); everything else works without it.

---

## 2. Daily Git workflow (VS Code)

### Quick routine
1. Open the project path inside `htdocs` (for example `C:\xampp\htdocs\REPO-NAME`) in a terminal and run:
   ```
   code .
   ```
2. In VS Code, click the **refresh / sync icon at the bottom-left status bar** to pull the latest code.
3. Edit the code.
4. Open **Source Control** (`Ctrl+Shift+G`).
5. **Stage** your files (click **+** next to each file).
6. Write a message and **Commit**.
7. Click **Sync Changes** to push.

The detailed version of the same steps is below.

### Detailed steps

Click the **Source Control** icon in the left sidebar (the branch symbol), or press `Ctrl+Shift+G`.

### Step 1: Pull before you start
Get your teammates' latest changes first.
- Click the **...** menu in Source Control > **Pull**.
- Or in the terminal: `git pull origin main`

### Step 2: Do your work
Edit code as usual.

### Step 3: Check what changed
Changed files appear in the Source Control panel. Click a file to see exactly what changed.

### Step 4: Stage your changes
- Click the **+** next to a file, or the **+** next to "Changes" to stage everything.
- Or in the terminal: `git add .`

### Step 5: Commit with a clear message
- Type a message in the box (for example `Add login page validation`) and click **Commit**.
- Or in the terminal: `git commit -m "Add login page validation"`

### Step 6: Pull again, then push
- **...** menu > **Pull**, then click **Sync Changes** or **Push**.
- Or in the terminal:
```
git pull origin main
git push origin main
```

### Golden rules
- Pull **before** you start and **before** you push.
- Commit small and often, with clear messages.
- Tell the team when you change shared files (database files, config, `index.php`).
- Never commit passwords, API keys, or real user data. This includes SQL files: do not put the real API key inside any migration, seed, or dump file.

---

## 3. Resolving merge conflicts

A conflict happens when you and a teammate edit the **same lines** of the same file. Git stops and asks you to choose.

### Step 1: Pull and see the conflict
Pull as normal. Git will say `CONFLICT (content): Merge conflict in somefile.php`.
In VS Code, conflicted files appear under **Merge Changes** in Source Control.

### Step 2: Open the conflicted file
You will see markers like this:
```
your version of the code
```

### Step 3: Fix it
VS Code shows buttons above the conflict:
- **Accept Current Change** keeps your version.
- **Accept Incoming Change** keeps your teammate's version.
- **Accept Both Changes** keeps both.
- Or edit by hand, then **delete the three marker lines** (`<<<<<<<`, `=======`, `>>>>>>>`). The file must be valid code again.

### Step 4: Mark as resolved and commit
- Stage the file (click **+**), then write a message like `Resolve merge conflict in somefile.php` and click **Commit**.
- Or in the terminal:
```
git add somefile.php
git commit -m "Resolve merge conflict in somefile.php"
```

### Step 5: Test, then push
Check that the app still works, then push.

### Escape hatch
If you get stuck in the middle of a merge and want to start over:
```
git merge --abort
```

### Avoiding conflicts
- Pull often.
- Agree who edits which file.
- Don't reformat whole files (it changes every line).

---

## 4. Database

Each database is separate: your XAMPP database, each teammate's XAMPP database, and the Railway database do **not** sync automatically. Git only shares the SQL files, not the data.

### Folder and file format
`database.sql` at the repository root is the **single source of truth** — it creates the
database and every table, so a fresh clone only needs that one file. Changes made *after*
it go in `migrations/`, one folder per teammate:

```
migrations/
  jocelyn/
    migration_20261015_143000.sql
  gohyiqi/
    migration_20261018_164500.sql
```

The date and time in the name (`YYYYMMDD_HHMMSS`) shows the order the files were made, and
separate folders stop two people from creating the same file name. Record a file you have run:

```sql
INSERT INTO schema_migrations (filename) VALUES ('gohyiqi/migration_20261018_164500.sql');
```

### When you change a table
1. Write the change as a **new SQL file** in your own folder, for example `database/migrations/jocelyn/migration_20261015_143000.sql`.
2. Run it on your own XAMPP database (phpMyAdmin > Import or SQL tab).
3. Commit and push the file.
4. Tell the team the file name.
5. Teammates pull, then run the new file on their own database.
6. The same file is also run on the **Railway database** (see section 5).

### Rules
- Run files in date/time order, oldest first.
- Never change a table only in phpMyAdmin. Always write the SQL file too.
- Never edit a migration file that has already been run. Add a new one.
- Avoid `DROP TABLE` / `DROP COLUMN` unless you really mean to delete data.
- Don't commit a database dump that contains real user data or the API key.

---

## 5. Using Railway

Railway hosts the live website and the central MySQL database.

### How the app connects to the database
`config.php` reads the connection settings from `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and
`DB_PASS`. Locally those come from your `.env`; on Railway they are supplied as environment
variables pointing at the MySQL service (`DB_HOST=${{MySQL.MYSQLHOST}}`, and so on). Real
environment variables are read first, so the same code works in both places and no password
is ever committed.

`.env` itself is git-ignored. That is why a fresh clone cannot boot until `php setup.php` has
created one — see section 1.

### Deploy the website
1. Railway project > your PHP service > **Settings** > connect the GitHub repository and choose the `main` branch.
2. Every push to `main` triggers a new deployment automatically.
3. Watch progress under the **Deployments** tab. Click a deployment to see **build logs** and **runtime logs**.
4. If something breaks, read the logs first. Most errors show up there.

### Variables
Service > **Variables** tab. Add the database variables (`MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`), usually by referencing the MySQL service's variables. The database login must be here, because it is needed to reach the database in the first place. The AI API key lives in the database. After changing a variable, Railway redeploys the service.

### Get a public link
Service > **Settings** > **Networking** > **Generate Domain**. Put this link in the "Live demo" line at the top of this README.

### Connect to the Railway database from your laptop
Use this to import or run SQL files.
1. Click the **MySQL service** > **Connect** tab.
2. Copy the **public** host, port, user, password, and database name. (The private/internal host only works between Railway services.)
3. Open HeidiSQL, DBeaver, or MySQL Workbench and create a connection with those details.
4. Run or import your SQL file.

Or from a terminal:
```
mysql -h PUBLIC_HOST -P PUBLIC_PORT -u USER -p DATABASE < database/migrations/003_xxx.sql
```

### Importing a full database (first time only)
1. In phpMyAdmin: Export > Custom > SQL (tick "Add DROP TABLE" if you want to replace existing tables).
2. Delete any `CREATE DATABASE` and `USE ...` lines from the file (Railway's database is named `railway`).
3. Import it into Railway using the method above.

Re-importing a full dump **replaces** the data on Railway, so only do it when the Railway database holds test data.

### Before the submission deadline
- Open the live link in an incognito window and test every main feature.
- Make sure the repository link is accessible to the judges.
- Check that the API key has a spending limit.
- Keep the Railway project running through the judging period.

---

## 6. Environment variables reference

Only the database connection uses environment variables, and they are set in Railway **Variables**.

| Variable | Purpose |
|---|---|
| `MYSQLHOST` | Database host |
| `MYSQLPORT` | Database port |
| `MYSQLDATABASE` | Database name |
| `MYSQLUSER` | Database user |
| `MYSQLPASSWORD` | Database password |

---

## 7. Declarations

- **AI tools used in development:** (list them)
- **External APIs / services:** (for example Gemini / GPT, Railway)
- **Datasets / models:** (list them, or "none")
- **Pre-existing code:** (list it, or "none")
