<?php

declare(strict_types=1);

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Internal\EncodedOutput;
use Componenta\Image\Converter\Internal\WebpStructure;
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


function webpStructureChunk(string $type, string $data): string
{
    return $type . pack('V', strlen($data)) . $data . ((strlen($data) & 1) !== 0 ? "\0" : '');
}

function webpStructureContainer(string $chunks): string
{
    return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
}

/** Extract one complete still-image bitstream, including its RIFF padding. */
function webpStructureBitstream(string $raw): string
{
    for ($offset = 12; $offset + 8 <= strlen($raw);) {
        $type = substr($raw, $offset, 4);
        $length = unpack('Vlength', $raw, $offset + 4)['length'];
        $span = 8 + $length + ($length & 1);
        if ($type === 'VP8 ' || $type === 'VP8L') {
            return substr($raw, $offset, $span);
        }
        $offset += $span;
    }

    throw new RuntimeException('Fixture has no image bitstream.');
}

function webpStructureExtendedHeader(): string
{
    return webpStructureChunk('VP8X', "\0\0\0\0\x1f\0\0\x17\0\0");
}

test('WebP still-image structure rejects ambiguous reconstruction chunks', function (string $kind, string $message): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/webp')) {
        $this->markTestSkipped('WebP decoding unavailable.');
    }
    $bitstream = webpStructureBitstream(webpFramingFixture());
    $header = webpStructureExtendedHeader();
    $source = webpStructureContainer(match ($kind) {
        'duplicate bitstream' => $bitstream . $bitstream,
        'duplicate extended header' => $header . $header . $bitstream,
        'late extended header' => $bitstream . $header,
        'short extended header' => webpStructureChunk('VP8X', "\0") . $bitstream,
        'unflagged frame' => $header . $bitstream . webpStructureChunk('ANMF', str_repeat("\0", 6) . "\x1f\0\0\x17\0\0\x01\0\0\0" . $bitstream),
        'missing image' => $header,
    });

    expect(fn() => WebpStructure::assertStatic($source))->toThrow(ConversionException::class, $message);
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class);
})->with([
    ['duplicate bitstream', 'Multiple WebP image bitstreams'],
    ['duplicate extended header', 'Invalid or duplicate WebP extended header'],
    ['late extended header', 'Invalid or duplicate WebP extended header'],
    ['short extended header', 'Invalid or duplicate WebP extended header'],
    ['unflagged frame', 'Animated WebP'],
    ['missing image', 'Missing WebP image bitstream'],
]);

test('WebP still-image guard ignores unflagged animation control and permits valid lossless bitstreams', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/webp')) {
        $this->markTestSkipped('WebP decoding unavailable.');
    }
    $raw = webpFramingFixture();
    $bitstream = webpStructureBitstream($raw);
    // Section 2.7.1.1: ANIM alone is ignored when the animation flag is clear.
    $source = webpStructureContainer(webpStructureExtendedHeader() . webpStructureChunk('ANIM', str_repeat("\0", 6)) . $bitstream);
    WebpStructure::assertStatic($source);
    expect(imagecreatefromstring($codec->convert($source)->content))->toBeInstanceOf(GdImage::class);

    $image = imagecreatetruecolor(32, 24);
    $lossless = EncodedOutput::capture(static fn(): bool => imagewebp($image, null, IMG_WEBP_LOSSLESS));
    WebpStructure::assertStatic($lossless);
    expect(imagecreatefromstring($codec->convert($lossless)->content))->toBeInstanceOf(GdImage::class);
});
