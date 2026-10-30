<?php
declare(strict_types=1);

final class Campaign
{
    public static function settings(array $brief, array $product): array
    {
        // Brand and headline are PHP overlays; naming them here can put invented lettering in the photo.
        $prompt = "Premium editorial photograph of unwrapped, unbranded chocolate. Product: {$product['name']}. "
            . "Description: {$product['description']} Audience: {$brief['audience']} Market: {$brief['market']}. "
            . "Art direction: {$brief['visual_direction']} Square, edge-to-edge photograph. "
            . 'The upper 35 percent is a quiet, pale ivory wall with empty space, brightest at the upper left. '
            . 'The chocolate fills the lower 65 percent: a sharply focused product hero near the lower center, '
            . 'with a few intact pieces and soft foreground depth. Warm afternoon window light, realistic appetizing texture. '
            . 'Keep the main hero fully visible within the central 60 percent of the width for vertical cropping. '
            . 'No borders, graphic panels, packaging, text, letters, logos, labels, stamps, or engravings.';
        return ['prompt' => $prompt, 'steps' => 4];
    }

    public static function cacheKey(array $settings): string
    {
        return hash('sha256', json_encode(['provider' => 'cloudflare_workers_ai', 'model' => CloudflareClient::MODEL,
            'settings' => $settings], JSON_THROW_ON_ERROR));
    }

    public static function cacheGenerated(array $settings, array $result): array
    {
        $key = self::cacheKey($settings);
        $directory = STORAGE_ROOT . '/cache/' . $key;
        Storage::directory($directory);
        $asset = ['origin' => 'cloudflare_workers_ai', 'model' => CloudflareClient::MODEL,
            'request_settings' => $settings, 'cache_key' => $key, 'created_at' => gmdate(DATE_ATOM),
            'path' => 'source.' . $result['info']['extension']] + $result['info'];
        Storage::write($directory . '/' . $asset['path'], $result['image']);
        Storage::json($directory . '/source.json', $asset);
        return $asset;
    }

    private static function cached(array $settings): ?array
    {
        $key = self::cacheKey($settings);
        $directory = STORAGE_ROOT . '/cache/' . $key;
        if (!is_file($directory . '/source.json')) {
            return null;
        }
        try {
            $asset = json_decode(file_get_contents($directory . '/source.json'), true, 32, JSON_THROW_ON_ERROR);
            if (($asset['cache_key'] ?? '') !== $key || ($asset['origin'] ?? '') !== 'cloudflare_workers_ai'
                || ($asset['model'] ?? '') !== CloudflareClient::MODEL
                || ($asset['retained_for_settings'] ?? $asset['request_settings'] ?? []) !== $settings
                || !preg_match('/^source\.(jpg|png|webp)$/D', $asset['path'] ?? '')) {
                return null;
            }
            $file = $directory . '/' . $asset['path'];
            if (!is_file($file) || !hash_equals($asset['sha256'], hash_file('sha256', $file))) {
                return null;
            }
            Input::inspectImage(file_get_contents($file), 10 * 1024 * 1024);
            return $asset;
        } catch (Throwable) {
            return null; // A damaged cache entry is never treated as usable imagery.
        }
    }

    /** Explicitly retain a chosen photograph without rewriting its original generation record. */
    public static function retainCached(array $settings, string $sourceCacheKey): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sourceCacheKey)) {
            throw new InvalidArgumentException('Invalid source cache key.');
        }
        $source = STORAGE_ROOT . '/cache/' . $sourceCacheKey;
        if (!is_file($source . '/source.json')) {
            throw new RuntimeException('The source to retain is missing from the cache.');
        }
        $original = json_decode(file_get_contents($source . '/source.json'), true, 32, JSON_THROW_ON_ERROR);
        $asset = self::cached($original['retained_for_settings'] ?? $original['request_settings']);
        if ($asset === null || $asset['cache_key'] !== $sourceCacheKey) {
            throw new RuntimeException('The retained source failed cache validation.');
        }
        $key = self::cacheKey($settings);
        if ($key === $sourceCacheKey) {
            return;
        }
        $asset['retained_from_cache_key'] = $sourceCacheKey;
        $asset['retained_for_settings'] = $settings;
        $asset['retained_at'] = gmdate(DATE_ATOM);
        $asset['cache_key'] = $key;
        Storage::directory(STORAGE_ROOT . '/cache/' . $key);
        Storage::write(STORAGE_ROOT . '/cache/' . $key . '/' . $asset['path'], file_get_contents($source . '/' . $asset['path']));
        Storage::json(STORAGE_ROOT . '/cache/' . $key . '/source.json', $asset);
    }

    private static function copySource(string $source, array $asset, string $directory, string $reuse): array
    {
        $path = 'sources/' . bin2hex(random_bytes(16)) . '.' . $asset['extension'];
        $bytes = file_get_contents($source);
        if ($bytes === false || !hash_equals($asset['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('The saved source changed or is unavailable. Supply the image again.');
        }
        Storage::write($directory . '/' . $path, $bytes);
        return array_replace($asset, ['path' => $path, 'reuse' => $reuse]);
    }

    private static function saveBrief(array $brief, array $uploads): array
    {
        $started = microtime(true);
        $run = bin2hex(random_bytes(16));
        $directory = STORAGE_ROOT . '/runs/' . $run;
        Storage::directory($directory . '/sources');
        try {
            foreach ($brief['products'] as $i => &$product) {
                $upload = $uploads[$i] ?? null;
                if ($upload === null) {
                    $product['asset'] = ['origin' => 'needs_generation', 'path' => null];
                    continue;
                }
                $name = bin2hex(random_bytes(16)) . '.' . $upload['info']['extension'];
                if (!move_uploaded_file($upload['tmp_name'], $directory . '/sources/' . $name)) {
                    throw new RuntimeException('Unable to save the uploaded image.');
                }
                chmod($directory . '/sources/' . $name, 0600);
                $product['asset'] = ['origin' => 'user_upload', 'path' => 'sources/' . $name] + $upload['info'];
            }
            unset($product);
            $brief = ['schema_version' => 1, 'run_id' => $run, 'created_at' => gmdate(DATE_ATOM)] + $brief;
            Storage::json($directory . '/brief.json', $brief);
            Storage::json($directory . '/manifest.json', [
                'schema_version' => 1, 'run_id' => $run, 'status' => 'processing',
                'created_at' => $brief['created_at'], 'inputs' => 'brief.json',
                'assets' => array_column($brief['products'], 'asset', 'id'),
                'outputs' => [], 'errors' => [], 'generation_requests' => 0,
                'compliance' => [
                    'content' => ['status' => 'passed', 'prohibited_phrases' => Input::PROHIBITED_PHRASES,
                        'scope' => 'Brief text only; no image/OCR or legal review.'],
                    'brand' => ['status' => 'not_checked', 'checked_outputs' => 0, 'expected_outputs' => 6,
                        'scope' => 'PHP wordmark, cocoa/gold colors and text bounds; photograph not assessed.'],
                ],
                'timings_ms' => ['save' => round((microtime(true) - $started) * 1000, 2)],
            ]);
            return $brief;
        } catch (Throwable $e) {
            // Only remove the newly allocated run if its transaction could not be completed.
            foreach (glob($directory . '/sources/*') ?: [] as $file) { unlink($file); }
            foreach (glob($directory . '/*.json') ?: [] as $file) { unlink($file); }
            rmdir($directory . '/sources');
            rmdir($directory);
            throw $e;
        }
    }

    private static function plans(array $brief): array
    {
        if ($contentErrors = Input::contentErrors($brief)) {
            throw new InvalidArgumentException(implode(' ', $contentErrors));
        }
        $plans = [];
        foreach ($brief['products'] as $product) {
            // Reject copy that cannot fit, or an oversized prompt, before making a provider request.
            foreach (Renderer::SIZES as $ratio => $_) {
                $plans[$product['id']][$ratio] = Renderer::plan($brief, $product, $ratio);
            }
            if (strlen(self::settings($brief, $product)['prompt']) > 2048) {
                throw new InvalidArgumentException('Product, audience, and visual direction make the hero prompt too long. Shorten these fields.');
            }
        }
        return $plans;
    }

    /** Trusted packaged or authorized saved sources only. This path never calls the provider. */
    public static function fromSources(array $input, string $sourceRoot, array $assets, ?string $revisionOf = null): array
    {
        $started = microtime(true);
        $validated = Input::validateBrief($input, []);
        if ($validated['errors']) {
            throw new InvalidArgumentException(implode(' ', $validated['errors']));
        }
        $plans = self::plans($validated['brief']);
        $brief = self::saveBrief($validated['brief'], []);
        $directory = STORAGE_ROOT . '/runs/' . $brief['run_id'];
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($revisionOf !== null) {
            $manifest['revision_of'] = $revisionOf;
        }
        foreach ($brief['products'] as &$product) {
            try {
                $asset = $assets[$product['id']] ?? [];
                if (!is_string($asset['path'] ?? null)
                    || !preg_match('~^sources/[a-z0-9-]+\.(jpg|png|webp)$~D', $asset['path'])) {
                    throw new RuntimeException('No saved source is available. Supply an image and generate the campaign again.');
                }
                $product['asset'] = self::copySource($sourceRoot . '/' . $asset['path'], $asset, $directory,
                    $revisionOf === null ? 'example' : 'previous_run');
            } catch (RuntimeException $e) {
                $manifest['errors'][$product['id']] = $e->getMessage();
            }
        }
        unset($product);
        return self::render($brief, $manifest, $plans, $started);
    }

    /** The HTTP caller validates fields/uploads and authorizes previous-run access before calling. */
    public static function generate(array $brief, array $uploads, ?array $previous = null, ?CloudflareClient $client = null): array
    {
        $started = microtime(true);
        $plans = self::plans($brief);
        $brief = self::saveBrief($brief, $uploads);
        $directory = STORAGE_ROOT . '/runs/' . $brief['run_id'];
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $requests = [];
        foreach ($brief['products'] as &$product) {
            $id = $product['id'];
            if ($product['asset']['origin'] === 'user_upload') {
                $product['asset']['reuse'] = 'uploaded';
                continue;
            }
            try {
                foreach ($previous['products'] ?? [] as $old) {
                    if ($old['id'] === $id && $old['name'] === $product['name'] && $old['description'] === $product['description']
                        && in_array($old['asset']['origin'] ?? '', ['user_upload', 'example'], true)
                        && preg_match('~^sources/[a-f0-9]{32}\.(jpg|png|webp)$~D', $old['asset']['path'])) {
                        $product['asset'] = self::copySource(STORAGE_ROOT . '/runs/' . $previous['run_id'] . '/' . $old['asset']['path'],
                            $old['asset'], $directory, 'previous_run');
                        continue 2;
                    }
                }
                $settings = self::settings($brief, $product);
                $asset = self::cached($settings);
                if ($asset !== null) {
                    $product['asset'] = self::copySource(STORAGE_ROOT . '/cache/' . $asset['cache_key'] . '/' . $asset['path'],
                        $asset, $directory, 'cache');
                } else {
                    $requests[$id] = $settings;
                }
            } catch (RuntimeException $e) {
                $manifest['errors'][$id] = $e->getMessage();
            }
        }
        unset($product);

        if ($requests) {
            try {
                if ($client === null) {
                    $credentials = Config::credentials();
                    if ($credentials === null) {
                        throw new RuntimeException('Cloudflare credentials are missing. Set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN in the private root .env, or supply an image.');
                    }
                    $client = new CloudflareClient($credentials['account'], $credentials['token']);
                }
                $manifest['generation_requests'] = count($requests);
                $batch = $client->generate($requests);
                $manifest['timings_ms']['generation'] = $batch['elapsed_ms'];
                foreach ($brief['products'] as &$product) {
                    $id = $product['id'];
                    if (!isset($batch['results'][$id])) {
                        continue;
                    }
                    $result = $batch['results'][$id];
                    $manifest['transfers'][$id] = ['http_status' => $result['http_status'], 'elapsed_ms' => $result['elapsed_ms']];
                    if (!$result['success']) {
                        $manifest['errors'][$id] = $result['error'];
                        continue;
                    }
                    // Save the source before rendering so a layout failure cannot lose the API result.
                    $name = 'sources/' . bin2hex(random_bytes(16)) . '.' . $result['info']['extension'];
                    Storage::write($directory . '/' . $name, $result['image']);
                    $product['asset'] = ['origin' => 'cloudflare_workers_ai', 'reuse' => 'generated', 'path' => $name,
                        'model' => CloudflareClient::MODEL, 'request_settings' => $requests[$id],
                        'cache_key' => self::cacheKey($requests[$id])] + $result['info'];
                    try {
                        self::cacheGenerated($requests[$id], $result);
                    } catch (Throwable) {
                        $manifest['errors'][$id . ':cache'] = 'Source was saved, but its cache could not be written. Check storage permissions.';
                    }
                }
                unset($product);
            } catch (RuntimeException | InvalidArgumentException $e) {
                foreach ($requests as $id => $_) {
                    $manifest['errors'][$id] ??= $e->getMessage();
                }
            } finally {
                unset($product);
            }
        }
        return self::render($brief, $manifest, $plans, $started);
    }

    private static function render(array $brief, array $manifest, array $plans, float $started): array
    {
        $directory = STORAGE_ROOT . '/runs/' . $brief['run_id'];
        Storage::json($directory . '/brief.json', $brief);
        $manifest['assets'] = array_column($brief['products'], 'asset', 'id');
        Storage::json($directory . '/manifest.json', $manifest);

        foreach ($brief['products'] as $product) {
            if ($product['asset']['path'] === null) {
                continue;
            }
            foreach ($plans[$product['id']] as $ratio => $plan) {
                try {
                    $path = 'products/' . $product['id'] . '/' . $ratio . '.png';
                    Storage::directory(dirname($directory . '/' . $path));
                    $output = Renderer::render($directory . '/' . $product['asset']['path'], $directory . '/' . $path, $plan);
                    $manifest['outputs'][] = ['product_id' => $product['id'], 'product_name' => $product['name'],
                        'ratio' => $ratio, 'path' => $path] + $output;
                    if ($output['brand_compliance']['status'] === 'failed') {
                        $manifest['errors'][$product['id'] . ':' . $ratio] = 'Brand checks failed for ' . $product['name']
                            . ' (' . $ratio . '). Review its wordmark, colors and text placement before use.';
                    }
                } catch (Throwable) {
                    $manifest['errors'][$product['id'] . ':' . $ratio] = 'Could not render ' . $product['name'] . ' (' . $ratio . '). Check its source, fonts, and writable storage.';
                }
                Storage::json($directory . '/manifest.json', $manifest);
            }
        }
        $brandStatuses = array_column(array_column($manifest['outputs'], 'brand_compliance'), 'status');
        $manifest['compliance']['brand']['checked_outputs'] = count($brandStatuses);
        $manifest['compliance']['brand']['status'] = in_array('failed', $brandStatuses, true) ? 'failed'
            : (count($brandStatuses) === 6 ? 'passed' : ($brandStatuses ? 'incomplete' : 'not_checked'));
        $manifest['status'] = count($manifest['outputs']) === 6 && !$manifest['errors'] ? 'complete' : 'incomplete';
        $manifest['timings_ms']['total'] = round((microtime(true) - $started) * 1000, 2);
        Storage::json($directory . '/manifest.json', $manifest);
        return ['status' => $manifest['status'], 'brief' => $brief, 'manifest' => $manifest,
            'download_url' => '/brief?run=' . $brief['run_id']];
    }
}
