<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConversionLimits;
use Psr\Container\ContainerInterface;

final readonly class GdWebPConverterFactory
{
    public function __invoke(ContainerInterface $container): GdWebPConverter
    {
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new GdWebPConverter(limits: $limits);
    }
}
