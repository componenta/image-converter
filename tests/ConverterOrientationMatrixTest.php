<?php

declare(strict_types=1);

use Componenta\Image\GdPngConverter;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\ImageConversionLimits;
use Componenta\Image\ConversionException;
use Componenta\Image\Converter\Internal\EncodedOutput;

function converterOrientationFixture(int $orientation, bool $littleEndian): string
{
    $image = imagecreatetruecolor(40, 24);
    $colors = [[240, 10, 10], [10, 240, 10], [10, 10, 240], [240, 240, 10]];
    foreach ($colors as $index => [$r, $g, $b]) {
        $x = ($index % 2) * 20;
        $y = intdiv($index, 2) * 12;
        imagefilledrectangle($image, $x, $y, $x + 19, $y + 11, imagecolorallocate($image, $r, $g, $b));
    }
    $jpeg = EncodedOutput::capture(static fn(): bool => imagejpeg($image, null, 100));
    $exif = $littleEndian
        ? "Exif\\0\\0II" . pack('vVvvvVv', 42, 8, 1, 0x112, 3, 1, $orientation) . "\\0\\0" . pack('V', 0)
        : "Exif\\0\\0MM" . pack('nNnnnNn', 42, 8, 1, 0x112, 3, 1, $orientation) . "\\0\\0" . pack('N', 0);
    return substr($jpeg, 0, 2) . "\\xff\\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
}

test('all EXIF orientations preserve visual corner positions', function (int $orientation, array $corners, bool $littleEndian): void {
    $source = converterOrientationFixture($orientation, $littleEndian);
    $result = (new GdPngConverter())->convert($source);
    $decoded = imagecreatefromstring($result->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    $width = $orientation >= 5 ? 24 : 40;
    $height = $orientation >= 5 ? 40 : 24;
    expect(imagesx($decoded))->toBe($width);
    expect(imagesy($decoded))->toBe($height);
    $palette = [[240, 10, 10], [10, 240, 10], [10, 10, 240], [240, 240, 10]];
    foreach ($corners as $index => $color) {
        $x = intdiv($width * (($index % 2) * 2 + 1), 4);
        $y = intdiv($height * (intdiv($index, 2) * 2 + 1), 4);
        $pixel = imagecolorat($decoded, $x, $y);
        $actual = [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
        foreach ($palette[$color] as $channel => $expected) {
            expect(abs($actual[$channel] - $expected))->toBeLessThan(40);
        }
    }
})->with([
    '1 little' => [1, [0, 1, 2, 3], true],
    '2 little' => [2, [1, 0, 3, 2], true],
    '3 little' => [3, [3, 2, 1, 0], true],
    '4 little' => [4, [2, 3, 0, 1], true],
    '5 little' => [5, [0, 2, 1, 3], true],
    '6 little' => [6, [2, 0, 3, 1], true],
    '7 little' => [7, [3, 1, 2, 0], true],
    '8 little' => [8, [1, 3, 0, 2], true],
    '1 big' => [1, [0, 1, 2, 3], false],
    '2 big' => [2, [1, 0, 3, 2], false],
    '3 big' => [3, [3, 2, 1, 0], false],
    '4 big' => [4, [2, 3, 0, 1], false],
    '5 big' => [5, [0, 2, 1, 3], false],
    '6 big' => [6, [2, 0, 3, 1], false],
    '7 big' => [7, [3, 1, 2, 0], false],
    '8 big' => [8, [1, 3, 0, 2], false],
]);

test('rotation is subject to both dimension limits', function (): void {
    $codec = new GdPngConverter(limits: new ImageConversionLimits(maxHeight: 30));
    expect(fn() => $codec->convert(converterOrientationFixture(6, true)))
        ->toThrow(ConversionException::class, 'Oriented image dimensions');
});
