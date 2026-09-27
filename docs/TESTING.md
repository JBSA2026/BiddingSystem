# Test Environment & User-Acceptance Testing (UAT)

Test the whole system safely **before** installing it on the live cPanel site. The test environment:

- shows a red **TEST ENVIRONMENT** banner on every page (the live site never does);
- comes with ready-made **test accounts** and **sample properties in every bidding status**;
- catches every email, including verification codes, in a **test mailbox**, so no real inboxes are needed;
- has admin **Test tools** to fast-forward closing times and reset all data with one click.

Test mode is switched on by `'env' => 'testing'` in the config. The mailbox and Test tools pages return *404 Not Found* on a production install.

---

## Option A — Docker (recommended, one command)

Works on Windows, macOS and Linux.

1. Install **Docker Desktop**: https://www.docker.com/products/docker-desktop
2. Download or clone this repository and open a terminal in its folder.
3. Start everything:
   ```
   docker compose up -d --build
   ```
   The first run downloads and builds for a few minutes. Wait until `docker compose logs app` shows **"Test environment ready."**
4. Open:

| What | Address |
|---|---|
| Website (bidders) | http://localhost:8080 |
| Admin portal | http://localhost:8080/admin/ |
| Test mailbox (Mailpit) | http://localhost:8025 |
| Built-in mailbox page | http://localhost:8080/dev/mailbox.php |
| Database (phpMyAdmin) | http://localhost:8081 |

Useful commands:

| Command | Does |
|---|---|
| `docker compose logs app` | Shows the test accounts again |
| `docker compose exec app php tools/testenv.php reset` | Wipes and reloads fresh test data (or use Admin → Test tools) |
| `docker compose exec app sh tests/run-e2e.sh` | Runs the automated suite (≈90 checks) on a **separate** database |
| `docker compose down` | Stops the environment (data is kept) |
| `docker compose down -v` | Stops it and deletes all test data |

The code folder is mounted live, so edits to PHP, CSS or JS files show on refresh.
If port 8080, 8025, 8081 or 3307 is already in use, change the left-hand number under `ports:` in `docker-compose.yml`.

---

## Option B — XAMPP / Laragon on Windows (no Docker)

1. Install **XAMPP** (PHP 8.1+) from https://www.apachefriends.org and start **Apache** and **MySQL**.
2. Copy the project folder to `C:\xampp\htdocs\cityland`.
3. Open *Command Prompt* in that folder and run:
   ```
   C:\xampp\php\php.exe tools\testenv.php setup --url=http://localhost/cityland --db-host=127.0.0.1 --db-user=root --db-pass=
   ```
   This creates `app/config.php` in **test mode**, creates the `cityland_test` database and loads the test data.
4. Open http://localhost/cityland. Emails appear at http://localhost/cityland/dev/mailbox.php.

To reset: `C:\xampp\php\php.exe tools\testenv.php reset`
Scheduled tasks: use **Admin → Test tools → Run scheduled tasks now**, or run `C:\xampp\php\php.exe cron.php`.

> Before copying the folder to the live server, delete this test `app/config.php`. The live site must be installed with `install.php`.

---

## Option C — cPanel test / staging site (recommended for sharing with staff)

Cityland staff can test from their own phones and PCs, on the same kind of server as the live site.

1. **Subdomain**: in cPanel → **Domains** (or **Subdomains**), create for example `test-bidding.yourdomain.com`.
   Note its document root, for example `/home/CPANELUSER/test-bidding.yourdomain.com`.
2. **PHP version**: in cPanel → **MultiPHP Manager**, set that subdomain to **PHP 8.1 or newer**.
   In **Select PHP Version → Extensions**, make sure `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip` and `curl` are ticked.
3. **Database**: in cPanel → **MySQL® Databases**, create a *separate* database (for example `cpuser_bidtest`) and a user, and add the user with **ALL PRIVILEGES**.
4. **Upload**: in **File Manager**, open the subdomain folder, **Upload** `cityland-bidding-cpanel.zip` and **Extract** it there.
   The files, including `index.php`, `install.php` and the hidden `.htaccess`, must sit directly in that folder, not in a sub-folder.
   Tick *Settings → Show Hidden Files* to see `.htaccess`.
5. **SSL**: in cPanel → **SSL/TLS Status**, run **AutoSSL** for the subdomain.
6. **Install**: open `https://test-bidding.yourdomain.com/install.php`. Choose **Test / staging site**, enter the database details and your own admin account, and click **Install**.
   The final screen lists all the test accounts.
7. **Protect it**: in cPanel → **Directory Privacy**, open the subdomain folder, tick *Password protect this directory*, and create a username and password for your testers.
   The test mailbox is otherwise open to anyone who finds the address.
8. **Cron (optional for testing)**: in cPanel → **Cron Jobs**, add *Once Per Minute*: `/usr/local/bin/php /home/CPANELUSER/test-bidding.yourdomain.com/cron.php >/dev/null 2>&1`
   Bidding still closes on time without cron. Cron sends the closing reminders and runs backups. You can also use **Admin → Test tools → Run scheduled tasks now**.
9. Delete `install.php`. It is already locked, but deleting it is good practice.

To start over, use **Admin → Test tools → Reset test data**.
When testing is finished, install the live site **fresh** with the **Live site** option, on its own domain and database. Do not copy the test site's `app/config.php`.

---

## Test accounts

| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@test.local | Admin@12345 |
| Bidding Administrator | biddingadmin@test.local | Admin@12345 |
| Approving Officer | approver@test.local | Admin@12345 |
| Auditor / Viewer | auditor@test.local | Admin@12345 |
| Bidder — approved | bidder1@test.local (Juan Dela Cruz) | Bidder@12345 |
| Bidder — approved (company) | bidder2@test.local (Maria Santos) | Bidder@12345 |
| Bidder — approved, on watchlist | bidder3@test.local (Jose Reyes) | Bidder@12345 |
| Bidder — awaiting admin approval | pending@test.local | Bidder@12345 |
| Bidder — email not yet verified | unverified@test.local | Bidder@12345 |
| Bidder — blacklisted (cannot sign in) | blacklisted@test.local | Bidder@12345 |

## Sample properties

| Property | Status | Use it to test |
|---|---|---|
| 1BR Unit 1508, Pioneer Tower | Open, 3 days | Revised bids, ranking visible to bidders, number of bidders |
| Studio 0912, Shaw Tower | Open, **closes ~20 min after setup** | Countdown, anti-sniping extension, automatic close, highest bid visible |
| Parking Slot B2-117 | Open | **One bid only**, **GPS required** |
| Office Unit 11F-A | Upcoming (opens in 1 hour) | Pre-qualification request, bid-security deposit, property-specific document |
| 2BR Unit 2203, Grand Emerald | **Under Evaluation** | Qualify/disqualify bidders, recommendation, award approval |
| Commercial Unit G-05, Herrera Tower | **Awarded** | Complete award record (highest bidder disqualified → next qualified bidder won, with a backup) |
| Lot 14, Tagaytay | Cancelled | Cancellation display and emails |
| Parking Slot P3-020 | Unpublished draft | Publish / unpublish, admin preview |

Need more time or a quicker close? Use **Admin → Test tools → Fast-forward closing** or **Open an upcoming property now**.

---

## UAT checklist

Tick each item and note any issue with a screenshot.

**Public site and registration**
- [ ] Search and each filter work: type, location, price range, status, reference number
- [ ] The property page shows the gallery, details, documents, rules, terms, contact details and QR code, and prints cleanly
- [ ] Register a new bidder with an ID upload. The verification code arrives in the test mailbox.
- [ ] Registering again with the same email, mobile or ID number is rejected
- [ ] A wrong CAPTCHA is rejected
- [ ] `unverified@test.local` is sent to the verification page after signing in
- [ ] `blacklisted@test.local` cannot sign in

**Eligibility and bidding** (sign in as `bidder1`)
- [ ] `pending@test.local` sees the requirements checklist and a **disabled** Submit Bid button
- [ ] A bid below the minimum is rejected. A valid bid gives a bid reference, an acknowledgment page, a download and an email.
- [ ] A revised bid keeps the earlier bid in the history, and a revision below the increment is rejected
- [ ] GPS: **Share my location** asks the browser for permission; **Don't share** records "Location permission not granted."
- [ ] On the GPS-required parking slot, the bid is refused without location. A second bid is refused (one bid only).
- [ ] Bidders never see other bidders' names

**Closing** (Studio 0912, or fast-forward any open property)
- [ ] The countdown follows server time, even if you change your PC clock
- [ ] A bid in the final 5 minutes extends the closing time by 5 minutes, and participants are emailed
- [ ] At closing, bids are refused, the ranking is locked and the status is **Under Evaluation**

**Evaluation and award** (2BR Unit 2203)
- [ ] `biddingadmin` marks bidders Qualified/Disqualified with remarks, and bidders are emailed
- [ ] The recommendation is the highest **qualified** bidder, not simply the highest bid
- [ ] `biddingadmin` recommends a winner and a backup. They **cannot** approve their own recommendation.
- [ ] `approver` approves with a reference, remarks, document and password, and the status becomes **Awarded**
- [ ] Winning, backup and non-winning emails are received

**Admin controls**
- [ ] Add a property with photos and documents, then publish, unpublish and archive it
- [ ] After bidding opens, the price and rules are locked. A schedule change needs a reason and a second officer's approval.
- [ ] `auditor` can view but cannot change anything
- [ ] Approve and reject bidder documents, flag a bidder, blacklist, and add internal notes
- [ ] Reports download and open in Excel
- [ ] **Audit Trail → Verify integrity** reports every record intact
- [ ] **Backup → Create backup now** works
- [ ] The Super Admin can enable 2FA with an authenticator app

**Mobile**
- [ ] Repeat registration, bidding and the dashboard on a phone. No sideways scrolling, and buttons are easy to tap.

## Automated tests

`tests/run-e2e.sh` runs about 90 end-to-end checks covering registration, OTP, duplicates, uploads, CSRF, CAPTCHA, eligibility,
bidding rules, GPS, immutability triggers, anti-sniping, dual authorization, closing, evaluation, award, exports, audit integrity,
backups, XSS and brute-force protection. It uses its own database (`cityland_e2e`) and a temporary web server.
