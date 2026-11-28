<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Factory;

use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\ImageConversionLimits;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

final readonly class GdPngConverterFactory
{
    /** @throws ContainerExceptionInterface */
    public function __invoke(ContainerInterface $container): GdPngConverter
    {
        $limits = $container->has(ImageConversionLimits::class)
            ? $container->get(ImageConversionLimits::class)
            : new ImageConversionLimits();

        return new GdPngConverter(limits: $limits);
    }
}
