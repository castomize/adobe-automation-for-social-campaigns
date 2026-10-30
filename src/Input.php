<?php
declare(strict_types=1);

final class Input
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    // Starter rules for campaign copy; not a complete legal policy.
    public const PROHIBITED_PHRASES = ['cure', 'cures', 'miracle', 'guaranteed weight loss'];

    public static function sampleBrief(): array
    {
        return json_decode(file_get_contents(APP_ROOT . '/examples/sample-brief.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function validateBrief(array $input, array $files): array
    {
        $errors = [];
        $brief = ['brand' => 'Melt & Muse'];
        foreach (['campaign_message' => 180, 'audience' => 300, 'market' => 80, 'language' => 60, 'visual_direction' => 600] as $field => $limit) {
            $brief[$field] = self::text($input[$field] ?? null, $field, $limit, $errors);
        }
        if (!isset($errors['language']) && $brief['language'] !== 'English') {
            $errors['language'] = 'Use English only.';
        }
        $products = $input['products'] ?? null;
        if (!is_array($products) || !array_is_list($products) || count($products) !== 2) {
            $errors['products'] = 'Include exactly two products, each with a unique ID.';
            $products = [];
        }
        $ids = [];
        $uploads = [];
        $brief['products'] = [];
        foreach ($products as $i => $product) {
            $product = is_array($product) ? $product : [];
            $clean = [];
            foreach (['id' => 48, 'name' => 80, 'description' => 350] as $field => $limit) {
                $clean[$field] = self::text($product[$field] ?? null, "products.$i.$field", $limit, $errors);
            }
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $clean['id'])) {
                $errors["products.$i.id"] = 'Use lowercase letters, numbers, and single hyphens only.';
            } elseif (isset($ids[$clean['id']])) {
                $errors["products.$i.id"] = 'Each product ID must be unique.';
            }
            $ids[$clean['id']] = true;
            try {
                if (isset($product['crops'])) {
                    if (!is_array($product['crops'])) {
                        throw new InvalidArgumentException('Invalid crop settings.');
                    }
                    foreach ($product['crops'] as $ratio => $crop) {
                        if (!isset(Renderer::SIZES[$ratio])) {
                            throw new InvalidArgumentException('Unknown crop format.');
                        }
                        $clean['crops'][$ratio] = self::crop($crop);
                    }
                }
            } catch (InvalidArgumentException $e) {
                $errors["products.$i.crops"] = $e->getMessage();
            }
            try {
                $file = $files['asset_' . $i] ?? null;
                if ($file !== null && !is_array($file)) {
                    throw new InvalidArgumentException('Malformed upload. Please select the file again.');
                }
                $uploads[$i] = self::validateUpload($file);
            } catch (InvalidArgumentException $e) {
                $errors["asset_$i"] = $e->getMessage();
            }
            $brief['products'][] = $clean;
        }
        if (array_diff(array_keys($files), ['asset_0', 'asset_1'])) {
            $errors['uploads'] = 'An unexpected upload field was supplied.';
        }
        $errors += self::contentErrors($brief);
        // Validate the complete brief and every upload before writing any run or sending any request.
        return ['brief' => $brief, 'uploads' => $uploads, 'errors' => $errors];
    }

    public static function contentErrors(array $brief): array
    {
        $fields = array_intersect_key($brief, array_flip(['campaign_message', 'audience', 'market', 'visual_direction']));
        foreach ($brief['products'] ?? [] as $i => $product) {
            foreach (['name', 'description'] as $field) {
                $fields["products.$i.$field"] = $product[$field] ?? '';
            }
        }
        $errors = [];
        foreach ($fields as $field => $value) {
            if (!is_string($value)) {
                continue; // Format validation reports malformed input separately.
            }
            // Treat punctuation, hyphens and repeated whitespace as word separators.
            $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
            if ($normalized === null) {
                continue;
            }
            $matches = [];
            foreach (self::PROHIBITED_PHRASES as $phrase) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu', $normalized)) {
                    $matches[] = $phrase;
                }
            }
            if ($matches) {
                $errors[$field] = 'Revise this text: prohibited words or phrases: ' . implode(', ', $matches) . '.';
            }
        }
        return $errors;
    }

    public static function crop(mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid crop settings.');
        }
        $crop = [];
        foreach (['x' => [-20, 20, 0], 'y' => [-20, 20, 0], 'zoom' => [85, 150, 100]] as $key => [$min, $max, $default]) {
            $value = $input[$key] ?? $default;
            if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value)
                || (float) $value < $min || (float) $value > $max) {
                throw new InvalidArgumentException("Crop $key must be between $min and $max.");
            }
            $crop[$key] = (int) round((float) $value);
        }
        return $crop;
    }

    private static function text(mixed $value, string $field, int $limit, array &$errors): string
    {
        if (!is_string($value) || trim($value) === '') {
            $errors[$field] = 'This field is required.';
            return '';
        }
        if (strlen($value) > $limit || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[$field] = "Use valid text up to $limit UTF-8 bytes, without control characters.";
        }
        return $value; // Preserve the exact headline; trimming or rewording would change campaign copy.
    }

    /** Check dimensions before allocating the full decoded pixel buffer. */
    public static function inspectImage(string $bytes, int $maxBytes = self::MAX_UPLOAD_BYTES): array
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new InvalidArgumentException('Image is empty or exceeds the permitted file size.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);
        if (!isset(self::MIME_EXTENSIONS[$mime]) || $size === false || ($size['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('Use a real JPEG, PNG, or WebP image.');
        }
        [$width, $height] = $size;
        if ($width < 256 || $height < 256 || $width > 4096 || $height > 4096 || $width * $height > 8_000_000) {
            throw new InvalidArgumentException('Each side must be 256–4096 px, with at most 8 million pixels in total.');
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new InvalidArgumentException('The image could not be decoded. Export it again and retry.');
        }
        unset($decoded);
        return ['mime' => $mime, 'extension' => self::MIME_EXTENSIONS[$mime], 'width' => $width, 'height' => $height, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public static function validateUpload(?array $file): ?array
    {
        if ($file === null) {
            return null;
        }
        $error = $file['error'] ?? null;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload exceeds the 5 MiB file limit or server limit.',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please select the file again.',
                default => 'Upload failed. Please select the file again.',
            });
        }
        $path = $file['tmp_name'] ?? null;
        if (!is_string($path) || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('The uploaded file is unavailable. Please select it again.');
        }
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Upload must be nonempty and no larger than 5 MiB.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException('The uploaded image could not be read.');
        }
        return ['tmp_name' => $path, 'info' => self::inspectImage($bytes)];
    }
}
