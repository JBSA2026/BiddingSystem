# License renewal portal (vendor-hosted, PayMongo)

Lets client admins **pay online** to renew early, renew after expiry, or subscribe after a trial, and receive
their new license key automatically. Deploy this folder on **your own** hosting, never on a client server.
It is not included in the client cPanel ZIP.

```
Client Admin → License → "Pay online & renew" ──► this portal (plan, term, email)
      ──► PayMongo Checkout (GCash, Maya, GrabPay, QR Ph, card) ──► success.php / webhook.php
      ──► payment re-confirmed with PayMongo's API ──► signed key issued (same License ID)
Client Admin → License → "Check for my renewed license" ──► api.php ──► key verified & installed
```

## Requirements
PHP 8.1+ with **curl**, **pdo_sqlite** and **sodium** (all standard on cPanel; tick them in *Select PHP Version*), HTTPS.

## Setup
1. Create a subdomain such as `license.yourdomain.com` and upload this folder's files into it.
2. Copy `config.sample.php` to `config.php` and fill it in:
   - `portal_url`: the portal's address, e.g. `https://license.yourdomain.com`
   - `vendor_name`, `vendor_email`, `mail_from`
   - `data_dir`: preferably outside `public_html`, e.g. `/home/USER/license-portal-data`
   - `plans` prices (pesos per year) and limits, `term_discounts`
   - `paymongo.secret_key`: PayMongo Dashboard → Developers → API keys (`sk_test_…` first)
3. PayMongo Dashboard → Developers → **Webhooks** → add `https://license.yourdomain.com/webhook.php`
   for the event **checkout_session.payment.paid**. Copy its secret (`whsk_…`) into `paymongo.webhook_secret`.
4. Choose how keys are issued:
   - **Automatic (instant):** upload `cityland-license-signing-key.json` to a folder **outside `public_html`**
     (e.g. `/home/USER/keys/`, permissions 600) and set `signing_key_file` to its path.
     Anyone who gets full access to this hosting account could then issue licenses, so protect it with a strong
     cPanel password and 2FA.
   - **Manual:** leave `signing_key_file` empty. When an order is paid, you get an email with the details;
     issue the key with the license generator and email it to the customer.
5. Point client sites at the portal: set `RENEW_URL` in `app/lib/License.php` before building the client ZIP,
   or on an existing site add `'license' => ['renew_url' => 'https://license.yourdomain.com']` to `app/config.php`.
6. Test with PayMongo **test** keys and test cards/e-wallets, then switch to `sk_live_…` and a live webhook.

## How renewals are priced and dated
- The price is the plan's yearly price × years − the term discount.
- **Early renewal**: the new term is added to the current expiry date, so no days are lost.
- **After expiry, or from a Trial plan**: the new term starts on the payment date.
- The License ID, licensee and domains are copied from the customer's current (signature-checked) key.
  Expired keys are accepted for renewal. Changing plan takes effect in the new key's limits.
- A key is issued only after PayMongo's API confirms a payment of at least the order amount.

## Files
| File | Purpose |
|---|---|
| `index.php` | Plan/term/email form, creates the PayMongo Checkout Session |
| `success.php` | Return page: confirms payment with PayMongo, shows the key |
| `webhook.php` | PayMongo webhook (signature + replay check, then API re-confirmation) |
| `api.php` | Lets the client's License page download its newest paid key |
| `lib.php`, `config.php` | Code and secrets (blocked from the web by `.htaccess`) |
| `data/` | SQLite order database and `portal.log` (blocked from the web) |

Tests: `sh tests/portal/run.sh` runs the whole flow against a mock PayMongo API.
