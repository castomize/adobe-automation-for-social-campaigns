<?php
declare(strict_types=1);

/** Browser-owned local history. Files remain the source of truth; no database is needed. */
final class Runs
{
    public function __construct(private string $owner, private array $legacyGrants = []) {}

    public function read(mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            return null;
        }
        $directory = STORAGE_ROOT . '/runs/' . $id;
        if (!is_file($directory . '/manifest.json') || !is_file($directory . '/brief.json')) {
            return null;
        }
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $authorized = isset($manifest['owner_hash']) ? hash_equals($manifest['owner_hash'], $this->owner)
            : isset($this->legacyGrants[$id]);
        if (!$authorized) {
            return null;
        }
        $brief = json_decode(file_get_contents($directory . '/brief.json'), true, 64, JSON_THROW_ON_ERROR);
        return self::result($brief, $manifest);
    }

    public static function result(array $brief, array $manifest): array
    {
        unset($manifest['owner_hash']);
        return ['status' => $manifest['status'], 'brief' => $brief, 'manifest' => $manifest,
            'download_url' => '/brief?run=' . $brief['run_id'], 'bundle_url' => '/bundle?run=' . $brief['run_id']];
    }

    public function claim(array $result): array
    {
        $manifest = $result['manifest'];
        $manifest['owner_hash'] = $this->owner;
        $manifest['kept'] = false;
        Storage::json(STORAGE_ROOT . '/runs/' . $result['brief']['run_id'] . '/manifest.json', $manifest);
        return self::result($result['brief'], $manifest);
    }

    public function history(): array
    {
        $history = [];
        foreach (glob(STORAGE_ROOT . '/runs/*', GLOB_ONLYDIR) ?: [] as $directory) {
            try {
                $run = $this->read(basename($directory));
                if ($run !== null) {
                    $history[] = ['run_id' => $run['brief']['run_id'], 'created_at' => $run['brief']['created_at'],
                        'message' => $run['brief']['campaign_message'], 'status' => $run['status'],
                        'kept' => $run['manifest']['kept'] ?? false];
                }
            } catch (Throwable) {
                // A damaged unrelated run must not prevent opening healthy campaigns.
            }
        }
        usort($history, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $history;
    }

    /** Serialize keep/delete with reads and exports across PHP sessions. */
    public static function locked(callable $action, int $mode = LOCK_EX): mixed
    {
        $lock = fopen(STORAGE_ROOT . '/runs.lock', 'c');
        if ($lock === false || !flock($lock, $mode)) {
            throw new RuntimeException('Campaign storage is busy. Try again.');
        }
        try {
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function update(mixed $id, string $action, bool $kept = false): array
    {
        return self::locked(function () use ($id, $action, $kept): array {
            $run = $this->read($id);
            if ($run === null) {
                throw new InvalidArgumentException('This saved campaign is unavailable in this browser.');
            }
            if ($run['status'] === 'processing') {
                throw new InvalidArgumentException('Wait for this campaign to finish processing.');
            }
            $directory = STORAGE_ROOT . '/runs/' . $id;
            if ($action === 'keep') {
                $manifest = $run['manifest'];
                $manifest['owner_hash'] = $this->owner;
                $manifest['kept'] = $kept;
                Storage::json($directory . '/manifest.json', $manifest);
                return self::result($run['brief'], $manifest);
            }
            if ($action !== 'delete' || ($run['manifest']['kept'] ?? false)) {
                throw new InvalidArgumentException('Unkeep this campaign before deleting it.');
            }
            Storage::directory(STORAGE_ROOT . '/trash');
            if (file_exists(STORAGE_ROOT . '/trash/' . $id) || !rename($directory, STORAGE_ROOT . '/trash/' . $id)) {
                throw new RuntimeException('The campaign could not be moved to trash.');
            }
            return ['deleted' => $id];
        });
    }

    /** Add only declared, verified outputs. Never include private ownership or arbitrary files. */
    public static function bundle(array $run): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Enable the PHP ZIP extension to download a campaign bundle.');
        }
        if (!$run['manifest']['outputs']) {
            throw new RuntimeException('No campaign images are available to download. Resolve the generation errors and try again.');
        }
        $file = tempnam(STORAGE_ROOT, 'bundle-');
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($file === false || $zip->open($file, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the campaign ZIP.');
            }
            $opened = true;
            $directory = STORAGE_ROOT . '/runs/' . $run['brief']['run_id'];
            foreach ($run['manifest']['outputs'] as $output) {
                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $output['product_id'])
                    || !isset(Renderer::SIZES[$output['ratio']])) {
                    throw new RuntimeException('An output record is invalid. Open the campaign and render it again.');
                }
                $path = 'products/' . $output['product_id'] . '/' . $output['ratio'] . '.png';
                if (!is_file($directory . '/' . $path)
                    || !hash_equals($output['sha256'], hash_file('sha256', $directory . '/' . $path))
                    || !$zip->addFile($directory . '/' . $path, $path)) {
                    throw new RuntimeException('An output is missing or changed. Render the campaign again before exporting.');
                }
            }
            $closed = $zip->close();
            $opened = false;
            if (!$closed) {
                throw new RuntimeException('Could not finish the campaign ZIP.');
            }
            return $file;
        } catch (Throwable $e) {
            if ($opened) {
                $zip->close();
            }
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
            throw $e;
        }
    }
}
