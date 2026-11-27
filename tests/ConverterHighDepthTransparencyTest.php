<?php

declare(strict_types=1);

use Componenta\Image\ConversionException;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdWebPConverter;

// Independently assembled PNGs with correct CRCs and 16-bit samples.
// The first patch matches tRNS; the second differs only in the low bytes.
// GD strips those bytes and collapses distinct transparent and opaque colors.
test('rejects high-depth PNG color keys before GD can collapse distinct samples', function (string $fixture, string $target): void {
    $codec = match ($target) {
        'jpeg' => new GdJpegConverter(),
        'webp' => new GdWebPConverter(),
        'avif' => new GdAvifConverter(),
    };
    if (!$codec->supports('image/png')) {
        $this->markTestSkipped('Required codec unavailable.');
    }
    $source = base64_decode($fixture, true);
    expect($source)->toBeString();
    $info = getimagesizefromstring($source);
    expect($info['bits'])->toBe(16);
    expect([$info[0], $info[1]])->toBe([48, 16]);
    $native = imagecreatefromstring($source);
    expect($native)->toBeInstanceOf(GdImage::class);
    $key = imagecolortransparent($native);
    expect($key)->toBeGreaterThanOrEqual(0);
    expect(imagecolorat($native, 8, 8))->toBe($key);
    expect(imagecolorat($native, 24, 8))->toBe($key);
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class, '16-bit PNG color-key transparency');
})->with([
    'grayscale tRNS' => 'iVBORw0KGgoAAAANSUhEUgAAADAAAAAQEAAAAAAlVX8uAAAAAnRSTlMSNC/TSV4AAAAiSURBVHicYxAyIQD/44cTGPBDhlELRi0YtWDUglELhoIFABSF5xDTF2YoAAAAAElFTkSuQmCC',
    'RGB tRNS' => 'iVBORw0KGgoAAAANSUhEUgAAADAAAAAQEAIAAACPXLelAAAABnRSTlMSNCNFNFaJ7fKKAAAAR0lEQVR4nO3QQRWAMAwFsK8BC7NQC7OALiQgAYedil54uURActXadQ/aq6vnfPLmy5wRJEiQIEGCBAkSJEiQIEGCBAn6V9AB1Ox+W2BGTIgAAAAASUVORK5CYII=',
])->with(['jpeg', 'webp', 'avif']);

test('16-bit PNGs without a color key remain convertible', function (string $fixture, bool $alpha, string $target): void {
    $codec = match ($target) {
        'jpeg' => new GdJpegConverter(defaultQuality: 100),
        'webp' => new GdWebPConverter(defaultQuality: 100),
        'avif' => new GdAvifConverter(defaultQuality: 100),
    };
    if (!$codec->supports('image/png')) {
        $this->markTestSkipped('Required codec unavailable.');
    }
    $source = base64_decode($fixture, true);
    expect(getimagesizefromstring($source)['bits'])->toBe(16);
    $result = $codec->convert($source);
    expect($result->mimeType)->toBe('image/' . $target);
    $decoded = imagecreatefromstring($result->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([48, 16]);
    if ($alpha && $target !== 'jpeg') {
        expect(imagecolorsforindex($decoded, imagecolorat($decoded, 8, 8))['alpha'])->toBe(127);
        expect(imagecolorsforindex($decoded, imagecolorat($decoded, 24, 8))['alpha'])->toBe(0);
        expect(abs(imagecolorsforindex($decoded, imagecolorat($decoded, 40, 8))['alpha'] - 63))->toBeLessThanOrEqual(1);
    }
})->with([
    'opaque grayscale' => ['iVBORw0KGgoAAAANSUhEUgAAADAAAAAQEAAAAAAlVX8uAAAAIklEQVR4nGMQMiEA/+OHExjwQ4ZRC0YtGLVg1IJRC4aCBQAUhecQ0xdmKAAAAABJRU5ErkJggg==', false],
    'opaque RGB' => ['iVBORw0KGgoAAAANSUhEUgAAADAAAAAQEAIAAACPXLelAAAAR0lEQVR4nO3QQRWAMAwFsK8BC7NQC7OALiQgAYedil54uURActXadQ/aq6vnfPLmy5wRJEiQIEGCBAkSJEiQIEGCBAn6V9AB1Ox+W2BGTIgAAAAASUVORK5CYII=', false],
    'explicit RGBA alpha' => ['iVBORw0KGgoAAAANSUhEUgAAADAAAAAQEAYAAAAAPiDyAAAAU0lEQVR4nO3RMQGAQAwEwdOAhbcQC1hADxJeAhJwGGSkYKrtd3LUOutKxtqrq7unuvPkzZ2pzo0HAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/AvgA7/n8eTPmBHYAAAAASUVORK5CYII=', true],
])->with(['jpeg', 'webp', 'avif']);
