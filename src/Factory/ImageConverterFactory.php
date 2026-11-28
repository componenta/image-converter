<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Factory;

use Componenta\Detector\FinfoDetector;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\ImageConverter;
use Componenta\Image\Converter\ImageConversionLimits;
use Psr\Container\ContainerInterface;

final readonly class ImageConverterFactory
{
    public function __invoke(ContainerInterface $container): ImageConverter
    {
        $detector = $container->has(MimeTypeDetectorInterface::class)
            ? $container->get(MimeTypeDetectorInterface::class)
            : new FinfoDetector();
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new ImageConverter(
            detector: $detector,
            converters: [
                $container->has(GdWebPConverter::class) ? $container->get(GdWebPConverter::class) : new GdWebPConverter(limits: $limits),
                $container->has(GdJpegConverter::class) ? $container->get(GdJpegConverter::class) : new GdJpegConverter(limits: $limits),
                $container->has(GdPngConverter::class) ? $container->get(GdPngConverter::class) : new GdPngConverter(limits: $limits),
                $container->has(GdAvifConverter::class) ? $container->get(GdAvifConverter::class) : new GdAvifConverter(limits: $limits),
            ],
            limits: $limits,
        );
    }
}
