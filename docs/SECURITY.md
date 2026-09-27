# Security, Integrity & Data Privacy Design

## Application security
| Control | Implementation |
|---|---|
| HTTPS | `force_https` redirect, HSTS, secure cookies |
| Passwords | Argon2id (or bcrypt fallback) via `password_hash`, automatic rehash; plain-text passwords are never stored |
| SQL injection | Every query goes through PDO prepared statements with emulation off (`app/lib/DB.php`) |
| XSS | All output is escaped with `e()`; admin-authored rich text passes an allow-list sanitizer; strict Content-Security-Policy with no inline scripts |
| CSRF | Per-session token on every POST form, verified with `hash_equals` |
| Sessions | HttpOnly, SameSite=Lax, strict mode, ID regenerated on login, 30-minute idle and 8-hour absolute expiry |
| Brute force | Per-account and per-IP lockout, CAPTCHA after failures, rate limits on OTP, registration, password reset and bids |
| Uploads | Extension **and** content-type (finfo) allow-list, size limit, PDF signature check, images re-encoded (strips payloads and EXIF GPS), random file names, private storage, served through an access-controlled `file.php` with `nosniff` and a sandbox CSP |
| RBAC | Permission matrix in `app/lib/Rbac.php`, enforced on every admin page and action |
| Critical actions | Password re-entry for close, cancel, award approval and schedule approval; optional dual authorization |
| Admin 2FA | RFC 6238 TOTP, optionally mandatory |
| Password reset | Single-use random token (only its hash is stored), 60-minute expiry, no account enumeration |
| Error handling | Generic error pages; details go to `storage/logs/php-error.log` |
| Headers | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy |
| Formula injection | CSV exports neutralize cells that start with `= + - @` |

## Bid integrity
- **Server time only.** Bid timestamps come from database `NOW(6)` (microseconds) inside a transaction holding a row lock on the property. The countdown uses a server-supplied remaining time, not the device clock.
- **Immutable history.** Bids are insert-only. Revisions and withdrawals are new rows linked by `previous_bid_id`.
- **Tamper evidence.** Each bid stores `sha256(prev_hash | bid fields)`, chained per property. Each audit record is chained globally. Admins can verify both chains from the UI.
- **Database enforcement.** `install/triggers.sql` blocks UPDATE and DELETE on `bids`, `audit_logs`, `ranking_snapshots` and `consents` for every DB user.
- **Closing.** At the official time new bids and modifications are refused, the ranking snapshot is frozen in `ranking_snapshots`, and the status becomes *Under Evaluation*.
- **No auto-award.** The system only recommends the highest *qualified* bidder. An authorized officer's approval is required.
- **Schedule changes** are never silent: they need a reason, are audited with the old and new values, can require dual authorization, and are emailed to participants.

## Duplicate and suspicious activity detection
- Unique email, unique normalized mobile number, and a unique hashed government ID number (blocked at registration).
- Flags for the same name and city, the same browser/device cookie, or many accounts from one IP.
- Flags for bursts of bids and for different bidders bidding from the same IP.
- Repeated failed sign-ins notify administrators.

## Data privacy (RA 10173 / NPC)
- A Privacy Notice explains the purpose, legal basis, sharing, retention, data subject rights and DPO contact.
- Consent is collected separately for the privacy notice, data processing, terms, per-bid terms and GPS. Each consent row records the date and time, user, document version, context and IP.
- Terms and Privacy documents are versioned; old versions are never overwritten, and bidders must re-accept new versions.
- GPS is collected only after an explicit click, with a purpose statement. A refusal is recorded as "Location permission not granted."
- Data minimization: the government ID number is stored only as a keyed hash plus its last 4 characters.
- Access to personal data is restricted by role, and admin views of bidder documents are audited.
- Bidder identities are never shown to other bidders.
- Retention: configurable (default 10 years for bidding and audit records). See the Privacy page.
