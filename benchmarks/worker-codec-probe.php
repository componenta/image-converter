<?php

declare(strict_types=1);

use Componenta\Detector\FinfoDetector;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\ImageConverter;
use Componenta\Image\Converter\ImageConversionOptions;

require __DIR__ . '/../vendor/autoload.php';

function probeRss(): int
{
    $status = file_get_contents('/proc/self/status');
    if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
        throw new RuntimeException('Linux process RSS unavailable.');
    }
    return (int) $matches[1];
}

function probeEncode(GdImage $image, string $format): string
{
    ob_start();
    try {
        $ok = match ($format) {
            'jpeg' => imagejpeg($image, null, 80),
            'png' => imagepng($image, null, 6),
            'webp' => imagewebp($image, null, 80),
            'avif' => imageavif($image, null, 80, 8),
        };
        $result = ob_get_contents();
        if (!$ok || $result === false || $result === '') {
            throw new RuntimeException('Native encoder failed.');
        }
        return $result;
    } finally {
        ob_end_clean();
    }
}

[, $mode, $from, $to] = $argv;
$image = imagecreatetruecolor(512, 384);
imagefilledrectangle($image, 0, 0, 255, 383, imagecolorallocate($image, 200, 30, 50));
imagefilledrectangle($image, 256, 0, 511, 383, imagecolorallocate($image, 30, 80, 220));
$raw = probeEncode($image, $from);
$detector = new FinfoDetector();
$service = new ImageConverter($detector, [
    new GdJpegConverter(), new GdPngConverter(), new GdWebPConverter(), new GdAvifConverter(),
]);
$options = match ($to) {
    'png' => new ImageConversionOptions(compressionLevel: 6),
    'avif' => new ImageConversionOptions(quality: 80, avifSpeed: 8),
    default => new ImageConversionOptions(quality: 80),
};
$process = static function () use ($mode, $from, $to, $image, $raw, $detector, $service, $options): void {
    switch ($mode) {
        case 'detect':
            if ($detector->detectMimeType($raw) === null) {
                throw new RuntimeException('Detection failed.');
            }
            break;
        case 'header':
            if (getimagesizefromstring($raw) === false) {
                throw new RuntimeException('Header failed.');
            }
            break;
        case 'decode':
            if (!imagecreatefromstring($raw) instanceof GdImage) {
                throw new RuntimeException('Decode failed.');
            }
            break;
        case 'encode':
            probeEncode($image, $to);
            break;
        case 'native':
            $decoded = imagecreatefromstring($raw);
            if (!$decoded instanceof GdImage) {
                throw new RuntimeException('Decode failed.');
            }
            if ($to === 'avif' || ($to === 'png' && $from === 'avif')) {
                imagealphablending($decoded, false);
                imagesavealpha($decoded, true);
            }
            $encoded = probeEncode($decoded, $to);
            $header = getimagesizefromstring($encoded);
            if ($header === false || $header[0] !== 512 || $header[1] !== 384
                || $header['mime'] !== 'image/' . $to || getimagesizefromstring($raw) === false) {
                throw new RuntimeException('Header failed.');
            }
            break;
        case 'library':
            $result = $service->convert($raw, $to, $options);
            $header = getimagesizefromstring($result->content);
            if ($header === false || $header[0] !== 512 || $header[1] !== 384 || $header['mime'] !== 'image/' . $to) {
                throw new RuntimeException('Unexpected library output.');
            }
            break;
        default:
            throw new InvalidArgumentException('Unknown mode.');
    }
};
for ($i = 1; $i <= 200; ++$i) {
    $process();
    if (in_array($i, [20, 80, 140, 200], true)) {
        gc_collect_cycles();
        echo json_encode([
            'mode' => $mode, 'case' => $from . '->' . $to, 'iterations' => $i,
            'rss_kib' => probeRss(), 'php_bytes' => memory_get_usage(false),
        ], JSON_THROW_ON_ERROR), PHP_EOL;
    }
}
