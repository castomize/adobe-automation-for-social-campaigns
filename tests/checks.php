<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$passed = 0;
function check(bool $condition, string $description): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $description);
    }
    $passed++;
    echo 'PASS ' . $description . PHP_EOL;
}
function rejects(callable $action): bool
{
    try { $action(); } catch (InvalidArgumentException | RuntimeException) { return true; }
    return false;
}

function testClientAndValidation(string $endpoint): void
{
    global $passed;
    $sample = Input::sampleBrief();
    check(Input::validateBrief($sample, [])['errors'] === [], 'sample brief accepts intentionally absent images');
    $prohibited = $sample;
    $prohibited['campaign_message'] = "GUARANTEED—weight\nloss!";
    $prohibited['products'][1]['description'] = 'A miracle treat that cures everything.';
    $validation = Input::validateBrief($prohibited, []);
    check(isset($validation['errors']['campaign_message'], $validation['errors']['products.1.description'])
        && str_contains($validation['errors']['campaign_message'], 'guaranteed weight loss')
        && str_contains($validation['errors']['products.1.description'], 'miracle')
        && str_contains($validation['errors']['products.1.description'], 'cures'),
        'content rules flag specific fields despite case, punctuation and whitespace changes');
    $allowed = $sample;
    $allowed['campaign_message'] = 'Secure a better afternoon.';
    check(Input::validateBrief($allowed, [])['errors'] === []
        && Input::validateBrief($allowed, [])['brief']['campaign_message'] === $allowed['campaign_message'],
        'whole-word matching permits secure and leaves accepted copy unchanged');
    $prohibited['campaign_message'] = str_repeat('cure ', 40);
    check(str_contains(Input::validateBrief($prohibited, [])['errors']['campaign_message'], '180 UTF-8 bytes'),
        'content findings do not replace existing format errors');
    check(!str_contains(Campaign::settings($sample, $sample['products'][0])['prompt'], 'Melt & Muse'), 'brand wordmark stays out of source generation and is rendered by PHP');
    $changed = $sample;
    $changed['campaign_message'] = 'A new headline, unchanged hero.';
    check(Campaign::settings($changed, $changed['products'][1])['prompt'] === Campaign::settings($sample, $sample['products'][1])['prompt'], 'headline is separate from the hero prompt');
    $changed['products'][1]['description'] = 'White chocolate with hazelnuts.';
    check(Campaign::settings($changed, $changed['products'][1])['prompt'] !== Campaign::settings($sample, $sample['products'][1])['prompt'], 'product description changes the hero prompt');
    $changed = $sample;
    $changed['visual_direction'] = 'A mint tabletop in afternoon sun.';
    check(Campaign::settings($changed, $changed['products'][1])['prompt'] !== Campaign::settings($sample, $sample['products'][1])['prompt'], 'visual direction changes the hero prompt');
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $error) {
        check(rejects(fn () => Input::validateUpload(['error' => $error])), "upload error $error is rejected instead of becoming generation");
    }
    check(Input::validateUpload(['error' => UPLOAD_ERR_NO_FILE]) === null, 'explicit no-file upload is intentional generation');
    check(rejects(fn () => Input::validateUpload(['error' => [0], 'tmp_name' => ['bad']])), 'malformed upload is rejected');
    check(rejects(fn () => Input::inspectImage('not an image')), 'invalid image bytes are rejected');

    // Dummy configuration only, in the test runner's temporary directory. Never print values.
    $envFile = tempnam(sys_get_temp_dir(), 'melt-env-');
    try {
        putenv('CLOUDFLARE_ACCOUNT_ID');
        putenv('CLOUDFLARE_API_TOKEN');
        file_put_contents($envFile, "# Literal test values\nCLOUDFLARE_ACCOUNT_ID='" . str_repeat('0', 32) . "'\nCLOUDFLARE_API_TOKEN=test-token\n");
        Config::loadEnv($envFile);
        check(Config::credentials() === ['account' => str_repeat('0', 32), 'token' => 'test-token'], 'documented private dotenv loading works');
        putenv('CLOUDFLARE_API_TOKEN=environment-wins');
        Config::loadEnv($envFile);
        check(getenv('CLOUDFLARE_API_TOKEN') === 'environment-wins', 'process environment takes precedence over dotenv');
        file_put_contents($envFile, 'UNEXPECTED_KEY=literal');
        check(rejects(fn () => Config::loadEnv($envFile)), 'unsupported dotenv syntax fails without executing anything');
    } finally {
        unlink($envFile);
    }

    $client = new CloudflareClient(str_repeat('0', 32), 'test-token', $endpoint, 3);
    $request = static fn (string $prompt) => ['prompt' => $prompt, 'steps' => 4];
    check(rejects(fn () => $client->generate([])), 'zero-request batch rejected');
    check(rejects(fn () => $client->generate(['a' => $request('never'), 'b' => $request('never'), 'c' => $request('never')])), 'three-request batch rejected before networking');
    check(rejects(fn () => $client->generate(['a' => $request('never') + ['width' => 1024]])), 'unsupported provider settings rejected before networking');
    check(rejects(fn () => $client->generate(['a' => ['prompt' => 'never', 'steps' => 9]])), 'steps above model limit rejected');
    check(rejects(fn () => $client->generate(['a' => $request(str_repeat('x', 2049))])), 'oversized prompt rejected');
    check(rejects(fn () => new CloudflareClient(str_repeat('0', 32), 'real-credential-placeholder', $endpoint)), 'real credentials cannot use a fixture endpoint');

    $batch = $client->generate(['good' => $request('overlap-success'), 'bad' => $request('overlap-failure')]);
    check($batch['status'] === 'incomplete', 'mixed batch is marked incomplete');
    check($batch['results']['good']['success'] && strlen($batch['results']['good']['image']) > 0, 'successful image survives sibling failure');
    check(!$batch['results']['bad']['success'] && $batch['results']['bad']['http_status'] === 429, 'per-request HTTP failure is recorded');
    check($batch['results']['good']['info']['width'] === 256, 'successful response is decoded and measured');
    foreach (['invalid-json', 'provider-error', 'missing-image', 'bad-base64', 'non-image', 'corrupt-image', 'tiny-image', 'oversized-dimensions', 'pixel-limit', 'huge-response', 'redirect'] as $mode) {
        $batch = $client->generate(['test' => $request($mode)]);
        check($batch['status'] === 'incomplete' && !$batch['results']['test']['success'], "controlled $mode response rejected");
        check(!str_contains(json_encode($batch), 'test-token'), "$mode result does not expose the token");
    }
    $pair = $client->generate(['a' => $request('success'), 'b' => $request('success')]);
    check($pair['status'] === 'complete' && count($pair['results']) === 2, 'two successful transfers return two images');
    $timeoutClient = new CloudflareClient(str_repeat('0', 32), 'test-token', $endpoint, 1);
    $timed = $timeoutClient->generate(['slow' => $request('timeout'), 'fast' => $request('success')]);
    check($timed['status'] === 'incomplete' && $timed['results']['fast']['success'] && !$timed['results']['slow']['success'], 'timeout preserves its successful sibling');
    check(str_contains($timed['results']['slow']['error'], '28'), 'bounded transfer timeout is reported');
    echo "$passed PHP checks passed. Controlled fixtures are not a live Cloudflare test.\n";
}

function testCampaign(string $endpoint, string $previousRun): void
{
    global $passed;
    // Run only from tests/run.py's temporary app. The previous source was uploaded over real HTTP.
    $client = new CloudflareClient(str_repeat('0', 32), 'test-token', $endpoint, 3);
    $previous = json_decode(file_get_contents(STORAGE_ROOT . '/runs/' . $previousRun . '/brief.json'), true, 32, JSON_THROW_ON_ERROR);
    $brief = Input::sampleBrief();
    $mixed = Campaign::generate($brief, [], $previous, $client);
    check($mixed['status'] === 'complete' && count($mixed['manifest']['outputs']) === 6, 'supplied bar plus generated fixture hero produce six actual PNG files');
    check($mixed['manifest']['generation_requests'] === 1, 'only the missing truffle hero is requested');
    check($mixed['brief']['products'][0]['asset']['sha256'] === $previous['products'][0]['asset']['sha256'], 'supplied bar bytes are reused unchanged');
    check($mixed['brief']['products'][0]['asset']['reuse'] === 'previous_run', 'uploaded source reuse is recorded');
    check($mixed['brief']['products'][1]['asset']['reuse'] === 'generated', 'missing source generation is recorded');
    $directory = STORAGE_ROOT . '/runs/' . $mixed['brief']['run_id'];
    $savedManifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    check($savedManifest['compliance']['content']['status'] === 'passed'
        && $savedManifest['compliance']['content']['prohibited_phrases'] === Input::PROHIBITED_PHRASES
        && $savedManifest['compliance']['brand']['status'] === 'passed'
        && $savedManifest['compliance']['brand']['checked_outputs'] === 6,
        'saved manifest records the applied content rules and six passed brand checks');

    foreach ($mixed['manifest']['outputs'] as $output) {
        $path = $directory . '/' . $output['path'];
        $size = getimagesize($path);
        $image = imagecreatefrompng($path);
        check([$size[0], $size[1]] === Renderer::SIZES[$output['ratio']], 'correct PNG dimensions: ' . $output['path']);
        check($output['brand_compliance']['status'] === 'passed'
            && !in_array(false, $output['brand_compliance']['checks'], true), 'brand results retained for ' . $output['path']);
        check($output['text']['message']['text'] === 'Unwrap a better afternoon.' && $output['text']['brand']['text'] === 'melt&muse'
            && $output['text']['product']['text'] === $output['product_name'], 'exact required copy retained: ' . $output['path']);
        foreach ($output['text'] as $role => $block) {
            [$bx, $by, $bw, $bh] = $block['box'];
            check($bx >= 60 && $by >= 40 && $bx + $bw <= $size[0] - 40 && $by + $bh <= $size[1] - 40,
                $role . ' retains safe canvas margins: ' . $output['path']);
            $visible = 0;
            foreach ($block['lines'] as $line) {
                [$x, $y, $w, $h] = $line['bounds'];
                check($x >= $bx && $y >= $by && $x + $w <= $bx + $bw && $y + $h <= $by + $bh
                    && $x + $w < $size[0] && $y + $h < $size[1], 'measured ' . $role . ' fits: ' . $output['path']);
                for ($row = $y; $row < $y + $h; $row += 2) {
                    for ($column = $x; $column < $x + $w; $column += 2) {
                        if ((imagecolorat($image, $column, $row) & 0xffffff) === 0x36231f) {
                            $visible++;
                        }
                    }
                }
            }
            check($visible > 20, 'actual visible ' . $role . ' ink in PNG: ' . $output['path']);
        }
        [$px, $py, $pw, $ph] = $output['image_bounds'];
        [$sx, $sy, $sw, $sh] = $output['source_crop'];
        check(abs($pw / $ph - $sw / $sh) < 0.01, 'photographic crop is scaled without distortion: ' . $output['path']);
        if ($output['ratio'] === 'square') {
            check($px === 0 && $pw === 1080 && $py + $ph >= 1080, 'square photograph reaches the left, right, and bottom edges');
            $brandBottom = max(array_map(static fn (array $line): int => $line['bounds'][1] + $line['bounds'][3], $output['text']['brand']['lines']));
            check($output['text']['message']['lines'][0]['bounds'][1] - $brandBottom >= 50,
                'square wordmark and headline have clear breathing room');
        } elseif ($output['ratio'] === 'vertical') {
            $source = $mixed['manifest']['assets'][$output['product_id']];
            $coverScale = max(1080 / $source['width'], 1920 / $source['height']);
            check(abs(($ph / $sh) / $coverScale - 0.875) < 0.01 && $px === 0 && $pw === 1080 && $py + $ph === 1920,
                'vertical square source is about 12.5 percent smaller and reaches the sides and bottom');
        } elseif ($output['ratio'] === 'landscape') {
            check(count($output['text']['message']['lines']) === 3,
                'default landscape headline retains its three-line composition');
        }
        unset($image);
    }

    // Damage decoded test exports to prove these checks can fail, rather than only reporting success.
    $square = $directory . '/products/sea-salt-dark-bar/square.png';
    $plan = Renderer::plan($brief, $brief['products'][0], 'square');
    $damaged = imagecreatefrompng($square);
    [$x, $y, $w, $h] = $plan['text']['brand']['box'];
    imagefilledrectangle($damaged, $x, $y, $x + $w, $y + $h, 0xffffff);
    $checked = Renderer::checkBrand($damaged, $plan);
    check($checked['status'] === 'failed' && !$checked['checks']['wordmark_present'], 'erased wordmark fails brand verification');
    unset($damaged);
    $damaged = imagecreatefrompng($square);
    [$x, $y] = $plan['rule'];
    imagefilledrectangle($damaged, $x, $y, $x + 72, $y + 2, 0xff0000);
    $checked = Renderer::checkBrand($damaged, $plan);
    check($checked['checks']['wordmark_present'] && !$checked['checks']['brand_colors_used'], 'wrong accent color fails palette verification');
    $plan['text']['message']['lines'][0]['bounds'][0] = -10;
    check(!Renderer::checkBrand($damaged, $plan)['checks']['text_within_safe_bounds'], 'out-of-canvas text fails placement verification');
    unset($damaged);

    // Dark uploads exercise the actual contrast protection, independently of generated lighting.
    $darkPath = $directory . '/dark-upload.png';
    $dark = imagecreatetruecolor(256, 256);
    imagepng($dark, $darkPath);
    unset($dark);
    $short = $brief;
    $short['campaign_message'] = '.';
    $shortProduct = $brief['products'][0];
    $shortProduct['name'] = 'I';
    $shortOutput = Renderer::render($darkPath, $directory . '/short-copy.png', Renderer::plan($short, $shortProduct, 'square'));
    check($shortOutput['brand_compliance']['status'] === 'passed', 'very short valid copy still passes brand color checks');
    $portraitPath = $directory . '/portrait-upload.png';
    $portrait = imagecreatetruecolor(288, 512);
    imagepng($portrait, $portraitPath);
    unset($portrait);
    $portraitOutput = Renderer::render($portraitPath, $directory . '/portrait-output.png', Renderer::plan($brief, $brief['products'][0], 'vertical'));
    check($portraitOutput['image_bounds'] === [0, 0, 1080, 1920] && $portraitOutput['source_crop'] === [0, 0, 288, 512],
        'native portrait upload stays full frame without stretching or introducing borders');
    foreach (Renderer::SIZES as $ratio => $_) {
        $path = $directory . '/dark-' . $ratio . '.png';
        $result = Renderer::render($darkPath, $path, Renderer::plan($brief, $brief['products'][1], $ratio));
        $image = imagecreatefrompng($path);
        foreach ($result['text'] as $role => $block) {
            [$x, $y] = $block['box'];
            $pixel = imagecolorat($image, $x, $y);
            $linear = static function (int $channel): float {
                $s = $channel / 255;
                return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            };
            $backgroundLuminance = 0.2126 * $linear(($pixel >> 16) & 255)
                + 0.7152 * $linear(($pixel >> 8) & 255) + 0.0722 * $linear($pixel & 255);
            $inkLuminance = 0.2126 * $linear(54) + 0.7152 * $linear(35) + 0.0722 * $linear(31);
            check(($backgroundLuminance + 0.05) / ($inkLuminance + 0.05) >= 4.5,
                'dark uploaded image retains readable ' . $role . ' contrast in ' . $ratio);
        }
        unset($image);
    }

    $revised = $brief;
    $revised['campaign_message'] = 'A little chocolate. A well-deserved pause. A better afternoon.';
    $revision = Campaign::generate($revised, [], $mixed['brief'], $client);
    check($revision['status'] === 'complete' && $revision['manifest']['generation_requests'] === 0, 'headline-only revision renders six outputs with no provider request');
    check($revision['brief']['products'][1]['asset']['reuse'] === 'cache'
        && $revision['brief']['products'][1]['asset']['sha256'] === $mixed['brief']['products'][1]['asset']['sha256'], 'headline revision reuses the same generated bytes');
    check($revision['manifest']['outputs'][0]['sha256'] !== $mixed['manifest']['outputs'][0]['sha256'], 'headline revision changes rendered pixels');
    foreach ($revision['manifest']['outputs'] as $output) {
        $message = $output['text']['message'];
        check(implode('', array_column($message['lines'], 'text')) === $revised['campaign_message']
            && strlen(trim($message['lines'][0]['text'])) > 3 && $output['brand_compliance']['status'] === 'passed',
            'long headline keeps every word, avoids an orphaned first word, and fits: ' . $output['path']);
    }

    $retainedBrief = $brief;
    $retainedBrief['products'][1]['description'] .= ' Retain the approved photograph.';
    $original = $mixed['brief']['products'][1]['asset'];
    $retainedSettings = Campaign::settings($retainedBrief, $retainedBrief['products'][1]);
    Campaign::retainCached($retainedSettings, $original['cache_key']);
    $retained = Campaign::generate($retainedBrief, [], $mixed['brief'], $client);
    $asset = $retained['brief']['products'][1]['asset'];
    check($retained['status'] === 'complete' && $retained['manifest']['generation_requests'] === 0,
        'explicitly retained source renders under revised brief without a provider request');
    check($asset['sha256'] === $original['sha256'] && $asset['request_settings'] === $original['request_settings']
        && $asset['retained_from_cache_key'] === $original['cache_key'] && $asset['retained_for_settings'] === $retainedSettings
        && $asset['cache_key'] !== $original['cache_key'] && $asset['reuse'] === 'cache',
        'retention preserves original generation settings and records the separate reuse decision');
    check(rejects(fn () => Campaign::retainCached($retainedSettings, '../outside'))
        && rejects(fn () => Campaign::retainCached($retainedSettings, str_repeat('0', 64))),
        'retention rejects unsafe and missing source keys');

    $changed = $brief;
    $changed['products'][1]['description'] .= ' Served on a lilac ceramic plate.';
    $changedProduct = Campaign::generate($changed, [], $mixed['brief'], $client);
    check($changedProduct['manifest']['generation_requests'] === 1, 'changing a product invalidates only its generated source');
    $changed = $brief;
    $changed['visual_direction'] = 'Mint tabletop, afternoon sun, pink linen.';
    $two = Campaign::generate($changed, [], null, $client);
    check($two['status'] === 'complete' && $two['manifest']['generation_requests'] === 2, 'changed art direction with no uploads requests two fresh heroes');

    $partialBrief = $brief;
    $partialBrief['visual_direction'] = 'A yellow cafe table at afternoon break.';
    $partialBrief['products'][1]['description'] = 'CONTROLLED_FAILURE';
    $partial = Campaign::generate($partialBrief, [], null, $client);
    check($partial['status'] === 'incomplete' && count($partial['manifest']['outputs']) === 3, 'provider failure preserves the other product and its three PNGs');
    check($partial['manifest']['compliance']['brand']['status'] === 'incomplete'
        && $partial['manifest']['compliance']['brand']['checked_outputs'] === 3,
        'partial campaign reports only three checked outputs rather than full brand compliance');
    check(str_contains($partial['manifest']['errors']['hazelnut-truffles'], 'HTTP 429'), 'partial run includes a useful provider failure message');
    check(is_file(STORAGE_ROOT . '/runs/' . $partial['brief']['run_id'] . '/' . $partial['brief']['products'][0]['asset']['path']), 'successful source persists despite sibling failure');

    $tooLong = $brief;
    $tooLong['campaign_message'] = str_repeat('W', 180);
    check(rejects(fn () => Campaign::generate($tooLong, [], null, $client)), 'unfittable text rejected before generation instead of clipping');
    $prohibited = $brief;
    $prohibited['campaign_message'] = 'A miracle cure.';
    $runsBefore = glob(STORAGE_ROOT . '/runs/*');
    check(rejects(fn () => Campaign::generate($prohibited, [], null, $client))
        && glob(STORAGE_ROOT . '/runs/*') === $runsBefore, 'direct campaign call rejects prohibited copy before creating a run');
    $settings = Campaign::settings($brief, $brief['products'][1]);
    $otherSettings = $settings;
    $otherSettings['steps'] = 5;
    check(Campaign::cacheKey($settings) !== Campaign::cacheKey($otherSettings), 'generation settings participate in the cache key');

    // Optional inspection artifacts are explicitly labeled as controlled fixture evidence.
    $inspection = STORAGE_ROOT . '/verification/controlled-run';
    Storage::directory($inspection);
    Storage::json($inspection . '/manifest.json', ['verification' => 'Controlled local fixture. NOT live Cloudflare imagery.'] + $mixed['manifest']);
    foreach ($mixed['manifest']['outputs'] as $output) {
        Storage::directory(dirname($inspection . '/' . $output['path']));
        copy($directory . '/' . $output['path'], $inspection . '/' . $output['path']);
    }
    echo "$passed campaign checks passed using supplied test pixels and the local provider fixture.\n";
}

// tests/run.py supplies an isolated app, dummy credentials, and the loopback provider.
if (($argv[1] ?? '') === 'client' && isset($argv[2])) {
    testClientAndValidation($argv[2]);
} elseif (($argv[1] ?? '') === 'campaign' && isset($argv[2], $argv[3])) {
    testCampaign($argv[2], $argv[3]);
} else {
    fwrite(STDERR, "Run python3 tests/run.py to execute the suite safely.\n");
    exit(2);
}
