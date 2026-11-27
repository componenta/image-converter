<?php

declare(strict_types=1);

use Componenta\Image\ConversionException;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;

use Componenta\Image\ImageConversionLimits;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\Utils;

function converterIntegrityFixture(string $format): string
{
    $image = imagecreatetruecolor(32, 16);
    imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 230, 20, 10));
    imagefilledrectangle($image, 16, 0, 31, 15, imagecolorallocate($image, 10, 30, 230));

    return EncodedOutput::capture(static fn(): bool => match ($format) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image),
    });
}

test('rejects corrupted inputs without altering the surrounding error handler or output', function (string $format): void {
    $codec = $format === 'png' ? new GdJpegConverter() : new GdPngConverter();
    $source = converterIntegrityFixture($format);
    $broken = substr($source, 0, $format === 'png' ? 33 : 12);
    $handler = static fn(int $severity, string $message): bool => true;
    set_error_handler($handler);
    ob_start();
    try {
        echo 'parent';
        $level = ob_get_level();
        foreach (['', 'not an image', $broken] as $data) {
            expect(fn() => $codec->convert($data))->toThrow(ConversionException::class);
            expect(ob_get_level())->toBe($level);
            expect(ob_get_contents())->toBe('parent');
            $installed = set_error_handler(static fn(): bool => true);
            restore_error_handler();
            expect($installed)->toBe($handler);
        }
        for ($i = 0; $i < 10; ++$i) {
            $decoded = imagecreatefromstring($codec->convert($source)->content);
            expect($decoded)->toBeInstanceOf(GdImage::class);
            expect(imagesx($decoded))->toBe(32);
            expect(imagesy($decoded))->toBe(16);
            expect(ob_get_level())->toBe($level);
            expect(ob_get_contents())->toBe('parent');
        }
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
})->with(['png', 'jpeg']);

test('real two-frame AVIF sequence is not silently converted into a still image', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding is unavailable.');
    }
    // Generated with Pillow/libavif from red and blue 8x6 frames.
    $source = base64_decode('AAAALGZ0eXBhdmlzAAAAAGF2aWZhdmlzbXNmMWlzbzhtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAPlAAAAJwAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEAAGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAYAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAACxm1vb3YAAAB4bXZoZAEAAAAAAAAA5y9C+gAAAADnL0L6AAAD6P//////////AAEAAAEAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAJGdHJhawAAAGh0a2hkAQAAAQAAAADnL0L6AAAAAOcvQvoAAAABAAAAAP//////////AAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAIAAAABgAAAAAALGVkdHMAAAAkZWxzdAEAAAEAAAABAAAAAAAAAMgAAAAAAAAAAAABAAAAAAGqbWRpYQAAACxtZGhkAQAAAAAAAADnL0L6AAAAAOcvQvoAAAPoAAAAAAAAAMhVxAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAABVW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAARVzdGJsAAAAlXN0c2QAAAAAAAAAAQAAAIVhdjAxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAgABgBIAAAASAAAAAAAAAABCkFPTSBDb2RpbmcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGP//AAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABBjY3N0AAAAAHwAAAAAAAAYc3R0cwAAAAAAAAABAAAAAgAAAGQAAAAcc3RzYwAAAAAAAAABAAAAAQAAAAIAAAABAAAAHHN0c3oAAAAAAAAAAAAAAAIAAAAnAAAAIAAAABRzdGNvAAAAAAAAAAEAAAPlAAAAFHN0c3MAAAAAAAAAAQAAAAEAAABPbWRhdBIACgwAAAABF6bXzICGg0IyFRAAoAAAAkAAAEZp7e7s1VRRxDbYuBIAMhwwA8CAAABGoAAAAkAAAIAAHuX9dDxrfRBeYbQB', true);
    expect($source)->toBeString();
    expect(substr($source, 8, 4))->toBe('avis');
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class);
});

test('exact byte and geometry limits work with seekable streams', function (): void {
    $source = converterIntegrityFixture('png');
    $codec = new GdJpegConverter(limits: new ImageConversionLimits(
        maxInputBytes: strlen($source),
        maxPixels: 512,
        maxWidth: 32,
        maxHeight: 16,
    ));
    $stream = Utils::streamFor($source);
    $stream->seek(5);
    $result = $codec->convert($stream);
    expect($stream->tell())->toBe(5);
    expect(imagecreatefromstring($result->content))->toBeInstanceOf(GdImage::class);
});

test('one pixel over the area limit is rejected before decoding', function (): void {
    $source = converterIntegrityFixture('png');
    $codec = new GdJpegConverter(limits: new ImageConversionLimits(maxPixels: 511));
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class, 'dimensions exceed');
});
