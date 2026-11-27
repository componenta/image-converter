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
