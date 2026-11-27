<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdAvifConverter;
use Componenta\Image\ImageConversionLimits;
use Psr\Container\ContainerInterface;

final readonly class GdAvifConverterFactory
{
    public function __invoke(ContainerInterface $container): GdAvifConverter
    {
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new GdAvifConverter(limits: $limits);
    }
}
