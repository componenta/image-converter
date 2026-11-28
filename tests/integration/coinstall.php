<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\Image\ConfigProvider;
use Componenta\Image\Factory\ImageCompressorFactory;
use Componenta\Image\Factory\ImageConverterFactory;
use Componenta\Image\ImageCompressionOptions;
use Componenta\Image\ImageCompressor;
use Componenta\Image\ImageCompressorConfigProvider;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\ImageConverter;
use Psr\Container\ContainerInterface;

if (!isset($argv[1])) {
    throw new RuntimeException('Pass the consumer autoload path.');
}
require $argv[1];

$providers = [
    ImageCompressor::class => new ImageCompressorConfigProvider(),
    ImageConverter::class => new ConfigProvider(),
];
foreach ($providers as $service => $provider) {
    $configuration = $provider();
    if (!isset($configuration[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][$service])) {
        throw new RuntimeException('Missing provider registration for ' . $service);
    }
}

$container = new class implements ContainerInterface {
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new RuntimeException('Unexpected container lookup: ' . $id);
    }
};

$source = imagecreatetruecolor(24, 8);
if ($source === false) {
    throw new RuntimeException('Could not allocate fixture.');
}
imagealphablending($source, false);
imagesavealpha($source, true);
imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
imagefilledrectangle($source, 12, 0, 23, 7, imagecolorallocate($source, 20, 180, 90));

ob_start();
try {
    if (!imagepng($source)) {
        throw new RuntimeException('Could not encode fixture.');
    }
    $png = ob_get_contents();
} finally {
    ob_end_clean();
}
if (!is_string($png) || $png === '') {
    throw new RuntimeException('Empty fixture.');
}

$converter = (new ImageConverterFactory())($container);
$compressor = (new ImageCompressorFactory())($container);
$converted = $converter->convert($png, 'webp', new ImageConversionOptions(quality: 85));
$compressed = $compressor->compress($converted->content, new ImageCompressionOptions(quality: 80));
$decoded = imagecreatefromstring($compressed->content);
if ($compressed->mimeType !== 'image/webp' || $decoded === false || imagesx($decoded) !== 24
    || imagesy($decoded) !== 8 || ((imagecolorat($decoded, 4, 4) >> 24) & 127) !== 127
    || ((imagecolorat($decoded, 20, 4) >> 24) & 127) !== 0) {
    throw new RuntimeException('Combined image pipeline did not preserve format, dimensions or alpha.');
}

echo "Co-installation, providers and PNG -> WebP -> WebP pipeline passed.", PHP_EOL;

$jpeg = $converter->convert($png, 'jpeg', new ImageConversionOptions(quality: 95));
$jpegPixels = imagecreatefromstring($jpeg->content);
$opaquePng = $converter->convert($jpeg->content, 'png', new ImageConversionOptions(compressionLevel: 6));
$recompressed = $compressor->compress($opaquePng->content, new ImageCompressionOptions(compressionLevel: 9));
$pngPixels = imagecreatefromstring($recompressed->content);
if ($jpegPixels === false || $pngPixels === false || ord($opaquePng->content[25]) !== 2
    || $recompressed->mimeType !== 'image/png' || ord($recompressed->content[25]) !== 2
    || imagesx($pngPixels) !== 24 || imagesy($pngPixels) !== 8) {
    throw new RuntimeException('Opaque JPEG-to-PNG integration failed.');
}
for ($y = 0; $y < 8; ++$y) {
    for ($x = 0; $x < 24; ++$x) {
        if (imagecolorat($jpegPixels, $x, $y) !== imagecolorat($pngPixels, $x, $y)) {
            throw new RuntimeException('Lossless PNG round-trip changed decoded JPEG pixels.');
        }
    }
}
echo "PNG -> JPEG -> RGB PNG -> compressed PNG pipeline passed.", PHP_EOL;

// Verify alpha through AVIF decoding, re-encoding and conversion in the same
// consumer process. At maximum AVIF quality the endpoint alpha samples
// should remain distinct.
$avif = $converter->convert($png, 'avif', new ImageConversionOptions(quality: 100, avifSpeed: 10));
$avifCompressed = $compressor->compress($avif->content, new ImageCompressionOptions(quality: 100, avifSpeed: 10));
$avifPng = $converter->convert($avifCompressed->content, 'png');
foreach ([$avif, $avifCompressed, $avifPng] as $index => $result) {
    $expectedMime = $index === 2 ? 'image/png' : 'image/avif';
    $decoded = imagecreatefromstring($result->content);
    if ($result->mimeType !== $expectedMime || $decoded === false
        || imagesx($decoded) !== 24 || imagesy($decoded) !== 8
        || ((imagecolorat($decoded, 4, 4) >> 24) & 127) < 125
        || ((imagecolorat($decoded, 20, 4) >> 24) & 127) > 2) {
        throw new RuntimeException('Combined AVIF pipeline lost format, dimensions or transparency.');
    }
}
echo "PNG -> AVIF -> AVIF -> PNG pipeline passed.", PHP_EOL;

// JPEG orientation must be applied exactly once even with unrelated APP1
// metadata preceding EXIF. The two-stage pipeline also verifies distinct
// internal classes from both co-installed libraries.
$xmp = "http://ns.adobe.com/xap/1.0/\0" . '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>';
$exif = "Exif\0\0II" . pack('vVvvvVv', 42, 8, 1, 0x112, 3, 1, 6) . "\0\0" . pack('V', 0);
$orientedJpeg = substr($jpeg->content, 0, 2)
    . "\xff\xe1" . pack('n', strlen($xmp) + 2) . $xmp
    . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif
    . substr($jpeg->content, 2);
$rotated = $compressor->compress($orientedJpeg, new ImageCompressionOptions(quality: 100));
$rotatedPng = $converter->convert($rotated->content, 'png');
$rotatedPixels = imagecreatefromstring($rotated->content);
$finalPixels = imagecreatefromstring($rotatedPng->content);
if ($rotated->mimeType !== 'image/jpeg' || $rotatedPng->mimeType !== 'image/png'
    || $rotatedPixels === false || $finalPixels === false
    || imagesx($rotatedPixels) !== 8 || imagesy($rotatedPixels) !== 24
    || imagesx($finalPixels) !== 8 || imagesy($finalPixels) !== 24) {
    throw new RuntimeException('EXIF pipeline did not apply orientation exactly once.');
}
$white = imagecolorsforindex($finalPixels, imagecolorat($finalPixels, 4, 4));
$green = imagecolorsforindex($finalPixels, imagecolorat($finalPixels, 4, 20));
if (min($white['red'], $white['green'], $white['blue']) < 220
    || $green['green'] < 140 || $green['green'] <= $green['red'] || $green['green'] <= $green['blue']) {
    throw new RuntimeException('EXIF pipeline did not preserve oriented color regions.');
}
for ($y = 0; $y < 24; ++$y) {
    for ($x = 0; $x < 8; ++$x) {
        if (imagecolorat($rotatedPixels, $x, $y) !== imagecolorat($finalPixels, $x, $y)) {
            throw new RuntimeException('PNG conversion changed rotated JPEG pixels.');
        }
    }
}
echo "JPEG with XMP and EXIF -> JPEG -> PNG pipeline passed.", PHP_EOL;

// Both public orchestration paths must reject a truncated JPEG with a
// valid header, rather than returning GD's repaired image.
$truncated = substr($jpeg->content, 0, -2);
try {
    $compressor->compress($truncated);
    throw new RuntimeException('Compressor accepted a truncated JPEG.');
} catch (\Componenta\Image\CompressionException $error) {
    if (!str_contains($error->getMessage(), 'end marker')) {
        throw $error;
    }
}
try {
    $converter->convert($truncated, 'png');
    throw new RuntimeException('Converter accepted a truncated JPEG.');
} catch (\Componenta\Image\ConversionException $error) {
    if (!str_contains($error->getMessage(), 'end marker')) {
        throw $error;
    }
}
echo "Both packages rejected a truncated JPEG.", PHP_EOL;
