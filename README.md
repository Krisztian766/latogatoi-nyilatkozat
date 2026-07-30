# Látogatói Nyilatkozat (Visitor Declaration System)

A self-hosted PHP visitor sign-in system: visitors fill out a declaration form and sign with a touch/mouse signature pad, admins manage submissions, generate PDFs, and export records. Built for a small business site (bilingual Hungarian/English UI) that needs a simple, GDPR-aware visitor log without a third-party SaaS.

## Features

- Public signing flow (`sign.php` / `sign_submit.php`) with an in-browser signature pad
- Admin panel: list/search declarations, view/edit individual records, bulk and single PDF export, audit log, per-admin user management
- Tamper-evident records — each declaration is stored with a SHA-256 hash over its core fields, checked on view
- GDPR-oriented data retention: configurable retention period, automatic purge of expired declarations (`cron/purge_expired.php`, runnable via CLI cron or an HTTP endpoint protected by a constant-time-compared secret token)
- Email notifications on new submissions (with CC support), sent through a minimal hand-rolled SMTP client (`sendSmtpEmail()`) — no external mail library dependency
- Basic brute-force protection on admin login: failed attempts are rate-limited per IP with a lockout window, and the admin gets alerted by email once the lockout triggers

## Security notes

- All database access goes through PDO prepared statements — no raw SQL string interpolation.
- Admin passwords are checked with `password_verify()` (bcrypt), never compared in plaintext.
- CSRF tokens are generated per session and checked with `hash_equals()` on state-changing requests.
- `includes/` is blocked from direct web access via `.htaccess`; `uploads/documents/` blocks execution of PHP/CGI files so an uploaded file can never be run as a script.
- Real credentials live in `includes/db.php`, which is gitignored — only `includes/db.php.example` is committed.

## Setup

1. Copy `includes/db.php.example` to `includes/db.php` and fill in your DB/SMTP credentials and a random `CRON_SECRET`.
2. Point your web server's document root at the project root; ensure `.htaccess` rules are honored (Apache + `mod_rewrite`).
3. Create the database schema (`declarations`, `admin_users`, `audit_log`, `settings` tables — see the `getDB()`/query usage in `includes/` for the expected columns).
4. Schedule `cron/purge_expired.php` to run daily (CLI preferred; the HTTP endpoint requires `?token=<CRON_SECRET>`).

## Responsible use

This handles real personal data (names, contact info, signatures) if deployed live. Configure a sensible retention period, keep `includes/db.php` and `CRON_SECRET` out of version control, and follow applicable data protection law (e.g. GDPR) for your jurisdiction.

## License

MIT.
