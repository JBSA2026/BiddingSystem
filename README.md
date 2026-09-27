# Cityland Online Property Bidding System

A secure, mobile-responsive online bidding platform for condominium units, parking slots, properties and other assets.
It is built with plain **PHP 8.1+, MySQL/MariaDB, HTML5, CSS3 and JavaScript**. There are no frameworks and no Composer,
Node.js, Docker or other application servers, so it deploys on ordinary **cPanel shared hosting** or any VPS.

```
VIEW PROPERTY → REGISTER → VERIFY ACCOUNT → REVIEW TERMS → SUBMIT BID → RECEIVE BID CONFIRMATION → MONITOR STATUS → WAIT FOR EVALUATION/AWARD
```

## Highlights

| Area | What you get |
|---|---|
| Public listing | Search and filters (type, location, price range, status, reference no.), photo carousel, downloadable documents, bidding rules, terms, contact details, QR code, printable details |
| Bidders | Registration with ID and qualification-document upload, email OTP/link, optional SMS OTP, CAPTCHA, duplicate-account detection, dashboard, notifications |
| Bidding | Server-time schedule, countdown, immutable bid history (revisions and withdrawals are new rows), consent-based GPS, per-property rules, anti-sniping auto-extension |
| Evaluation | Ranking locked at close, status set to *Under Evaluation*, Qualified/Disqualified/Under Review, recommendation of the highest **qualified** bidder, dual-authorization award approval |
| Admin | Role-based portal (Super Admin, Bidding Administrator, Approving Officer, Auditor), reports, CSV/Excel export, backups |
| Integrity | Hash-chained bids and audit log, optional DB triggers that block UPDATE/DELETE, integrity checker |
| Security | Argon2id/bcrypt hashing, CSRF protection, prepared statements, output escaping, strict CSP, secure uploads, rate limiting, lockout, session expiry, admin TOTP 2FA |
| Privacy | Privacy Notice, versioned Terms, recorded consents (date/time, user, version), GPS consent, retention policy, DPO contact (RA 10173 / NPC principles) |

## Try it first: test environment

```
docker compose up -d --build
```
This starts the website at http://localhost:8080, the admin portal at `/admin`, a fake inbox at http://localhost:8025 and phpMyAdmin at http://localhost:8081.
It comes with test accounts and sample properties in every status. XAMPP and cPanel staging options, the test accounts and a
UAT checklist are in **[docs/TESTING.md](docs/TESTING.md)**.

## Documentation

| Document | For |
|---|---|
| [docs/TESTING.md](docs/TESTING.md) | Test environment (Docker / XAMPP / staging), test accounts, UAT checklist |
| [docs/INSTALL.md](docs/INSTALL.md) | Installation and setup on cPanel or a VPS |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Database, SMTP email, SMS, CAPTCHA, security and all settings |
| [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) | Administrator setup procedure and day-to-day operation |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | Basic guide for bidders |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Backup and restore procedure |
| [docs/SECURITY.md](docs/SECURITY.md) | Security, integrity and data-privacy design |
| [docs/LICENSING.md](docs/LICENSING.md) | License keys, expiry/grace/lock rules, plan limits, license generator |

## Quick start (cPanel)

1. Upload the package to `public_html` (or a sub-folder) and extract it.
2. In cPanel → **MySQL® Databases**, create a database and a user, and add the user to the database with **ALL PRIVILEGES**.
3. Open `https://your-domain/install.php` and follow the steps.
4. **Delete `install.php`**, add the cron job the installer shows you, and sign in at `/admin`.

## Folder layout

```
index.php, property.php, register.php …   Public site and bidder portal
admin/                                     Administrator portal (/admin)
app/                                       Application code and config (web access denied)
  config.sample.php                        Documented configuration template
  lib/                                     Core classes (Bidding, Auth, Audit, Mailer …)
  views/                                   Layout templates
assets/                                    CSS, JS, images
install/schema.sql                         Database schema and seed data
install/triggers.sql                       Optional immutability triggers
storage/                                   Private uploads, backups, logs, sessions (web access denied)
tools/                                     CLI utilities (create admin, demo data)
cron.php                                   Scheduled tasks (closing, reminders, email, backups)
install.php                                Web installer (delete after use)
dev/mailbox.php, admin/testtools.php       Test-mode only (return 404 on the live site)
docker/, docker-compose.yml, tests/        Local test environment and automated tests (not needed on cPanel)
license-tools/                             Vendor license generator (not shipped to clients)
license-portal/                            Vendor-hosted PayMongo renewal portal (not shipped to clients)
docs/                                      Documentation
```
