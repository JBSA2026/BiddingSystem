# Installation & Setup Guide

> Want to try it first? See [TESTING.md](TESTING.md) for a ready-made test environment.
> You do **not** need to upload `docker/`, `docker-compose.yml`, `tests/` or `dev/` to the live server. They are blocked from web access even if you do.

## 1. Requirements

| Item | Minimum |
|---|---|
| PHP | 8.1 or newer (8.2/8.3 recommended) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`; recommended `gd`, `zip`, `intl` |
| Database | MySQL 5.7+ / 8.x or MariaDB 10.3+ (InnoDB, utf8mb4) |
| Web server | Apache with `.htaccess` (standard on cPanel), LiteSpeed, or Nginx (see §6) |
| SSL | Required for production (cPanel AutoSSL / Let's Encrypt is free) |

In cPanel, choose the PHP version and extensions under **Select PHP Version** or **MultiPHP Manager**.

## 2. Upload the files

1. Download the package as a ZIP.
2. In cPanel → **File Manager**, open `public_html` (or create a sub-folder such as `public_html/bidding`).
3. Upload the ZIP and click **Extract**.
4. Make sure `app/` and `storage/` (and its sub-folders) are writable by PHP. On most cPanel servers the default `755` for folders and `644` for files is correct.

## 3. Create the database

cPanel → **MySQL® Databases**:

1. Create a database, for example `cpuser_bidding`.
2. Create a user with a strong password, for example `cpuser_bid`.
3. Under **Add User To Database**, grant **ALL PRIVILEGES**. This includes `TRIGGER`, which the optional immutability triggers need.

## 4. Run the web installer

Open `https://your-domain/install.php` (or `/bidding/install.php`). The installer:

- checks the server requirements;
- asks for the site URL, database, SMTP email and Super Admin details;
- imports `install/schema.sql` and tries to install `install/triggers.sql`;
- writes `app/config.php` with a random secret key and cron key;
- creates the Super Admin account.

When it finishes:

1. **Delete `install.php`.** The installer also locks itself.
2. **Add the cron job** shown on the final screen. In cPanel → **Cron Jobs**, set **Once Per Minute**:
   ```
   /usr/local/bin/php /home/CPANELUSER/public_html/cron.php >/dev/null 2>&1
   ```
   The cron job closes bidding on schedule, locks rankings, sends reminders, delivers or retries emails, and runs the daily backup.
   Bidding still closes on time even without cron, because every page request checks server time. Cron is still required for reminders, email retries and backups.
3. **Enable SSL**: cPanel → **SSL/TLS Status** → **Run AutoSSL**. Keep `force_https => true` in `app/config.php`.

### Manual installation (without the web installer)

1. Import `install/schema.sql` in phpMyAdmin.
2. Import `install/triggers.sql`. In phpMyAdmin, set the **Delimiter** field to `$$`. This step is optional but recommended.
3. Copy `app/config.sample.php` to `app/config.php` and edit the values. Generate `secret_key` with `php -r "echo bin2hex(random_bytes(32));"`.
4. Create the first admin over SSH: `php tools/create_admin.php "Your Name" you@company.com super_admin`.
5. Create the file `storage/installed.lock`.

## 5. Hardening checklist (production)

- [ ] `install.php` deleted
- [ ] `app/config.php` has `'env' => 'production'` (or no `env` line). Never `testing` on the live site.
- [ ] HTTPS working and `force_https` set to `true`
- [ ] `debug` set to `false` in `app/config.php`
- [ ] **Move `storage/` outside `public_html`** (for example `/home/CPANELUSER/cityland_storage`) and update `storage_path` in `app/config.php`
- [ ] Immutability triggers installed (Admin → Settings shows "Installed")
- [ ] SMTP tested (Admin → Settings → *Send test email to me*)
- [ ] Every administrator has enabled 2FA, or *Require 2FA for all administrators* is ticked in Settings
- [ ] Terms, Privacy Notice and DPO details reviewed by Cityland Legal/DPO
- [ ] Off-site copies of backups scheduled (see BACKUP_RESTORE.md)

## 6. Nginx (VPS) configuration

Nginx ignores `.htaccess`, so deny the private folders explicitly:

```nginx
server {
    listen 443 ssl http2;
    server_name bidding.example.com;
    root /var/www/bidding;
    index index.php;
    client_max_body_size 64M;

    location ~ ^/(app|storage|install|tools|docs|docker|tests|license-tools|license-portal)(/|$) { deny all; return 404; }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(sql|md|log|lock|gz|zip|yml|yaml|bat)$ { deny all; }
    location = /admin/_init.php { deny all; }

    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Cron on a VPS (`crontab -e` as the web user): `* * * * * php /var/www/bidding/cron.php >/dev/null 2>&1`

## 7. Demo data (testing only)

`php tools/seed_demo.php` creates four sample properties. Do not run it on a live site.

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| "An unexpected error occurred" | See `storage/logs/php-error.log`. Temporarily set `debug => true`. |
| Emails not arriving | Admin → Settings → email log / test email. Check the SMTP host, port and encryption (465 = `ssl`, 587 = `tls`). Add SPF/DKIM for the domain in cPanel → **Email Deliverability**. |
| Uploads fail | Raise `upload_max_filesize` / `post_max_size` in cPanel → **MultiPHP INI Editor**, and check that `storage/` is writable. |
| Times look wrong | `timezone` in config must be `Asia/Manila`. The app sets the DB session time zone to match. |
| Triggers not installed | Ask the host to allow `CREATE TRIGGER`. The app still enforces immutability and hash-chaining without them. |
| Locked out of admin | SSH: `php tools/create_admin.php "Name" email super_admin` resets the password. |
