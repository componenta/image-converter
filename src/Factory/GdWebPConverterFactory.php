<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdWebPConverter;
use Psr\Container\ContainerInterface;

/**
 * Factory for creating GdWebPConverter.
 */
final readonly class GdWebPConverterFactory
{
    public function __invoke(ContainerInterface $container): GdWebPConverter
    {
        return new GdWebPConverter();
    }
}
