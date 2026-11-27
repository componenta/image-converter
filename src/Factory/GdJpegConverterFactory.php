<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdJpegConverter;
use Componenta\Image\ImageConversionLimits;
use Psr\Container\ContainerInterface;

final readonly class GdJpegConverterFactory
{
    public function __invoke(ContainerInterface $container): GdJpegConverter
    {
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new GdJpegConverter(limits: $limits);
    }
}
