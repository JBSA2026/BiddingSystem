<?php
/**
 * License renewal portal — configuration. Copy to config.php and fill in.
 * config.php holds SECRETS (PayMongo secret key). It is blocked from the web by .htaccess.
 */
return [
    // Public address of this portal (no trailing slash). Put the same URL in the client sites'
    // app/lib/License.php  RENEW_URL  (or their app/config.php  'license' => ['renew_url' => …]).
    'portal_url' => 'https://license.yourdomain.com',

    'vendor_name' => 'Your Company — Software Licensing',
    'vendor_email' => 'billing@yourdomain.com',   // receives a copy of every paid order
    'mail_from' => 'Licensing <no-reply@yourdomain.com>',

    // Where orders are stored (SQLite). Best OUTSIDE public_html, e.g. /home/USER/license-portal-data
    'data_dir' => __DIR__ . '/data',

    // Automatic issuing: path to cityland-license-signing-key.json, stored OUTSIDE public_html.
    // Leave '' to issue keys by hand: you are emailed when an order is paid.
    'signing_key_file' => '',

    // Extra trusted public keys (older keys after a key rotation). The signing key's own public key is added automatically.
    'public_keys' => ['+taBcfFKa3Uk6PGZzBxSwyPa5UupdbMpVwa57ifs5X0='],

    'paymongo' => [
        // Dashboard → Developers → API keys. Use sk_test_… first, then sk_live_… when going live.
        'secret_key' => 'sk_test_REPLACE_ME',
        // Dashboard → Developers → Webhooks → add  <portal_url>/webhook.php  for event checkout_session.payment.paid
        'webhook_secret' => 'whsk_REPLACE_ME',
        'payment_methods' => ['card', 'gcash', 'paymaya', 'grab_pay', 'qrph'],
    ],

    // Price per YEAR in pesos (VAT-inclusive) and the plan limits written into the key. 0 = unlimited.
    'plans' => [
        'Standard'     => ['price' => 30000, 'max_admins' => 5,  'max_properties' => 25, 'label' => 'Up to 5 admins · 25 active properties'],
        'Professional' => ['price' => 60000, 'max_admins' => 15, 'max_properties' => 100, 'label' => 'Up to 15 admins · 100 active properties'],
        'Enterprise'   => ['price' => 120000, 'max_admins' => 0, 'max_properties' => 0, 'label' => 'Unlimited admins and properties'],
    ],
    // Terms offered (years) => discount %.
    'term_discounts' => [1 => 0, 2 => 5, 3 => 10],
    'grace_days' => 7,
];
