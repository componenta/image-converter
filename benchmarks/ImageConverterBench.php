<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Benchmarks;

use Componenta\Detector\FinfoDetector;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\ImageConverter;
use Componenta\Image\Converter\ImageConversionOptions;
use GuzzleHttp\Psr7\Utils;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('cases')]
#[Bench\Revs(1)]
#[Bench\Iterations(3)]
final class ImageConverterBench
{
    private object $service;
    /** @var class-string */
    private string $optionsClass;
    private string $source;
    private bool $baseline = false;

    /** @param array{source: string, target: string, width: int, height: int, stream: bool} $params */
    public function setUp(array $params): void
    {
        $current = class_exists(ImageConverter::class);
        $serviceClass = $current ? ImageConverter::class : \Componenta\Image\ImageConverter::class;
        $optionsClass = $current ? ImageConversionOptions::class : \Componenta\Image\ImageConversionOptions::class;
        $jpegClass = $current ? GdJpegConverter::class : \Componenta\Image\GdJpegConverter::class;
        $pngClass = $current ? GdPngConverter::class : \Componenta\Image\GdPngConverter::class;
        $webpClass = $current ? GdWebPConverter::class : \Componenta\Image\GdWebPConverter::class;
        $avifClass = $current ? GdAvifConverter::class : \Componenta\Image\GdAvifConverter::class;
        $this->optionsClass = $optionsClass;
        $file = (new \ReflectionClass($serviceClass))->getFileName();
        $this->baseline = is_string($file) && str_contains($file, '/.benchmark-baseline/');
        if ($this->baseline !== (getenv('IMAGE_BENCH_BASELINE') === '1')) {
            throw new \RuntimeException('Benchmark resolved the wrong library version: ' . $file);
        }
        $expectedRoot = getenv('IMAGE_BENCH_EXPECTED_ROOT');
        if ($expectedRoot !== false && (!is_string($file) || !str_starts_with($file, $expectedRoot . '/'))) {
            throw new \RuntimeException('Benchmark resolved a different checkout: ' . $file);
        }
        $image = imagecreatetruecolor($params['width'], $params['height']);
        if ($image === false) {
            throw new \RuntimeException('Unable to allocate fixture.');
        }
        for ($y = 0; $y < $params['height']; $y += 32) {
            for ($x = 0; $x < $params['width']; $x += 32) {
                $color = imagecolorallocate($image, $x % 251, $y % 253, ($x + $y) % 255);
                imagefilledrectangle($image, $x, $y, $x + 31, $y + 31, $color);
            }
        }
        ob_start();
        try {
            $ok = match ($params['source']) {
                'jpeg' => imagejpeg($image, null, 90),
                'png' => imagepng($image, null, 6),
                'webp' => imagewebp($image, null, 90),
                'avif' => imageavif($image, null, 90),
                'bmp' => imagebmp($image),
            };
            $source = ob_get_contents();
            if (!$ok || !is_string($source) || $source === '') {
                throw new \RuntimeException('Unable to encode fixture.');
            }
            $this->source = $source;
        } finally {
            ob_end_clean();
        }
        $this->service = new $serviceClass(new FinfoDetector(), [
            new $jpegClass(), new $pngClass(), new $webpClass(), new $avifClass(),
        ]);
    }

    /** @param array{source: string, target: string, width: int, height: int, stream: bool} $params */
    public function benchProcess(array $params): void
    {
        $source = $params['stream'] ? Utils::streamFor($this->source) : $this->source;
        $optionsClass = $this->optionsClass;
        if ($this->baseline) {
            // v1 maps quality=33 to PNG compression level 6.
            $quality = $params['target'] === 'png' ? 33 : 80;
            $this->service->convert($source, $params['target'], $quality);
            return;
        }
        $options = $params['target'] === 'png'
            ? new $optionsClass(compressionLevel: 6)
            : new $optionsClass(quality: 80);
        $this->service->convert($source, $params['target'], $options);
    }

    /** @return iterable<string, array{source: string, target: string, width: int, height: int, stream: bool}> */
    public function cases(): iterable
    {
        $pairs = [['jpeg', 'webp'], ['png', 'jpeg'], ['jpeg', 'png'], ['png', 'webp'], ['webp', 'avif'], ['avif', 'webp'], ['bmp', 'png']];
        $compare = getenv('IMAGE_BENCH_COMPARE') === '1';
        foreach ($pairs as [$source, $target]) {
            if ($compare && $source === 'avif' && $target === 'webp') {
                // AVIF -> WebP was not advertised by v1.0.1.
                continue;
            }

            foreach ([[1000, 1000], [2000, 2000], [4000, 3000]] as [$width, $height]) {
                // A 12 MP uncompressed BMP exceeds the default 32 MiB input limit.
                if ($source === 'bmp' && $width * $height > 10000000) {
                    continue;
                }
                if ($compare && $width === 4000 && !in_array($target, ['jpeg', 'png'], true)) {
                    continue;
                }
                foreach ([false, true] as $stream) {
                    if ($compare && $stream && $width !== 1000) {
                        continue;
                    }
                    yield sprintf('%s-%s-%dx%d-%s', $source, $target, $width, $height, $stream ? 'stream' : 'bytes') => [
                        'source' => $source, 'target' => $target,
                        'width' => $width, 'height' => $height, 'stream' => $stream,
                    ];
                }
            }
        }
    }
}
