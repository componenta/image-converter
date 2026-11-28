<?php

declare(strict_types=1);

use Componenta\Image\ConversionConfigurationException;
use Componenta\Image\ConversionExceptionInterface;
use Componenta\Image\ConversionFileExceptionInterface;
use Componenta\Image\ConversionStreamExceptionInterface;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConversionLimits;

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
