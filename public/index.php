<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
Storage::directory(STORAGE_ROOT . '/sessions');
session_save_path(STORAGE_ROOT . '/sessions');
session_name('melt_muse');
session_start(['use_strict_mode' => 1, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$owner = $_COOKIE['melt_muse_owner'] ?? '';
if (!is_string($owner) || !preg_match('/^[a-f0-9]{64}$/D', $owner)) {
    $owner = bin2hex(random_bytes(32));
}
// Returning through a link must retain history. All writes still require the session CSRF token.
setcookie('melt_muse_owner', $owner, ['expires' => time() + 365 * 86400, 'path' => '/',
    'httponly' => true, 'samesite' => 'Lax']);
$runs = new Runs(hash('sha256', $owner), $_SESSION['runs'] ?? []);
unset($owner);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function authorizedBrief(mixed $run): ?array
{
    global $runs;
    return $runs->read($run)['brief'] ?? null;
}

function requireCsrf(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        respond(['errors' => ['request' => 'Your form session expired. Reload this page and try again.']], 403);
    }
}

if ($path === '/api/campaign') {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            respond(['runs' => Runs::locked(fn () => $runs->history(), LOCK_SH)]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: GET, POST');
            respond(['errors' => ['request' => 'Use a campaign action.']], 405);
        }
        requireCsrf();
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['keep', 'delete'], true)) {
            if ($action === 'keep' && !in_array($_POST['kept'] ?? null, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose whether to keep this campaign.');
            }
            respond($runs->update($_POST['run'] ?? '', $action, ($_POST['kept'] ?? '') === '1'));
        }
        set_time_limit(180);
        $result = Runs::locked(function () use ($action, $runs): array {
            if ($action === 'example') {
                $sample = Input::sampleBrief();
                return $runs->claim(Campaign::fromSources($sample, APP_ROOT . '/examples',
                    array_column($sample['products'], 'asset', 'id')));
            }
            $run = $runs->read($_POST['run'] ?? '');
            if ($run === null) {
                respond(['errors' => ['request' => 'This saved campaign is unavailable in this browser.']], 404);
            }
            if ($action === 'open') {
                return $run;
            }
            if ($action !== 'crop') {
                throw new InvalidArgumentException('Unknown campaign action.');
            }
            $ratio = $_POST['ratio'] ?? '';
            if (!is_string($ratio) || !isset(Renderer::SIZES[$ratio])) {
                throw new InvalidArgumentException('Unknown crop format.');
            }
            $brief = $run['brief'];
            $matched = false;
            foreach ($brief['products'] as &$product) {
                if ($product['id'] === ($_POST['product'] ?? '')) {
                    $product['crops'][$ratio] = Input::crop($_POST['crop'] ?? null);
                    $matched = true;
                }
            }
            unset($product);
            if (!$matched) {
                throw new InvalidArgumentException('Choose a product from this campaign.');
            }
            return $runs->claim(Campaign::fromSources($brief, STORAGE_ROOT . '/runs/' . $brief['run_id'],
                array_column($brief['products'], 'asset', 'id'), $brief['run_id']));
        }, LOCK_SH);
        $_SESSION['runs'][$result['brief']['run_id']] = true;
        respond($result);
    } catch (InvalidArgumentException $e) {
        respond(['errors' => ['request' => $e->getMessage()]], 422);
    } catch (Throwable) {
        respond(['errors' => ['request' => 'Could not load or save this campaign. Check its source files and writable storage.']], 500);
    }
}

if ($path === '/bundle') {
    try {
        $file = Runs::locked(function () use ($runs): string {
            $run = $runs->read($_GET['run'] ?? '');
            if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $run === null) {
                respond(['errors' => ['request' => 'Campaign not found.']], 404);
            }
            return Runs::bundle($run);
        }, LOCK_SH);
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="melt-muse-' . $_GET['run'] . '.zip"');
        header('Content-Length: ' . filesize($file));
        try { readfile($file); } finally { unlink($file); }
        exit;
    } catch (RuntimeException $e) {
        respond(['errors' => ['request' => $e->getMessage()]], 500);
    }
}

if ($path === '/api/generate') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        respond(['errors' => ['request' => 'Submit the campaign form to generate images.']], 405);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12 * 1024 * 1024) {
        respond(['errors' => ['uploads' => 'The total submission exceeds 12 MiB. Each image may be at most 5 MiB.']], 413);
    }
    requireCsrf();
    try {
        $validated = Input::validateBrief($_POST, $_FILES);
        if ($validated['errors']) {
            respond(['errors' => $validated['errors']], 422);
        }
        $previous = authorizedBrief($_POST['previous_run'] ?? '');
        if (($_POST['previous_run'] ?? '') !== '' && $previous === null) {
            respond(['errors' => ['request' => 'The previous run is unavailable in this session. Reload and select your source image again.']], 403);
        }
        set_time_limit(180);
        $result = Runs::locked(fn () => $runs->claim(Campaign::generate($validated['brief'], $validated['uploads'], $previous)), LOCK_SH);
        $_SESSION['runs'][$result['brief']['run_id']] = true;
        respond($result);
    } catch (InvalidArgumentException $e) {
        respond(['errors' => ['request' => $e->getMessage()]], 422);
    } catch (Throwable) {
        respond(['errors' => ['request' => 'Campaign processing stopped. Check PHP prerequisites and writable private storage. Any saved sources remain in storage/runs.']], 500);
    }
}

if (in_array($path, ['/brief', '/asset', '/output'], true)) {
    $brief = authorizedBrief($_GET['run'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $brief === null) {
        http_response_code(404);
        exit('Not found.');
    }
    $directory = STORAGE_ROOT . '/runs/' . $brief['run_id'];
    session_write_close();
    if ($path === '/brief') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="melt-muse-brief.json"');
        readfile($directory . '/brief.json');
        exit;
    }
    foreach ($brief['products'] as $product) {
        if ($product['id'] !== ($_GET['product'] ?? null)) {
            continue;
        }
        $asset = $product['asset'];
        if ($path === '/asset' && is_string($asset['path']) && preg_match('~^sources/[a-f0-9]{32}\.(jpg|png|webp)$~D', $asset['path'])) {
            header('Content-Type: ' . $asset['mime']);
            readfile($directory . '/' . $asset['path']);
            exit;
        }
        $ratio = $_GET['ratio'] ?? '';
        if ($path === '/output' && is_string($ratio) && isset(Renderer::SIZES[$ratio])) {
            $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
            foreach ($manifest['outputs'] as $output) {
                if ($output['product_id'] === $product['id'] && $output['ratio'] === $ratio) {
                    header('Content-Type: image/png');
                    if (($_GET['download'] ?? '') === '1') {
                        header('Content-Disposition: attachment; filename="' . $product['id'] . '-' . $ratio . '.png"');
                    }
                    readfile($directory . '/products/' . $product['id'] . '/' . $ratio . '.png');
                    exit;
                }
            }
        }
    }
    http_response_code(404);
    exit('Not found.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
$sample = Input::sampleBrief();
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Melt & Muse · Campaign Studio</title>
    <link rel="stylesheet" href="/style.css">
    <script src="/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#campaign">Skip to campaign form</a>
<header>
    <a class="wordmark" href="/" aria-label="Melt and Muse home">melt<span>&</span>muse</a>
    <span>Campaign Studio</span>
</header>
<main>
    <div class="page-heading">
        <h1>A better afternoon,<br><em>by design.</em></h1>
        <p>Turn one brief into a coordinated set of campaign images.</p>
    </div>
    <div class="campaign-toolbar">
        <button type="button" id="load-example" class="secondary">Load example</button>
        <span class="help">Approved photography · no generation requests</span>
    </div>
    <details class="saved-campaigns">
        <summary>Saved campaigns <span id="history-count"></span></summary>
        <p class="help">Saved in this browser. Keep protects a campaign from deletion.</p>
        <div id="history" aria-live="polite"></div>
    </details>
    <form id="campaign" action="/api/generate" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="previous_run" id="previous-run" value="">
        <section class="studio-section" aria-labelledby="brief-title">
            <div class="section-heading">
                <span class="section-number" aria-hidden="true">01</span>
                <h2 id="brief-title">Campaign brief</h2>
            </div>
            <div class="brief-panel">
                <div class="field">
                    <label for="campaign_message">Campaign message</label>
                    <input id="campaign_message" name="campaign_message"
                        value="<?= h($sample['campaign_message']) ?>" maxlength="180" required>
                </div>
                <div class="details-grid">
                    <div class="field">
                        <label for="audience">Audience</label>
                        <textarea id="audience" name="audience" rows="2" maxlength="300" required><?= h($sample['audience']) ?></textarea>
                    </div>
                    <div class="field">
                        <label for="market">Target market</label>
                        <input id="market" name="market" value="<?= h($sample['market']) ?>" maxlength="80" required>
                    </div>
                    <div class="field">
                        <label for="language">Language (English only)</label>
                        <input id="language" name="language" value="<?= h($sample['language']) ?>" maxlength="60" readonly required>
                    </div>
                </div>
                <div class="field">
                    <label for="visual_direction">Visual direction</label>
                    <textarea id="visual_direction" name="visual_direction" rows="4" maxlength="600" required><?= h($sample['visual_direction']) ?></textarea>
                </div>
            </div>
        </section>
        <section class="studio-section" aria-labelledby="products-title">
            <div class="section-heading">
                <span class="section-number" aria-hidden="true">02</span>
                <h2 id="products-title">Your products</h2>
            </div>
            <p id="upload-rules" class="section-help">Optional JPEG, PNG or WebP, up to 5 MiB. Missing images are generated; saved sources are reused.</p>
            <div class="product-grid">
                <?php foreach ($sample['products'] as $i => $product): ?>
                <fieldset class="product-card">
                    <legend>Product <?= $i + 1 ?></legend>
                    <input type="hidden" id="product-<?= $i ?>-id" name="products[<?= $i ?>][id]" value="<?= h($product['id']) ?>">
                    <div class="field">
                        <label for="product-<?= $i ?>-name">Product name</label>
                        <input id="product-<?= $i ?>-name" name="products[<?= $i ?>][name]"
                            value="<?= h($product['name']) ?>" maxlength="80" required>
                    </div>
                    <div class="field">
                        <label for="product-<?= $i ?>-description">Product description</label>
                        <textarea id="product-<?= $i ?>-description" name="products[<?= $i ?>][description]"
                            rows="7" maxlength="350" required><?= h($product['description']) ?></textarea>
                    </div>
                    <div class="field upload">
                        <label for="asset-<?= $i ?>">Source image <span class="help">(optional)</span></label>
                        <input type="file" id="asset-<?= $i ?>" name="asset_<?= $i ?>"
                            accept="image/jpeg,image/png,image/webp" aria-describedby="upload-rules source-<?= $i ?>">
                        <div class="source-status">
                            <img class="source-preview" id="source-preview-<?= $i ?>" alt="" hidden>
                            <p class="help" id="source-<?= $i ?>">No source selected.</p>
                        </div>
                    </div>
                </fieldset>
                <?php endforeach; ?>
            </div>
        </section>
        <div class="actions">
            <p class="export-summary">
                <strong>6 campaign images</strong>
                <span class="help">Square · Vertical · Landscape</span>
            </p>
            <button id="generate-button" type="submit">Generate campaign <span aria-hidden="true">↗</span></button>
        </div>
        <noscript>JavaScript is needed to display results here; submitting without it returns the run as JSON.</noscript>
    </form>
    <div id="feedback" class="feedback" role="status" aria-live="polite" tabindex="-1" hidden></div>
    <section id="result" hidden aria-labelledby="result-title">
        <div class="section-heading">
            <span class="section-number" aria-hidden="true">03</span>
            <h2 id="result-title">Campaign preview</h2>
        </div>
        <div class="result-actions"><p class="section-help">Select an image to view it full size. Crop changes save a new version.</p>
            <a id="bundle-download" class="secondary" href="#">Download campaign ZIP</a></div>
        <div id="gallery"></div>
    </section>
</main>
</body>
</html>
