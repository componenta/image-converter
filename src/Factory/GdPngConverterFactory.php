<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Factory;

use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\ImageConversionLimits;
use Psr\Container\ContainerInterface;

final readonly class GdPngConverterFactory
{
    public function __invoke(ContainerInterface $container): GdPngConverter
    {
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new GdPngConverter(limits: $limits);
    }
}
