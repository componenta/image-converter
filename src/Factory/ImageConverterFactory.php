<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Detector\FinfoDetector;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConverter;
use Psr\Container\ContainerInterface;

final readonly class ImageConverterFactory
{
    public function __invoke(ContainerInterface $container): ImageConverter
    {
        $detector = $container->has(MimeTypeDetectorInterface::class)
            ? $container->get(MimeTypeDetectorInterface::class)
            : new FinfoDetector();

        return new ImageConverter(
            detector: $detector,
            converters: [
                $container->has(GdWebPConverter::class) ? $container->get(GdWebPConverter::class) : new GdWebPConverter(),
                $container->has(GdJpegConverter::class) ? $container->get(GdJpegConverter::class) : new GdJpegConverter(),
                $container->has(GdPngConverter::class) ? $container->get(GdPngConverter::class) : new GdPngConverter(),
                $container->has(GdAvifConverter::class) ? $container->get(GdAvifConverter::class) : new GdAvifConverter(),
            ],
        );
    }
}
