<?php

declare(strict_types=1);

use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\ImageConversionOptions;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\Utils;

/** Build a real Windows BITMAPINFOHEADER / BI_RGB image, with odd-width padding. */
function converterBmp24Fixture(bool $topDown): string
{
    $width = 7;
    $height = 5;
    $stride = ($width * 3 + 3) & ~3;
    $pixels = '';
    for ($row = 0; $row < $height; ++$row) {
        $y = $topDown ? $row : $height - 1 - $row;
        for ($x = 0; $x < $width; ++$x) {
            $pixels .= pack('C3', ($x * 31 + $y * 9) % 256, ($x * 7 + $y * 51) % 256, ($x * 43 + $y * 13) % 256);
        }
        $pixels .= str_repeat("\0", $stride - $width * 3);
    }

    return 'BM' . pack('VvvV', 54 + strlen($pixels), 0, 0, 54)
        . pack('V3v2V6', 40, $width, $topDown ? -$height : $height, 1, 24, 0, strlen($pixels), 0, 0, 0, 0)
        . $pixels;
}

test('opaque 24-bit BMP is encoded as RGB PNG without changing pixels', function (bool $topDown, bool $asStream, int $level): void {
    $source = converterBmp24Fixture($topDown);
    $info = getimagesizefromstring($source);
    expect($info['mime'])->toBe('image/bmp');
    expect($info['bits'])->toBe(24);
    expect([$info[0], $info[1]])->toBe([7, 5]);
    $native = imagecreatefromstring($source);
    expect($native)->toBeInstanceOf(GdImage::class);
    $input = $asStream ? Utils::streamFor($source) : $source;
    if ($asStream) {
        $input->seek(9);
    }

    $result = (new GdPngConverter())->convert($input, new ImageConversionOptions(compressionLevel: $level));
    if ($asStream) {
        expect($input->tell())->toBe(9);
    }
    expect(ord($result->content[25]))->toBe(2);
    $output = imagecreatefromstring($result->content);
    expect($output)->toBeInstanceOf(GdImage::class);
    expect([imagesx($output), imagesy($output)])->toBe([7, 5]);

    for ($y = 0; $y < 5; ++$y) {
        for ($x = 0; $x < 7; ++$x) {
            $expected = ((($x * 43 + $y * 13) % 256) << 16)
                | ((($x * 7 + $y * 51) % 256) << 8)
                | (($x * 31 + $y * 9) % 256);
            expect(imagecolorat($native, $x, $y))->toBe($expected);
            expect(imagecolorat($output, $x, $y))->toBe($expected);
        }
    }
})->with(['bottom-up' => false, 'top-down' => true])
    ->with(['bytes' => false, 'stream' => true])->with([0, 6, 9]);

test('indexed BMP retains an indexed PNG and exact colors', function (bool $compressed): void {
    $image = imagecreate(17, 9);
    $red = imagecolorallocate($image, 230, 20, 30);
    $blue = imagecolorallocate($image, 10, 40, 200);
    $green = imagecolorallocate($image, 30, 210, 60);
    imagefilledrectangle($image, 0, 0, 16, 8, $red);
    imagefilledrectangle($image, 1, 1, 12, 5, $blue);
    imagefilledrectangle($image, 7, 3, 15, 7, $green);
    $source = EncodedOutput::capture(static fn(): bool => imagebmp($image, null, $compressed));
    $native = imagecreatefromstring($source);
    expect($native)->toBeInstanceOf(GdImage::class);
    $result = (new GdPngConverter())->convert($source);
    expect(ord($result->content[25]))->toBe(3);
    $output = imagecreatefromstring($result->content);
    expect($output)->toBeInstanceOf(GdImage::class);
    for ($y = 0; $y < 9; ++$y) {
        for ($x = 0; $x < 17; ++$x) {
            expect(imagecolorsforindex($output, imagecolorat($output, $x, $y)))
                ->toBe(imagecolorsforindex($native, imagecolorat($native, $x, $y)));
        }
    }
})->with(['uncompressed' => false, 'RLE' => true]);
