# Backup & Restore Procedure

## What must be backed up
1. **The database** holds properties, bidders, bids, rankings, awards, consents, payments and the audit trail.
2. **Files** in `storage/uploads/` hold property photos and documents, bidder documents, award documents and payment proofs.
3. **`app/config.php`**. Keep a copy in a password manager or vault; it contains secrets.

## Automatic backups
`cron.php` creates a daily backup after 02:00 server time:
- `storage/backups/db-YYYYMMDD-HHMMSS.sql.gz`, a full SQL dump that includes the triggers;
- `storage/backups/files-YYYYMMDD-HHMMSS.zip`, all uploads.

The 14 newest sets are kept. Failures are emailed to administrators.

## Manual backup
- **Admin → Backup → Create backup now**, then **Download** each file.
- Or cPanel → **Backup** → *Download a MySQL Database Backup*, and use **File Manager** to compress `storage/uploads`.
- Or over SSH: `mysqldump --single-transaction --routines --triggers -u USER -p DBNAME | gzip > db.sql.gz`

## Off-site copies (recommended)
Download backups weekly and keep them encrypted at a separate, access-controlled location, or configure cPanel **JetBackup** or remote backups to
another server or cloud storage. Backups contain personal data, so apply the same protection as the live system (Data Privacy Act).

## Restore
1. Put the site in maintenance, for example by blocking public access with an IP allow-list in `.htaccess`.
2. Take a fresh backup of the current state, even if it is broken.
3. Restore the database:
   - **phpMyAdmin**: select the database → **Import** → choose `db-….sql.gz` → Go.
     If the import stops on the trigger section, import it again with the phpMyAdmin *Delimiter* field set to `$$`.
   - **SSH**: `gunzip < db-….sql.gz | mysql -u USER -p DBNAME`
4. Restore files: extract `files-….zip` so that the `uploads/` folder lands inside your storage path.
5. Check that `app/config.php` points to the correct database and still has the **same `secret_key`**.
6. Sign in as Super Admin, then:
   - **Audit Trail → Verify integrity** should report all records intact;
   - open a property's **Bids & evaluation** and confirm the bid chain shows **Verified**.
7. Re-enable public access.

## Restore test
Run a restore into a separate test database at least quarterly to prove the backups are usable, and record the result.
