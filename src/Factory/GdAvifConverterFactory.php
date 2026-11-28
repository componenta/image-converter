<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Factory;

use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\ImageConversionLimits;
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
