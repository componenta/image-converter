<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Factory\GdWebPConverterFactory;
use Componenta\Image\Factory\GdAvifConverterFactory;
use Componenta\Image\Factory\GdJpegConverterFactory;
use Componenta\Image\Factory\GdPngConverterFactory;
use Componenta\Image\Factory\ImageConverterFactory;

class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ImageConverter::class => ImageConverterFactory::class,
            GdAvifConverter::class => GdAvifConverterFactory::class,
            GdJpegConverter::class => GdJpegConverterFactory::class,
            GdPngConverter::class => GdPngConverterFactory::class,
            GdWebPConverter::class => GdWebPConverterFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            ImageConverterInterface::class => GdWebPConverter::class,
        ];
    }
}
