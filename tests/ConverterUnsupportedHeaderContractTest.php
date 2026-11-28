<?php

declare(strict_types=1);

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Codec\GdPngConverter;

test('direct codecs reject unsupported compressed formats before native header parsing', function (): void {
    // Small valid SWF header plus compressed padding. The fixture is generated
    // in-process and limited to 1 MiB of decompressed data.
    $payload = "\x78\x00\x05\x5f\x00\x00\x0f\xa0\x00" . str_repeat("\0", 1048576);
    $source = "CWS\x09" . pack('V', 8 + strlen($payload)) . gzcompress($payload);
    unset($payload);
    expect(strlen($source))->toBeLessThan(4096);
    // getimagesize supports compressed SWF, but the raster codec must not
    // invoke that parser at all for an unsupported source format.
    expect(getimagesizefromstring($source)['mime'])->toBe('application/x-shockwave-flash');
    expect(fn() => (new GdPngConverter())->convert($source))
        ->toThrow(ConversionException::class, 'Unsupported source image signature');
});

test('a fake file type box does not make an unsupported signature eligible', function (): void {
    $source = "CWS\x09ftypavif\0\0\0\0avif";
    expect(fn() => (new GdPngConverter())->convert($source))
        ->toThrow(ConversionException::class, 'Unsupported source image signature');
});

test('unrelated recognized image formats are rejected before geometry parsing', function (): void {
    $source = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    expect(fn() => (new GdPngConverter())->convert($source))
        ->toThrow(ConversionException::class, 'Unsupported source image signature');
});
