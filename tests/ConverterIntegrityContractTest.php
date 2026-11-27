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

// Two AV1 frames and a primary still item; the absolute media offsets remain
// unchanged when the complete meta and moov boxes exchange positions.
function converterAvifSequenceFixture(): string
{
    $source = base64_decode('AAAALGZ0eXBhdmlzAAAAAGF2aWZhdmlzbXNmMWlzbzhtaWYxbWlhZk1BMUIAAADrbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAAB5pbG9jAAAAAEQAAAEAAQAAAAEAAAPlAAAAJgAAAChpaW5mAAAAAAABAAAAGmluZmUCAAAAAAEAAGF2MDFDb2xvcgAAAABqaXBycAAAAEtpcGNvAAAAFGlzcGUAAAAAAAAACAAAAAYAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABdpcG1hAAAAAAAAAAEAAQQBAoMEAAACxm1vb3YAAAB4bXZoZAEAAAAAAAAA5y9EkgAAAADnL0SSAAAD6P//////////AAEAAAEAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAJGdHJhawAAAGh0a2hkAQAAAQAAAADnL0SSAAAAAOcvRJIAAAABAAAAAP//////////AAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAIAAAABgAAAAAALGVkdHMAAAAkZWxzdAEAAAEAAAABAAAAAAAAAMgAAAAAAAAAAAABAAAAAAGqbWRpYQAAACxtZGhkAQAAAAAAAADnL0SSAAAAAOcvRJIAAAPoAAAAAAAAAMhVxAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAABVW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAARVzdGJsAAAAlXN0c2QAAAAAAAAAAQAAAIVhdjAxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAgABgBIAAAASAAAAAAAAAABCkFPTSBDb2RpbmcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGP//AAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABBjY3N0AAAAAHwAAAAAAAAYc3R0cwAAAAAAAAABAAAAAgAAAGQAAAAcc3RzYwAAAAAAAAABAAAAAQAAAAIAAAABAAAAHHN0c3oAAAAAAAAAAAAAAAIAAAAmAAAAIAAAABRzdGNvAAAAAAAAAAEAAAPlAAAAFHN0c3MAAAAAAAAAAQAAAAEAAABObWRhdBIACgwAAAABF6bXzICGg0IyFBAAoAAAAkAAAAB5TNXGiE1YU082EgAyHDADwIAAAEagAAACQAAAgAAe5f10Bsh9EBues/k=', true);
    if ($source === false) {
        throw new RuntimeException('Invalid AVIF sequence fixture.');
    }

    return $source;
}

function converterAvifMovieFirst(string $brand): string
{
    $source = converterAvifSequenceFixture();
    $ftypSize = unpack('N', $source, 0)[1];
    $metaSize = unpack('N', $source, $ftypSize)[1];
    $moovOffset = $ftypSize + $metaSize;
    $moovSize = unpack('N', $source, $moovOffset)[1];
    // Both the major and compatible avis brands are removed, but avif remains
    // a compatible brand. Reordering boxes does not move the mdat payload.
    $ftyp = str_replace('avis', $brand, substr($source, 0, $ftypSize));

    return $ftyp . substr($source, $moovOffset, $moovSize)
        . substr($source, $ftypSize, $metaSize)
        . substr($source, $moovOffset + $moovSize);
}

test('real two-frame AVIF sequence is not silently converted into a still image', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding is unavailable.');
    }
    // Generated with Pillow/libavif from red and blue 8x6 frames.
    $source = converterAvifSequenceFixture();
    expect($source)->toBeString();
    expect(substr($source, 8, 4))->toBe('avis');
    $info = getimagesizefromstring($source);
    expect($info)->toBeArray();
    expect($info[0])->toBe(8);
    expect($info[1])->toBe(6);
    expect($info['mime'])->toBe('image/avif');
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class, 'Animated AVIF');
});

test('AVIF with an ambiguous major brand and movie tracks is rejected', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding is unavailable.');
    }
    $source = converterAvifMovieFirst('mif1');
    expect(substr($source, 8, 4))->toBe('mif1');
    expect(getimagesizefromstring($source)['mime'])->toBe('image/avif');
    expect(imagecreatefromstring($source))->toBeInstanceOf(GdImage::class);
    expect(fn() => $codec->convert($source))
        ->toThrow(ConversionException::class, 'AVIF movie tracks');
});

test('AVIF with an explicit still major brand uses the primary item', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding is unavailable.');
    }
    $source = converterAvifMovieFirst('avif');
    expect(substr($source, 8, 4))->toBe('avif');
    $decoded = imagecreatefromstring($codec->convert($source)->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([8, 6]);
});

test('AVIF with a compatible avif brand and no movie box remains supported', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding is unavailable.');
    }
    $image = imagecreatetruecolor(16, 8);
    $source = EncodedOutput::capture(static fn(): bool => imageavif($image, null, 80));
    // GD includes avif in the compatible brand list.
    $source = substr_replace($source, 'mif1', 8, 4);
    expect(getimagesizefromstring($source)['mime'])->toBe('image/avif');
    $decoded = imagecreatefromstring($codec->convert($source)->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([16, 8]);
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
