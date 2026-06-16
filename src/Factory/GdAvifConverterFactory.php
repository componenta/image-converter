<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdAvifConverter;
use Psr\Container\ContainerInterface;

final readonly class GdAvifConverterFactory
{
    public function __invoke(ContainerInterface $container): GdAvifConverter
    {
        return new GdAvifConverter();
    }
}
