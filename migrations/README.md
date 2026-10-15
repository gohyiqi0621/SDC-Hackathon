# Migrations

`database.sql` at the repo root is the **single source of truth** for the
schema — it creates the database and every table. This folder holds only
*changes made after* that file, so all databases (yours, each teammate's, and
Railway's) can be brought to the same shape. Git shares the SQL files, never
the data.

## Layout

The repo convention is one folder per teammate:

```
migrations/
  <your-name>/
    migration_YYYYMMDD_HHMMSS.sql
```

The timestamp keeps files in creation order, and separate folders stop two
people from creating the same filename.

## Running a migration

1. Write the change as a new file in your own folder.
2. Run it on your local XAMPP database (phpMyAdmin → Import, or the SQL tab).
3. Record it: `INSERT INTO schema_migrations (filename) VALUES ('...');`
4. Commit and push, and tell the team the filename.
5. Teammates pull, then run any filename **not** already in their
   `schema_migrations`.
6. Run the same file on the **Railway** database.

## `schema_migrations`

```sql
SELECT filename FROM schema_migrations;   -- what has already been applied
```

It starts empty on a fresh import of `database.sql`, because that file already
creates every table in its final shape — there is nothing left to apply.

## Rules

- Run files in filename (timestamp) order, oldest first.
- Never change a table only in phpMyAdmin — always write the SQL file too.
- Never edit a migration that has already run; add a new one.
- Avoid `DROP TABLE` / `DROP COLUMN` unless you really mean to delete data.
- Never commit a dump containing real user data or an API key.

## Portability (XAMPP MariaDB vs Railway MySQL 8)

Local XAMPP runs MariaDB; Railway runs MySQL 8. Some MariaDB syntax is a hard
error on MySQL 8:

| Don't use (MariaDB-only) | Use instead |
|---|---|
| `ALTER TABLE t ADD COLUMN IF NOT EXISTS c ...` | `ALTER TABLE t ADD COLUMN c ...` |
| `ALTER TABLE t ADD KEY IF NOT EXISTS k (...)` | `ALTER TABLE t ADD KEY k (...)` |
| `ALTER TABLE t DROP COLUMN IF EXISTS c` | `ALTER TABLE t DROP COLUMN c` |

`CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` **are** valid MySQL 8 and
are fine. Rely on `schema_migrations` to avoid re-running a file rather than on
`IF NOT EXISTS` guards.
