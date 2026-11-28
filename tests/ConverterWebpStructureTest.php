<?php

declare(strict_types=1);

use Componenta\Image\ConversionException;
use Componenta\Image\GdPngConverter;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\Utils;

function webpFramingFixture(): string
{
    $image = imagecreatetruecolor(32, 24);
    imagefilledrectangle($image, 0, 0, 15, 23, imagecolorallocate($image, 30, 60, 220));

    return EncodedOutput::capture(static fn(): bool => imagewebp($image, null, 80));
}

function webpFramingAppend(string $raw, string $tail): string
{
    // The added bytes are part of the declared RIFF container, not trailing data.
    return 'RIFF' . pack('V', strlen($raw) + strlen($tail) - 8) . substr($raw, 8) . $tail;
}

test('WebP rejects incomplete RIFF framing even after valid image data', function (string $kind): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/webp')) {
        $this->markTestSkipped('WebP decoding unavailable.');
    }
    $raw = webpFramingFixture();
    $source = match ($kind) {
        'declared bytes missing' => 'RIFF' . pack('V', strlen($raw) + 32) . substr($raw, 8),
        'partial chunk header' => webpFramingAppend($raw, 'EXIF'),
        'chunk exceeds RIFF boundary' => webpFramingAppend($raw, 'EXIF' . pack('V', 100) . 'data'),
        'missing padding' => webpFramingAppend($raw, 'JUNK' . pack('V', 1) . 'x'),
        'nonzero padding' => webpFramingAppend($raw, 'JUNK' . pack('V', 1) . 'xy'),
        'empty RIFF with trailing pixels' => 'RIFF' . pack('V', 4) . substr($raw, 8),
    };
    $stream = Utils::streamFor($source);
    $stream->seek(3);
    foreach ([$source, $stream] as $input) {
        expect(fn() => $codec->convert($input))->toThrow(ConversionException::class);
    }
    expect($stream->tell())->toBe(3);
})->with([
    'declared bytes missing', 'partial chunk header', 'chunk exceeds RIFF boundary',
    'missing padding', 'nonzero padding', 'empty RIFF with trailing pixels',
]);

test('WebP accepts complete padded chunks and ignores bytes after the declared RIFF envelope', function (string $kind): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/webp')) {
        $this->markTestSkipped('WebP decoding unavailable.');
    }
    $raw = webpFramingFixture();
    $source = match ($kind) {
        'plain' => $raw,
        'even chunk' => webpFramingAppend($raw, 'JUNK' . pack('V', 2) . 'xy'),
        'odd chunk with padding' => webpFramingAppend($raw, 'JUNK' . pack('V', 1) . "x\0"),
        'trailing bytes' => $raw . 'EXIF' . pack('V', 100) . 'ignored',
    };
    $result = $codec->convert($source);
    $decoded = imagecreatefromstring($result->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([32, 24]);
})->with(['plain', 'even chunk', 'odd chunk with padding', 'trailing bytes']);
