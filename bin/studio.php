<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$command = $argv[1] ?? '';
if ($command === 'check') {
    echo 'PHP ' . PHP_VERSION . PHP_EOL;
    $checks = Config::prerequisites();
    foreach ($checks as $name => $present) {
        echo ($present ? 'PASS ' : 'MISSING ') . $name . PHP_EOL;
    }
    echo 'Cloudflare credentials: ' . (Config::credentials() === null ? 'missing or placeholders (live smoke test will skip)' : 'configured (not verified)') . PHP_EOL;
    exit(in_array(false, $checks, true) ? 1 : 0);
}
if ($command !== 'smoke') {
    fwrite(STDERR, "Usage: php bin/studio.php check | smoke --confirm-free-plan\n");
    exit(2);
}

$credentials = Config::credentials();
if ($credentials === null) {
    fwrite(STDOUT, "NOT RUN: Cloudflare credentials are missing. Configure the private root .env as described in README.md. No API request was sent.\n");
    exit(2);
}
if (in_array(false, Config::prerequisites(), true)) {
    fwrite(STDERR, "Missing prerequisites. Run php bin/studio.php check.\n");
    exit(1);
}
// Billing cannot be checked here; the flag confirms the user checked the Free plan.
if (!in_array('--confirm-free-plan', $argv, true)) {
    fwrite(STDERR, "NOT RUN: Confirm in Cloudflare that this account uses Workers Free, then run php bin/studio.php smoke --confirm-free-plan. This flag is your assertion, not an automatic billing check.\n");
    exit(2);
}

$directory = null;
try {
    $client = new CloudflareClient($credentials['account'], $credentials['token']);
    $brief = Input::sampleBrief();
    $product = $brief['products'][1];
    $settings = Campaign::settings($brief, $product);
    $directory = STORAGE_ROOT . '/smoke/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    Storage::directory($directory);
    $manifest = [
        'schema_version' => 1, 'kind' => 'single_image_smoke_test', 'created_at' => gmdate(DATE_ATOM),
        'status' => 'started', 'origin' => 'cloudflare_workers_ai', 'model' => CloudflareClient::MODEL,
        'inputs' => $brief, 'product_id' => $product['id'], 'request_settings' => $settings,
        'request_limit' => 1, 'billing_assertion' => 'User confirmed Workers Free',
        'outputs' => [], 'errors' => [],
    ];
    Storage::json($directory . '/manifest.json', $manifest);
    echo "Requesting one hazelnut-truffle campaign hero from Cloudflare. This can take up to 90 seconds.\n";
    $batch = $client->generate([$product['id'] => $settings]);
    $result = $batch['results'][$product['id']];
    $manifest['status'] = $batch['status'];
    $manifest['elapsed_ms'] = $batch['elapsed_ms'];
    $manifest['http_status'] = $result['http_status'];
    if ($result['success']) {
        $name = 'hero-' . bin2hex(random_bytes(8)) . '.' . $result['info']['extension'];
        Storage::write($directory . '/' . $name, $result['image']);
        $cached = Campaign::cacheGenerated($settings, $result);
        $manifest['cache_key'] = $cached['cache_key'];
        $manifest['outputs'][] = ['path' => $name, 'origin' => 'cloudflare_workers_ai', 'needs_visual_review' => true] + $result['info'];
        Storage::json($directory . '/manifest.json', $manifest);
        echo 'GENERATED: ' . $directory . '/' . $name . "\nPrivate source hero only; not a final creative. Inspect it before reuse.\n";
    } else {
        $manifest['errors'][] = $result['error'];
        Storage::json($directory . '/manifest.json', $manifest);
        fwrite(STDERR, 'FAILED: ' . $result['error'] . PHP_EOL);
    }
    echo 'Manifest: ' . $directory . '/manifest.json' . PHP_EOL;
    exit($result['success'] ? 0 : 1);
} catch (Throwable) {
    if ($directory !== null && isset($manifest)) {
        $manifest['status'] = 'incomplete';
        $manifest['errors'][] = 'Smoke test did not complete. Check credentials, local storage permissions, and prerequisites.';
        try { Storage::json($directory . '/manifest.json', $manifest); } catch (Throwable) { /* preserve existing evidence */ }
    }
    fwrite(STDERR, "FAILED: Smoke test did not complete. Check credentials, private storage permissions, and prerequisites.\n");
    exit(1);
}
