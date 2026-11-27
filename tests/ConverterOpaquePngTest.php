<?php

declare(strict_types=1);

use Componenta\Image\GdPngConverter;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\Converter\Internal\EncodedOutput;

test('JPEG conversion stores opaque RGB PNG without changing any decoded pixels', function (bool $progressive, int $level): void {
    $image = imagecreatetruecolor(37, 23);
    for ($y = 0; $y < 23; ++$y) {
        for ($x = 0; $x < 37; ++$x) {
            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image, ($x * 37 + $y) % 256, ($y * 41 + $x) % 256, ($x * 13 + $y * 17) % 256,
            ));
        }
    }
    imageinterlace($image, $progressive);
    $source = EncodedOutput::capture(static fn(): bool => imagejpeg($image, null, 93));
    $reference = imagecreatefromstring($source);
    expect($reference)->toBeInstanceOf(GdImage::class);

    $result = (new GdPngConverter())->convert($source, new ImageConversionOptions(compressionLevel: $level));
    expect($result->mimeType)->toBe('image/png');
    // PNG IHDR: color type 2 = RGB, type 6 = RGBA.
    expect(substr($result->content, 12, 4))->toBe('IHDR');
    expect(ord($result->content[25]))->toBe(2);
    $output = imagecreatefromstring($result->content);
    expect($output)->toBeInstanceOf(GdImage::class);
    expect([imagesx($output), imagesy($output)])->toBe([37, 23]);

    for ($y = 0; $y < 23; ++$y) {
        for ($x = 0; $x < 37; ++$x) {
            expect(imagecolorat($output, $x, $y))->toBe(imagecolorat($reference, $x, $y));
        }
    }
})->with(['baseline' => false, 'progressive' => true])->with([0, 6, 9]);

test('conversion of an alpha-bearing WebP or AVIF keeps the alpha channel in PNG', function (string $format): void {
    $image = imagecreatetruecolor(24, 12);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, 7, 11, imagecolorallocatealpha($image, 200, 10, 10, 127));
    imagefilledrectangle($image, 8, 0, 15, 11, imagecolorallocatealpha($image, 10, 200, 10, 64));
    imagefilledrectangle($image, 16, 0, 23, 11, imagecolorallocatealpha($image, 10, 10, 200, 0));
    $codec = new GdPngConverter();
    if (!$codec->supports('image/' . $format)) {
        $this->markTestSkipped('Required source codec unavailable.');
    }
    $source = EncodedOutput::capture(static fn(): bool => match ($format) {
        'webp' => imagewebp($image, null, 100),
        'avif' => imageavif($image, null, 100, 10),
    });
    $reference = imagecreatefromstring($source);
    $result = $codec->convert($source);
    expect(ord($result->content[25]))->toBe(6);
    $decoded = imagecreatefromstring($result->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    foreach ([4, 12, 20] as $x) {
        expect(imagecolorat($decoded, $x, 6))->toBe(imagecolorat($reference, $x, 6));
    }
})->with(['webp', 'avif']);
