<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdPngConverter;
use Psr\Container\ContainerInterface;

final readonly class GdPngConverterFactory
{
    public function __invoke(ContainerInterface $container): GdPngConverter
    {
        return new GdPngConverter();
    }
}
