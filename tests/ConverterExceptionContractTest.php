<?php

declare(strict_types=1);

use Componenta\Image\Converter\Exception\ConversionConfigurationException;
use Componenta\Image\Converter\Exception\ConversionExceptionInterface;
use Componenta\Image\Converter\Exception\ConversionFileExceptionInterface;
use Componenta\Image\Converter\Exception\ConversionStreamExceptionInterface;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\ImageConversionLimits;

test('invalid codec and resource configuration implements the domain contract', function (): void {
    foreach ([
        fn() => new ImageConversionLimits(maxPixels: 0),
        fn() => new GdJpegConverter(defaultQuality: -1),
        fn() => new GdWebPConverter(defaultQuality: 101),
        fn() => new GdAvifConverter(defaultQuality: -1),
        fn() => new GdPngConverter(defaultCompressionLevel: 10),
    ] as $operation) {
        try {
            $operation();
            $this->fail('Expected invalid configuration.');
        } catch (ConversionExceptionInterface $error) {
            expect($error)->toBeInstanceOf(ConversionConfigurationException::class);
            expect($error)->toBeInstanceOf(InvalidArgumentException::class);
            expect($error)->not->toBeInstanceOf(ConversionFileExceptionInterface::class);
            expect($error)->not->toBeInstanceOf(ConversionStreamExceptionInterface::class);
        }
    }
});
