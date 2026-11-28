<?php

declare(strict_types=1);

namespace Componenta\Image\Converter;

use Componenta\Image\Converter\Contract\ImageConverterInterface;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\Factory\GdWebPConverterFactory;
use Componenta\Image\Converter\Factory\GdAvifConverterFactory;
use Componenta\Image\Converter\Factory\GdJpegConverterFactory;
use Componenta\Image\Converter\Factory\GdPngConverterFactory;
use Componenta\Image\Converter\Factory\ImageConverterFactory;

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
