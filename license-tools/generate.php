<?php
/**
 * Cityland Bidding — LICENSE GENERATOR (vendor only, command line). Never upload to a client server.
 * The browser version is license-generator.html (same keys, same format).
 *
 *   php generate.php keygen  --out=/safe/folder
 *        Create a NEW signing key pair (only for key rotation). Add the printed public key to
 *        app/lib/License.php PUBLIC_KEYS and ship an update before issuing keys with it.
 *
 *   php generate.php issue --key=/safe/folder/cityland-license-signing-key.json \
 *        --licensee="Cityland Development Corporation" --domains=bidding.cityland.com.ph,bid.example.com \
 *        --plan=Professional --expires=2027-12-31 [--issued=2026-09-27] [--max-admins=10] [--max-properties=100] \
 *        [--grace-days=7] [--notes="Annual license"] [--lid=CLB-2026-XXXX]
 *        Prints the license key and records it in issued-licenses.csv next to the key file.
 *
 *   php generate.php inspect "CLB1-…"   [--key=…json]   Decode a license and verify its signature.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("Command line only.\n");
}
if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "The PHP sodium extension is required.\n");
    exit(1);
}
$cmd = $argv[1] ?? 'help';
$o = [];
$pos = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) {
        $o[$m[1]] = $m[2];
    } else {
        $pos[] = $a;
    }
}
$b64u = static fn(string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
$b64ud = static fn(string $s): string|false => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
$loadKey = static function (string $path): array {
    $k = json_decode((string) @file_get_contents($path), true);
    if (!is_array($k) || ($k['type'] ?? '') !== 'cityland-bidding-license-signing-key') {
        fwrite(STDERR, "Cannot read signing key file: {$path}\n");
        exit(1);
    }
    return $k;
};

switch ($cmd) {
    case 'keygen':
        $dir = rtrim($o['out'] ?? '.', '/');
        $file = $dir . '/cityland-license-signing-key.json';
        if (is_file($file)) {
            fwrite(STDERR, "Refusing to overwrite existing {$file}\n");
            exit(1);
        }
        $seed = random_bytes(32);
        $pub = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
        file_put_contents($file, json_encode(['type' => 'cityland-bidding-license-signing-key', 'version' => 1, 'created' => date('c'),
            'key_id' => substr(hash('sha256', $pub), 0, 12), 'seed' => base64_encode($seed), 'public' => $pub,
            'warning' => 'PRIVATE SIGNING KEY. Keep offline and backed up. Anyone with this file can create Cityland Bidding licenses. Never upload it to a server or commit it to Git.'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($file, 0600);
        echo "Created {$file}\nPublic key (add to app/lib/License.php PUBLIC_KEYS):\n{$pub}\n";
        break;

    case 'issue':
        $k = $loadKey($o['key'] ?? '');
        $domains = array_values(array_filter(array_map(static fn($d) => strtolower(trim($d)), preg_split('/[\s,;]+/', $o['domains'] ?? '') ?: [])));
        if (empty($o['licensee']) || !$domains || empty($o['expires']) || !strtotime($o['expires'])) {
            fwrite(STDERR, "Required: --licensee, --domains, --expires=YYYY-MM-DD\n");
            exit(1);
        }
        $payload = [
            'v' => 1, 'product' => 'cityland-bidding',
            'lid' => $o['lid'] ?? ('CLB-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)))),
            'licensee' => $o['licensee'], 'domains' => $domains, 'plan' => $o['plan'] ?? 'Standard',
            'issued' => date('Y-m-d', strtotime($o['issued'] ?? 'today')), 'expires' => date('Y-m-d', strtotime($o['expires'])),
            'grace_days' => (int) ($o['grace-days'] ?? 7),
            'max_admins' => (int) ($o['max-admins'] ?? 0), 'max_properties' => (int) ($o['max-properties'] ?? 0),
            'notes' => $o['notes'] ?? '',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(base64_decode($k['seed'])));
        $license = 'CLB1-' . $b64u($json) . '.' . $b64u(sodium_crypto_sign_detached($json, $sk));
        $reg = dirname($o['key']) . '/issued-licenses.csv';
        $new = !is_file($reg);
        $fh = fopen($reg, 'a');
        if ($new) {
            fputcsv($fh, ['issued_at', 'lid', 'licensee', 'domains', 'plan', 'issued', 'expires', 'grace_days', 'max_admins', 'max_properties', 'notes', 'license_key'], ',', '"', '\\');
        }
        fputcsv($fh, [date('c'), $payload['lid'], $payload['licensee'], implode(' ', $domains), $payload['plan'], $payload['issued'], $payload['expires'],
            $payload['grace_days'], $payload['max_admins'], $payload['max_properties'], $payload['notes'], $license], ',', '"', '\\');
        fclose($fh);
        fwrite(STDERR, "License {$payload['lid']} for {$payload['licensee']} (" . implode(', ', $domains) . "), {$payload['plan']}, expires {$payload['expires']}. Recorded in {$reg}\n\n");
        echo $license, "\n";
        break;

    case 'inspect':
        $key = preg_replace('/\s+/', '', $pos[0] ?? '') ?? '';
        if (!str_starts_with($key, 'CLB1-') || substr_count($key, '.') !== 1) {
            fwrite(STDERR, "Not a CLB1 license key.\n");
            exit(1);
        }
        [$p, $s] = explode('.', substr($key, 5), 2);
        $json = (string) $b64ud($p);
        echo json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        $pubs = [];
        if (!empty($o['key'])) {
            $pubs[] = $loadKey($o['key'])['public'];
        }
        $licFile = dirname(__DIR__) . '/app/lib/License.php';
        if (is_file($licFile) && preg_match_all("/'([A-Za-z0-9+\\/]{43}=)'/", (string) file_get_contents($licFile), $m)) {
            $pubs = array_merge($pubs, $m[1]);
        }
        $ok = false;
        foreach (array_unique($pubs) as $pub) {
            $ok = $ok || sodium_crypto_sign_verify_detached((string) $b64ud($s), $json, base64_decode($pub));
        }
        echo $ok ? "Signature: VALID\n" : "Signature: NOT VALID for the known public key(s)\n";
        exit($ok ? 0 : 2);

    default:
        $doc = (string) file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        echo preg_replace('/^\s*\* ?/m', '', $m[1] ?? ''), "\n";
}
