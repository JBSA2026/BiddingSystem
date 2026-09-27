# Licensing

The Bidding System is licensed per installation with an **offline, signed license key**. It works without calling home to any server.

## How it works

- A license key looks like `CLB1-eyJ2Ijox…​.1tfgsXs8…`. It contains the licensee, licensed domain(s), plan, issue and expiry dates, grace days, plan limits and notes. It is digitally signed (Ed25519) with the vendor's **private signing key**.
- The site contains only the **public key**, in `app/lib/License.php` under `PUBLIC_KEYS`. It can verify keys but cannot create them. Changing any detail, such as the domain, expiry date or limits, breaks the signature.
- The key must match the domain in the site's `app/config.php` → `url`. Wildcards such as `*.cityland.com.ph` cover all subdomains, and `www.` is ignored.

## What happens when

| License state | When | Effect |
|---|---|---|
| **Active** | More than 30 days left | Normal use |
| **Expiring soon** | 30 days or fewer left | Amber banner in the admin portal |
| **Grace period** | Expired, within the grace days (default 7) | Red banner showing the days until lock; admin still usable |
| **Expired** | Past the grace period | **Admin portal locked** to the License page |
| **Missing / invalid** | No key, altered key, another product, or wrong domain | **Admin portal locked** to the License page |

When the admin portal is locked:
- the public site, bidder registration, bidding, scheduled closing, emails and all records **keep working**;
- no data is changed or deleted;
- pasting a valid key unlocks the portal immediately.

In the **TEST environment** (`'env' => 'testing'`), licensing is shown but **not enforced**.

## Plan limits

| Limit | Counted as |
|---|---|
| `max_admins` | Active administrator accounts. Creating or re-activating one beyond the limit is blocked. |
| `max_properties` | Published, non-archived properties that are upcoming, open, closed or under evaluation. Awarded, cancelled, archived and draft properties do not count. Publishing beyond the limit is blocked. |

`0` means unlimited. The generator has these presets:

| Plan | Admins | Active properties |
|---|---|---|
| Standard | 5 | 25 |
| Professional | 15 | 100 |
| Enterprise | unlimited | unlimited |

## Client: installing or renewing a license

1. Sign in as **Super Admin** and open **Admin → License**. If the portal is locked, you are taken there automatically.
2. Paste the key and click **Verify & install license**. An invalid key is never saved.
3. The page shows the licensee, plan, domain, expiry, days left and plan usage. Every install or rejection is recorded in the audit trail.

The server needs the PHP **sodium** extension (cPanel → Select PHP Version → Extensions). The installer checks for it.

## Client: paying online (PayMongo)

When the vendor's renewal portal is set up, **Admin → License** shows a **Pay online & renew** button. The admin-portal
banner shows a **Renew online** link when the license is expiring or in its grace period. The button is always available,
including when the portal is locked, so the client can:
- **renew early**: the new term is added to the current expiry date, so no days are lost;
- **renew after expiry**, or **subscribe after a trial ends**: the new term starts on the payment date;
- **upgrade** to a bigger plan.

Payment is by GCash, Maya, GrabPay, QR Ph or credit/debit card through PayMongo. After paying, click
**Check for my renewed license** on the License page and the new key is downloaded, verified and installed.
The key is also shown on the payment confirmation page and emailed, so it can be pasted instead.

The site uses `RENEW_URL` in `app/lib/License.php`. You can override it per site with `'license' => ['renew_url' => '…']`
in `app/config.php`. When it is empty, the page shows the manual renewal instructions instead.

## Vendor: issuing licenses

The vendor tools are in `license-tools/`. They are **not** included in the cPanel upload ZIP and are blocked from web access.

### Browser generator (recommended)
Open `license-tools/license-generator.html` in Chrome, Edge, Firefox or Safari on **your own computer**.
1. **Signing key**: load `cityland-license-signing-key.json`.
2. **Issue**: enter the licensee, domain(s), plan, expiry (+1, +2 or +3 years, or a 30-day trial), limits, grace days and notes, then click **Generate**. Copy the key or download it as a `.txt` file to send to the client.
3. **Check**: paste any key to verify it and see its contents.
4. **Register**: every key you issue is listed with its status (Active, days left, Expired). Use **Renew** to pre-fill a renewal, and **Export CSV** or **Import CSV** to back up the register.

### Command line
```
php license-tools/generate.php issue --key=/secure/cityland-license-signing-key.json \
    --licensee="Cityland Development Corporation" --domains=bidding.cityland.com.ph \
    --plan=Professional --expires=2027-12-31 --max-admins=15 --max-properties=100
php license-tools/generate.php inspect "CLB1-…"
```
Each issued key is appended to `issued-licenses.csv` next to the key file.

### Online renewal portal (PayMongo)
`license-portal/` is a small PHP app that you host on your own domain. It sells renewals, upgrades and new licenses
through PayMongo Checkout and can issue signed keys automatically. For setup, see [license-portal/README.md](../license-portal/README.md).

## Protecting the signing key

- Store `cityland-license-signing-key.json` **offline**, for example on an encrypted USB drive or in a password manager, and keep **two backups**.
- **Never** upload it to a client server, email it, or commit it to Git. `.gitignore` blocks `*signing-key*.json`, and this repository is public.
- If it is lost or leaked, create a new key (generator → *Key rotation*, or `generate.php keygen`) and add the new public key to `PUBLIC_KEYS`. Ship that update to clients, then reissue their licenses. Remove the old public key only after every client has a new license.

## Limitations

This is a standard licensing scheme for self-hosted PHP. It stops forged, edited or copied licenses and use on unlicensed domains. However, someone with full server access and PHP skills could modify the code itself. For stronger protection, rely on your contract or license agreement, and optionally encode `app/lib/License.php` and `app/lib/Auth.php` with a PHP encoder such as ionCube before delivery.
