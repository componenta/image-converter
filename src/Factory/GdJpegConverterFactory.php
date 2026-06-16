<?php

declare(strict_types=1);

namespace Componenta\Image\Factory;

use Componenta\Image\GdJpegConverter;
use Psr\Container\ContainerInterface;

final readonly class GdJpegConverterFactory
{
    public function __invoke(ContainerInterface $container): GdJpegConverter
    {
        return new GdJpegConverter();
    }
}
