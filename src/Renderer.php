<?php
declare(strict_types=1);

final class Renderer
{
    public const SIZES = ['square' => [1080, 1080], 'vertical' => [1080, 1920], 'landscape' => [1920, 1080]];

    /** Pixel boxes [left, top, width, height]; lettering remains independent of the photograph. */
    public static function plan(array $brief, array $product, string $ratio): array
    {
        $layout = match ($ratio) {
            'square' => ['photo' => [0, 64, 1080, 1080],
                'brand' => [76, 44, 928, 48], 'message' => [74, 134, 932, 204],
                'product' => [76, 376, 928, 60], 'rule' => [78, 354], 'headline_size' => 88],
            'vertical' => ['photo' => [0, 0, 1080, 1920],
                'brand' => [80, 110, 920, 60], 'message' => [78, 238, 924, 260],
                'product' => [80, 558, 920, 80], 'rule' => [82, 530], 'headline_size' => 88],
            'landscape' => ['photo' => [840, 0, 1080, 1080],
                'brand' => [118, 180, 790, 60], 'message' => [118, 306, 790, 420],
                'product' => [118, 800, 790, 90], 'rule' => [120, 768], 'headline_size' => 100],
            default => throw new InvalidArgumentException('Unknown output aspect ratio.'),
        };
        $texts = ['brand' => self::wordmark($layout['brand'], $ratio === 'square' ? 32 : 38)];
        $texts['message'] = self::fitText($brief['campaign_message'], $layout['message'],
            APP_ROOT . '/assets/fonts/DMSerifDisplay-Regular.ttf', $layout['headline_size'], 32, "message in $ratio", true);
        $texts['product'] = self::fitText(strtoupper($product['name']), $layout['product'],
            APP_ROOT . '/assets/fonts/Lato-Regular.ttf', $ratio === 'square' ? 18 : 22, 18, "product in $ratio", false, 5);
        $texts['product']['text'] = $product['name']; // Preserve the input; uppercase is a display treatment.
        return ['size' => self::SIZES[$ratio], 'photo' => $layout['photo'], 'rule' => $layout['rule'],
            'text' => $texts, 'ratio' => $ratio, 'framing' => Input::crop($product['crops'][$ratio] ?? [])];
    }

    private static function wordmark(array $area, int $size): array
    {
        [$x, $y] = $area;
        $baseline = $y + 2 - self::bounds('melt&muse', $size, APP_ROOT . '/assets/fonts/Lato-Bold.ttf')[1];
        $lines = [];
        foreach (['melt', '&', 'muse'] as $part) {
            $font = 'Lato-' . ($part === '&' ? 'Regular' : 'Bold') . '.ttf';
            [$left, $top, $width, $height] = self::bounds($part, $size, APP_ROOT . '/assets/fonts/' . $font);
            $lines[] = ['text' => $part, 'font' => $font, 'baseline' => [$x + 2 - $left, $baseline],
                'bounds' => [$x + 2, $baseline + $top, $width, $height]];
            $x += $width + 1;
        }
        return ['text' => 'melt&muse', 'box' => $area, 'font_size' => $size, 'lines' => $lines];
    }

    private static function bounds(string $text, int $size, string $font): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException('Cannot measure text. Check the bundled fonts and GD FreeType support.');
        }
        $xs = [$box[0], $box[2], $box[4], $box[6]];
        $ys = [$box[1], $box[3], $box[5], $box[7]];
        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
    }

    /** Use the same glyph advances for measuring and drawing the letterspaced product label. */
    private static function lettering(string $text, int $size, string $font, int $tracking): array
    {
        $glyphs = [];
        $cursor = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
            $glyphs[] = ['text' => $letter, 'x' => $cursor];
            $box = imagettfbbox($size, 0, $font, $letter);
            $cursor += $box[2] + $tracking;
        }
        return ['width' => max(0, $cursor - $tracking), 'glyphs' => $glyphs];
    }

    private static function fitText(string $text, array $area, string $font, int $maximum, int $minimum,
        string $label, bool $wrapFromEnd = false, int $tracking = 0): array
    {
        [$x, $y, $width, $height] = $area;
        for ($size = $maximum; $size >= $minimum; $size -= 2) {
            $measure = static fn (string $value): int => $tracking
                ? self::lettering($value, $size, $font, $tracking)['width'] : self::bounds($value, $size, $font)[2];
            $lines = [];
            foreach (preg_split('/\r\n|\r|\n/u', $text) as $paragraph) {
                $words = preg_split('/(?<= )/u', $paragraph);
                if ($wrapFromEnd) {
                    $words = array_reverse($words);
                }
                $wrapped = [];
                $line = '';
                foreach ($words as $word) {
                    $candidate = $wrapFromEnd ? $word . $line : $line . $word;
                    if ($line !== '' && $measure($candidate) > $width - 4) {
                        $wrapped[] = $line;
                        $line = '';
                    }
                    $line = $wrapFromEnd ? $word . $line : $line . $word;
                }
                $wrapped[] = $line;
                $wrapped = $wrapFromEnd ? array_reverse($wrapped) : $wrapped;
                // Preserve deliberate paragraph breaks, but do not strand "A" or "The" when wrapping.
                if ($wrapFromEnd && count($wrapped) > 1 && preg_match('/^\s*\S{1,3}\s*$/u', $wrapped[0])) {
                    $words = preg_split('/(?<= )/u', $wrapped[1], -1, PREG_SPLIT_NO_EMPTY);
                    while (count($words) > 1 && $measure($wrapped[0]) < $measure($wrapped[1])
                        && $measure($wrapped[0] . $words[0]) <= $width - 4) {
                        $wrapped[0] .= array_shift($words);
                        $wrapped[1] = implode('', $words);
                    }
                }
                array_push($lines, ...$wrapped);
            }
            $metrics = array_map(static fn (string $line): array => self::bounds($line, $size, $font), $lines);
            $ascent = min(array_column($metrics, 1));
            $lineHeight = max((int) ceil($size * 1.35), max(array_column($metrics, 3)) + 10);
            $placed = [];
            foreach ($lines as $i => $line) {
                [$left, $top, , $h] = $metrics[$i];
                $w = $measure($line);
                $topInBox = $i * $lineHeight + $top - $ascent;
                if ($w > $width - 4 || $topInBox + $h > $height - 4) {
                    continue 2;
                }
                $placed[] = ['text' => $line, 'baseline' => [$x + 2 - $left, $y + 2 + $i * $lineHeight - $ascent],
                    'bounds' => [$x + 2, $y + 2 + $topInBox, $w, $h]];
            }
            return ['text' => $text, 'box' => $area, 'font' => basename($font), 'font_size' => $size,
                'tracking' => $tracking, 'lines' => $placed];
        }
        throw new InvalidArgumentException("The $label text cannot fit legibly. Shorten it; no clipped output was created.");
    }

    private static function photograph(GdImage $canvas, GdImage $photo, array $area): array
    {
        [$x, $y, $w, $h] = $area;
        $scale = max($w / imagesx($photo), $h / imagesy($photo));
        $sw = min(imagesx($photo), (int) round($w / $scale));
        $sh = min(imagesy($photo), (int) round($h / $scale));
        $sx = (int) floor((imagesx($photo) - $sw) / 2);
        $sy = (int) floor((imagesy($photo) - $sh) / 2);
        imagecopyresampled($canvas, $photo, $x, $y, $sx, $sy, $w, $h, $sw, $sh);
        return [$sx, $sy, $sw, $sh];
    }

    private static function protectCopy(GdImage $canvas, array $plan): void
    {
        // Dark uploads need a stronger wash. Pale generated walls retain their lighting and texture.
        $darkest = 255;
        foreach ($plan['text'] as $block) {
            foreach ($block['lines'] as $line) {
                [$x, $y, $w, $h] = $line['bounds'];
                for ($row = $y; $row < $y + $h; $row += 12) {
                    for ($col = $x; $col < $x + $w; $col += 12) {
                        $rgb = imagecolorat($canvas, $col, $row);
                        $darkest = min($darkest, min(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255));
                    }
                }
            }
        }
        $landscape = $plan['ratio'] === 'landscape';
        $opacity = $darkest < 160 ? 0.84 : 0.16;
        $textLines = array_merge(...array_column($plan['text'], 'lines'));
        $solidEnd = $landscape ? max(array_map(static fn (array $line): int => $line['bounds'][0] + $line['bounds'][2], $textLines)) + 24
            : max(array_map(static fn (array $line): int => $line['bounds'][1] + $line['bounds'][3], $plan['text']['product']['lines'])) + 10;
        $fadeEnd = $solidEnd + ($landscape ? 150 : 100);
        for ($pos = 0; $pos <= $fadeEnd; $pos++) {
            $fade = $pos <= $solidEnd ? 1 : 1 - ($pos - $solidEnd) / ($fadeEnd - $solidEnd);
            $alpha = (int) round(127 * (1 - $opacity * $fade));
            $color = imagecolorallocatealpha($canvas, 250, 237, 218, $alpha);
            if ($landscape) {
                imageline($canvas, $pos, 0, $pos, imagesy($canvas) - 1, $color);
            } else {
                imageline($canvas, 0, $pos, imagesx($canvas) - 1, $pos, $color);
            }
        }
    }

    /** Checks our known overlay locations and pixels; this is not image recognition or OCR. */
    public static function checkBrand(GdImage $canvas, array $plan): array
    {
        $inside = true;
        $ink = [];
        foreach ($plan['text'] as $role => $block) {
            [$bx, $by, $bw, $bh] = $block['box'];
            $inside = $inside && $bx >= 40 && $by >= 40
                && $bx + $bw <= imagesx($canvas) - 40 && $by + $bh <= imagesy($canvas) - 40;
            $ink[$role] = 0;
            foreach ($block['lines'] as $line) {
                [$x, $y, $w, $h] = $line['bounds'];
                if ($x < $bx || $y < $by || $x + $w > $bx + $bw || $y + $h > $by + $bh
                    || $x < 0 || $y < 0 || $x + $w >= imagesx($canvas) || $y + $h >= imagesy($canvas)) {
                    $inside = false;
                    continue;
                }
                for ($row = $y; $row < $y + $h; $row += 2) {
                    for ($column = $x; $column < $x + $w; $column += 2) {
                        if ((imagecolorat($canvas, $column, $row) & 0xffffff) === 0x36231f) {
                            $ink[$role]++;
                        }
                    }
                }
            }
        }
        [$ruleX, $ruleY] = $plan['rule'];
        $gold = $ruleX >= 0 && $ruleY >= 0 && $ruleX < imagesx($canvas) && $ruleY < imagesy($canvas)
            && (imagecolorat($canvas, $ruleX, $ruleY) & 0xffffff) === 0xaa7842;
        $checks = [
            'wordmark_present' => implode('', array_column($plan['text']['brand']['lines'] ?? [], 'text')) === 'melt&muse'
                && ($ink['brand'] ?? 0) > 20,
            'brand_colors_used' => $gold && min($ink['brand'] ?? 0, $ink['message'] ?? 0, $ink['product'] ?? 0) > 0,
            'text_within_safe_bounds' => $inside && count($ink) === 3,
        ];
        return ['status' => in_array(false, $checks, true) ? 'failed' : 'passed', 'checks' => $checks];
    }

    public static function render(string $source, string $destination, array $plan): array
    {
        $bytes = file_get_contents($source);
        if ($bytes === false || ($photo = @imagecreatefromstring($bytes)) === false) {
            throw new RuntimeException('The saved source image cannot be decoded. Upload it again.');
        }
        [$width, $height] = $plan['size'];
        $canvas = imagecreatetruecolor($width, $height);
        $photoArea = $plan['photo'];
        if ($plan['ratio'] === 'vertical') {
            // Back off the cover crop by 12.5% where width permits; keep the subject undistorted.
            $scale = max($width / imagesx($photo), 0.875 * max($width / imagesx($photo), $height / imagesy($photo)));
            $photoHeight = min($height, (int) round(imagesy($photo) * $scale));
            $photoArea = [0, $height - $photoHeight, $width, $photoHeight];
        }
        $framing = $plan['framing'];
        $adjusted = $framing !== ['x' => 0, 'y' => 0, 'zoom' => 100];
        if ($adjusted) {
            [$x, $y, $w, $h] = $photoArea;
            $zoom = $framing['zoom'] / 100;
            $photoArea = [(int) round($x + $w * (1 - $zoom) / 2 + $width * $framing['x'] / 100),
                (int) round($y + $h * (1 - $zoom) / 2 + $height * $framing['y'] / 100),
                (int) round($w * $zoom), (int) round($h * $zoom)];
        }
        if ($plan['ratio'] === 'square') {
            // Extend the quiet top edge slightly so the label clears the product.
            imagecopyresampled($canvas, $photo, 0, 0, 0, 0, $width, 64, imagesx($photo), 1);
        }
        if ($adjusted || $plan['ratio'] === 'landscape' || ($plan['ratio'] === 'vertical' && $photoArea[1] > 0)) {
            // Soft photographic extension fills the space outside the sharp photo, without empty borders.
            $smallHeight = (int) round(48 * $height / $width);
            $backdrop = imagecreatetruecolor(48, $smallHeight);
            self::photograph($backdrop, $photo, [0, 0, 48, $smallHeight]);
            imagefilter($backdrop, IMG_FILTER_GAUSSIAN_BLUR);
            $backdrop = imagescale($backdrop, $width, $height, IMG_BILINEAR_FIXED);
            imagecopy($canvas, $backdrop, 0, 0, 0, 0, $width, $height);
        }
        $crop = self::photograph($canvas, $photo, $photoArea);
        if ($adjusted) {
            self::blendEdges($canvas, $backdrop, $photoArea);
        } elseif ($plan['ratio'] === 'landscape') {
            for ($column = 0; $column < 180; $column++) {
                imagecopymerge($canvas, $backdrop, 840 + $column, 0, 840 + $column, 0, 1, $height,
                    (int) round(100 * (1 - $column / 180)));
            }
        } elseif ($plan['ratio'] === 'vertical' && $photoArea[1] > 0) {
            for ($row = 0; $row < 80; $row++) {
                imagecopymerge($canvas, $backdrop, 0, $photoArea[1] + $row, 0, $photoArea[1] + $row, $width, 1,
                    (int) round(100 * (1 - $row / 80)));
            }
        }
        self::protectCopy($canvas, $plan);
        $cocoa = imagecolorallocate($canvas, 54, 35, 31);
        [$ruleX, $ruleY] = $plan['rule'];
        imagefilledrectangle($canvas, $ruleX, $ruleY, $ruleX + 72, $ruleY + 2,
            imagecolorallocate($canvas, 170, 120, 66));
        foreach ($plan['text'] as $block) {
            foreach ($block['lines'] as $line) {
                $font = APP_ROOT . '/assets/fonts/' . ($line['font'] ?? $block['font']);
                $glyphs = ($block['tracking'] ?? 0)
                    ? self::lettering($line['text'], $block['font_size'], $font, $block['tracking'])['glyphs']
                    : [['text' => $line['text'], 'x' => 0]];
                foreach ($glyphs as $glyph) {
                    if (imagettftext($canvas, $block['font_size'], 0, $line['baseline'][0] + $glyph['x'],
                        $line['baseline'][1], $cocoa, $font, $glyph['text']) === false) {
                        throw new RuntimeException('Unable to render campaign text.');
                    }
                }
            }
        }
        $brandCompliance = self::checkBrand($canvas, $plan);
        ob_start();
        try {
            if (!imagepng($canvas, null, 6)) {
                throw new RuntimeException('Unable to encode the PNG output.');
            }
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        Storage::write($destination, $png);
        return ['width' => $width, 'height' => $height, 'sha256' => hash('sha256', $png),
            'image_bounds' => $photoArea, 'source_crop' => $crop, 'framing' => $framing, 'text' => $plan['text'],
            'brand_compliance' => $brandCompliance];
    }

    /** Feather exposed photo edges into the photographic extension, never into empty canvas. */
    private static function blendEdges(GdImage $canvas, GdImage $backdrop, array $area): void
    {
        [$x, $y, $w, $h] = $area;
        $left = max(0, $x);
        $top = max(0, $y);
        $right = min(imagesx($canvas), $x + $w);
        $bottom = min(imagesy($canvas), $y + $h);
        for ($i = 0; $i < 80; $i++) {
            $opacity = (int) round(100 * (1 - $i / 80));
            foreach ([$x > 0 ? $x + $i : -1, $x + $w < imagesx($canvas) ? $x + $w - 1 - $i : -1] as $col) {
                if ($col >= $left && $col < $right) {
                    imagecopymerge($canvas, $backdrop, $col, $top, $col, $top, 1, $bottom - $top, $opacity);
                }
            }
            foreach ([$y > 0 ? $y + $i : -1, $y + $h < imagesy($canvas) ? $y + $h - 1 - $i : -1] as $row) {
                if ($row >= $top && $row < $bottom) {
                    imagecopymerge($canvas, $backdrop, $left, $row, $left, $row, $right - $left, 1, $opacity);
                }
            }
        }
    }
}
