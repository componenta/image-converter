<?php

declare(strict_types=1);

use Componenta\Detector\FinfoDetector;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConversionOptions;

if (($argv[1] ?? '') === 'fixture') {
    [, , $path, $format, $width, $height] = $argv;
    $image = imagecreatetruecolor((int) $width, (int) $height);
    if ($image === false) {
        throw new RuntimeException('Unable to allocate benchmark fixture.');
    }
    for ($y = 0; $y < (int) $height; $y += 32) {
        for ($x = 0; $x < (int) $width; $x += 32) {
            $color = imagecolorallocate($image, $x % 251, $y % 253, ($x + $y) % 255);
            imagefilledrectangle($image, $x, $y, $x + 31, $y + 31, $color);
        }
    }
    $ok = match ($format) {
        'jpeg' => imagejpeg($image, $path, 90),
        'png' => imagepng($image, $path, 6),
        'webp' => imagewebp($image, $path, 90),
        'avif' => imageavif($image, $path, 90),
        default => throw new InvalidArgumentException('Unknown fixture format.'),
    };
    if (!$ok || !is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('Unable to encode benchmark fixture.');
    }

    exit(0);
}

if (($argv[1] ?? '') !== 'run' || count($argv) !== 6) {
    throw new InvalidArgumentException('Usage: rss-probe.php run AUTOLOAD FIXTURE TARGET VERSION');
}
[, , $autoload, $path, $target, $version] = $argv;
require $autoload;

$file = (new ReflectionClass(ImageConverter::class))->getFileName();
$baseline = is_string($file) && str_contains($file, '/.benchmark-baseline/');
if ($baseline !== ($version === 'v1')) {
    throw new RuntimeException('Wrong library resolved for RSS measurement: ' . $file);
}

$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read fixture.');
}
$service = new ImageConverter(new FinfoDetector(), [
    new GdJpegConverter(), new GdPngConverter(), new GdWebPConverter(), new GdAvifConverter(),
]);
$started = hrtime(true);
if ($baseline) {
    $quality = $target === 'png' ? 33 : 80;
    $result = $service->convert($source, $target, $quality);
} else {
    $options = $target === 'png'
        ? new ImageConversionOptions(compressionLevel: 6)
        : new ImageConversionOptions(quality: 80);
    $result = $service->convert($source, $target, $options);
}
$elapsed = (hrtime(true) - $started) / 1e6;
$usage = getrusage();
$output = getimagesizefromstring($result->content);
$input = getimagesizefromstring($source);
if ($input === false || $output === false || $output[0] !== $input[0]
    || $output[1] !== $input[1] || $output['mime'] !== 'image/' . $target) {
    throw new RuntimeException('Unexpected image dimensions or format.');
}
echo json_encode([
    'version' => $version,
    'case' => basename($path) . '->' . $target,
    'time_ms' => round($elapsed, 3),
    'peak_rss_kib' => $usage['ru_maxrss'],
    'php_peak_bytes' => memory_get_peak_usage(true),
    'output_bytes' => $result->size,
], JSON_THROW_ON_ERROR), PHP_EOL;
