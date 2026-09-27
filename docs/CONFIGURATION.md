# System Configuration

Configuration is split into two places:

1. **`app/config.php`** holds server-level secrets (database, SMTP, SMS, CAPTCHA, security). It is created by the installer; see `app/config.sample.php` for the documented template.
2. **Admin → Settings** holds business settings stored in the database: contacts, DPO, policies and toggles. Every change is audited.

## app/config.php

### `app`
| Key | Description |
|---|---|
| `url` | Public URL without a trailing slash, for example `https://bidding.cityland.com.ph` |
| `timezone` | `Asia/Manila`. All schedules use this server time. |
| `debug` | `false` in production |
| `force_https` | Redirects HTTP to HTTPS and sends HSTS |
| `secret_key` | 64-character random string. Used to hash OTPs, tokens and government ID numbers. **Never change it after go-live**, because existing OTPs and ID-duplicate checks would stop matching. |
| `storage_path` | Private folder for uploads, backups, logs and sessions. Move it outside `public_html` for best security. |
| `max_upload_mb` | Per-file upload limit (default 10) |

### `db`
Host, port, database name, user and password, from cPanel → MySQL Databases. Use `charset` `utf8mb4`.

### `mail` (SMTP)
| Provider | host | port | encryption |
|---|---|---|---|
| cPanel email account | `mail.yourdomain.com` | 465 | `ssl` |
| cPanel (STARTTLS) | `mail.yourdomain.com` | 587 | `tls` |
| Google Workspace | `smtp.gmail.com` (app password) | 465 | `ssl` |
| Microsoft 365 | `smtp.office365.com` | 587 | `tls` |
| SendGrid | `smtp.sendgrid.net` (user `apikey`) | 465 | `ssl` |
| Amazon SES | `email-smtp.<region>.amazonaws.com` | 465 | `ssl` |

`driver` can be `smtp` (recommended), `mail` (PHP `mail()`), or `log` (writes emails to `storage/logs/mail.log`, for testing only).

**Editing email settings without touching files:** a Super Admin can change the driver, host, port, encryption, username,
password, From address and SSL verification in **Admin → Settings → Email settings**, then click **Save & send test email**.
Values saved there override the `mail` block in `app/config.php`. The password is stored encrypted (AES-256-GCM, keyed by
`app.secret_key`). Leave the password blank to keep the saved one. Saving also re-queues any failed emails.
On cPanel, create a mailbox first (cPanel → Email Accounts, e.g. `bidding@yourdomain`) and use the *cPanel email (SSL 465)* preset.
Emails are queued in the `email_queue` table, sent at the end of each request, and retried up to 5 times by `cron.php`.

### `sms` (mobile OTP)
| Key | Description |
|---|---|
| `provider` | `none`, `semaphore` (semaphore.co, Philippines) or `http` (generic gateway) |
| `api_key`, `sender_name` | Credentials and registered sender name |
| `http_url`, `http_method` | For `http`: URL with placeholders `{to}`, `{message}`, `{api_key}`, `{sender}` |

After configuring a provider, tick **Require mobile SMS OTP verification** in Admin → Settings.

### `captcha`
`builtin` is a self-hosted image CAPTCHA that needs no external service. `recaptcha` is Google reCAPTCHA v2 and needs `site_key` and `secret_key`. `turnstile` is Cloudflare Turnstile.
A hidden honeypot field is always checked. Login and password-reset forms show the CAPTCHA after repeated failures.

### `security`
| Key | Default | Meaning |
|---|---|---|
| `max_login_attempts` | 5 | Failed sign-ins per account before temporary lockout |
| `max_ip_attempts` | 20 | Failed sign-ins per IP before lockout |
| `lockout_minutes` | 15 | Lockout / counting window |
| `session_idle_minutes` | 30 | Idle timeout. Overridden by the Settings value. |
| `session_absolute_hours` | 8 | Maximum session age |
| `password_min_length` | 10 | Also requires upper-case, lower-case and a digit |
| `cron_key` | random | Needed for `cron.php?key=…` over HTTP |
| `trusted_proxies` | `[]` | Proxy IPs, for example Cloudflare, whose `X-Forwarded-For` header is trusted |

## Admin → Settings (database)

| Setting | Effect |
|---|---|
| Website/company name, contacts, office address | Shown in the header, footer, emails and contact sections |
| DPO name/email/phone | Shown on the Privacy page (data privacy concerns) |
| Admin notification emails | Recipients of admin alerts. If blank, all relevant admins by role receive them. |
| Closing reminder hours | Participants are emailed N hours before closing |
| Data retention (years) | Shown in the retention policy |
| Duplicate IP threshold | Flags accounts when too many register from one IP in 30 days |
| Bidder accounts must be approved | Admin approval required before bidding |
| Documents must be approved | Uploaded documents must be approved, not just submitted |
| Require mobile OTP | Needs an SMS provider |
| Dual authorization — awards | The approver must be different from the recommender |
| Dual authorization — schedule | Schedule changes after bidding starts need a second officer |
| Require 2FA for all admins | Forces TOTP enrolment |
| Enable bid security | Turns on the deposit requirement for properties configured with one |
| Payment instructions | Shown to bidders on the deposit page |

## Per-property bid rules (Admin → Properties → Edit)

| Rule | Options |
|---|---|
| Number of bids | One bid only, or multiple/revised |
| Higher bids only | Revisions must exceed the previous bid by at least the increment |
| Minimum increment | Applies to revisions, and over the current highest bid when the highest price is visible |
| Withdrawal | Allowed or not allowed (a withdrawal is a new, permanent record) |
| Visibility | Own ranking, highest bid amount, number of bidders (identities are never shown) |
| GPS | Optional or required |
| Qualification | Approved account required; pre-qualification per property; required documents |
| Anti-sniping | Enabled, trigger period, extension length, maximum extensions (disclosed to bidders) |
| Bid security | Required, amount, refundable (needs the global toggle) |
| Property terms | Versioned. Each bid records the version accepted. |

After bidding opens, the pricing, rules, requirements and terms are locked. The schedule can only be changed through
**Bids & evaluation → Change schedule**, which needs a reason, is audited, requires dual authorization if enabled, and notifies participants.

## Payment gateways (future)

The `payments` table already stores the method (`gcash`, `maya`, `qrph`, `online_banking`, `bank_transfer`), reference number,
gateway name, gateway transaction ID and reconciliation status. Automatic processing is **off**: `payment_gateway` is `none`.
A future integration only needs to create or update `payments` rows from the gateway's webhook and set `status` to `verified`.
