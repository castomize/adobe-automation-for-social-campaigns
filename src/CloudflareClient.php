<?php
declare(strict_types=1);

final class CloudflareClient
{
    public const MODEL = '@cf/black-forest-labs/flux-1-schnell';
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;
    private string $endpoint;

    public function __construct(
        string $account,
        private string $token,
        ?string $testEndpoint = null,
        private int $timeoutSeconds = 90,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $account) || $token === '' || preg_match('/\s/', $token)) {
            throw new InvalidArgumentException('Invalid Cloudflare credentials. Check the private configuration.');
        }
        $this->endpoint = 'https://api.cloudflare.com/client/v4/accounts/' . $account . '/ai/run/' . self::MODEL;
        if ($testEndpoint !== null) {
            // Endpoint overrides are limited to local CLI tests with a dummy token.
            if (PHP_SAPI !== 'cli' || $token !== 'test-token' || !preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}/generate$~D', $testEndpoint)) {
                throw new InvalidArgumentException('Test endpoint is restricted to local CLI fixtures with a dummy token.');
            }
            $this->endpoint = $testEndpoint;
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 90) {
            throw new InvalidArgumentException('Total timeout must be between 1 and 90 seconds.');
        }
    }

    /**
     * $requests = ['product-id' => ['prompt' => '...', 'steps' => 4], ...].
     * One or two transfers; results retain each success even if its sibling fails.
     * Returned image bytes must be saved privately by the caller. No retries.
     */
    public function generate(array $requests): array
    {
        if (count($requests) < 1 || count($requests) > 2) {
            throw new InvalidArgumentException('A batch must contain one or two requests.');
        }
        foreach ($requests as $id => $request) {
            if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || !is_array($request)) {
                throw new InvalidArgumentException('Each request needs a safe product ID and request settings.');
            }
            if (array_diff(array_keys($request), ['prompt', 'steps']) || !is_string($request['prompt'] ?? null)
                || trim($request['prompt']) === '' || strlen($request['prompt']) > 2048 || !preg_match('//u', $request['prompt'])
                || !is_int($request['steps'] ?? null) || $request['steps'] < 1 || $request['steps'] > 8) {
                throw new InvalidArgumentException('Use only a nonempty prompt (up to 2048 UTF-8 bytes) and 1–8 integer steps.');
            }
        }

        $started = microtime(true);
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 2);
        curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 2);
        $handles = [];
        $buffers = [];
        $overflow = [];
        $transferCodes = [];
        $results = [];
        try {
            foreach ($requests as $id => $request) {
                $buffers[$id] = '';
                $overflow[$id] = false;
                $handle = curl_init($this->endpoint);
                $handles[$id] = $handle;
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
                    CURLOPT_TIMEOUT => $this->timeoutSeconds,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => str_starts_with($this->endpoint, 'https:') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($id, &$buffers, &$overflow): int {
                        if (strlen($buffers[$id]) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                            $overflow[$id] = true;
                            return 0;
                        }
                        $buffers[$id] .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
                    throw new RuntimeException('Unable to initialize the batch transfer.');
                }
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('The batch transfer engine failed.');
                }
                while ($message = curl_multi_info_read($multi)) {
                    $transferCodes[spl_object_id($message['handle'])] = $message['result'];
                }
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10_000); // libcurl may have no selectable descriptors; avoid a busy loop.
                }
            } while ($running > 0);

            foreach ($handles as $id => $handle) {
                $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $transfer = $transferCodes[spl_object_id($handle)] ?? curl_errno($handle);
                $result = ['http_status' => $http, 'elapsed_ms' => round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000, 2)];
                try {
                    if ($overflow[$id]) {
                        throw new RuntimeException('Provider response exceeded the 16 MiB safety limit.');
                    }
                    if ($transfer !== CURLE_OK) {
                        throw new RuntimeException('Network transfer failed (cURL code ' . $transfer . '). No retry was made.');
                    }
                    if ($http < 200 || $http >= 300) {
                        throw new RuntimeException('Cloudflare returned HTTP ' . $http . '. Check access, quota, and account settings. No retry was made.');
                    }
                    $data = json_decode($buffers[$id], true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($data) || ($data['success'] ?? null) !== true || !is_array($data['errors'] ?? null) || $data['errors'] !== []) {
                        throw new RuntimeException('Cloudflare reported an unsuccessful response or an invalid response envelope.');
                    }
                    $encoded = $data['result']['image'] ?? null;
                    if (!is_string($encoded) || $encoded === '') {
                        throw new RuntimeException('Cloudflare response is missing result.image.');
                    }
                    $image = base64_decode($encoded, true);
                    if ($image === false) {
                        throw new RuntimeException('Cloudflare image is not valid base64.');
                    }
                    $info = Input::inspectImage($image, 10 * 1024 * 1024);
                    $results[$id] = $result + ['success' => true, 'image' => $image, 'info' => $info, 'error' => null];
                } catch (JsonException) {
                    $results[$id] = $result + ['success' => false, 'error' => 'Cloudflare returned invalid JSON.'];
                } catch (RuntimeException | InvalidArgumentException $e) {
                    // Never persist or print raw provider bodies or curl diagnostics: they may echo secrets.
                    $results[$id] = $result + ['success' => false, 'error' => $e->getMessage()];
                }
            }
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            // PHP 8 CurlHandle objects release their resources when their references go out of scope.
            $handles = [];
            unset($handle);
            curl_multi_close($multi);
        }
        return [
            'status' => count(array_filter($results, static fn ($r) => $r['success'])) === count($requests) ? 'complete' : 'incomplete',
            'results' => $results, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        ];
    }
}
