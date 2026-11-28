<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Factory;

use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\ImageConversionLimits;
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
