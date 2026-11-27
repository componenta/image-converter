<?php

declare(strict_types=1);

use Componenta\Image\ConversionException;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\Converter\Internal\EncodedOutput;

function converterContainerFixture(string $format, bool $progressive = false): string
{
    $image = imagecreatetruecolor(64, 32);
    imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, 200, 40, 20));
    imagefilledrectangle($image, 32, 0, 63, 31, imagecolorallocate($image, 20, 40, 200));
    if ($progressive) {
        imageinterlace($image, true);
    }

    return EncodedOutput::capture(static fn(): bool => match ($format) {
        'jpeg' => imagejpeg($image, null, 90),
        'png' => imagepng($image, null, 6),
    });
}

test('JPEG with valid dimensions but missing its end marker is rejected', function (bool $progressive): void {
    $jpeg = converterContainerFixture('jpeg', $progressive);
    expect(substr($jpeg, -2))->toBe("\xff\xd9");
    $truncated = substr($jpeg, 0, -2);
    expect(getimagesizefromstring($truncated)['mime'])->toBe('image/jpeg');
    expect(fn() => (new GdPngConverter())->convert($truncated))->toThrow(ConversionException::class, 'missing its end marker');
})->with([false, true]);

test('a JPEG marker in comment data is not a real end of image', function (): void {
    $jpeg = converterContainerFixture('jpeg');
    $comment = "\xff\xd9";
    $truncated = substr($jpeg, 0, 2) . "\xff\xfe" . pack('n', strlen($comment) + 2)
        . $comment . substr($jpeg, 2, -2);
    expect(getimagesizefromstring($truncated)['mime'])->toBe('image/jpeg');
    expect(fn() => (new GdPngConverter())->convert($truncated))->toThrow(ConversionException::class);
});

test('complete baseline and progressive JPEG tolerate trailing data when re-encoding', function (bool $progressive): void {
    $jpeg = converterContainerFixture('jpeg', $progressive) . 'trailing bytes';
    $output = (new GdPngConverter())->convert($jpeg);
    $decoded = imagecreatefromstring($output->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect(imagesx($decoded))->toBe(64);
    expect(imagesy($decoded))->toBe(32);
})->with([false, true]);

test('PNG missing its IEND chunk is rejected even when pixels are present', function (): void {
    $png = converterContainerFixture('png');
    expect(substr($png, -8, 4))->toBe('IEND');
    $truncated = substr($png, 0, -12);
    expect(getimagesizefromstring($truncated)['mime'])->toBe('image/png');
    expect(fn() => (new GdJpegConverter())->convert($truncated))->toThrow(ConversionException::class);
});

test('an EOI byte pattern in a valid comment is not confused with the real end marker', function (): void {
    $jpeg = converterContainerFixture('jpeg');
    $comment = "before\xff\xd9after";
    $source = substr($jpeg, 0, 2) . "\xff\xfe" . pack('n', strlen($comment) + 2)
        . $comment . substr($jpeg, 2);
    $output = (new GdPngConverter())->convert($source);
    expect(imagecreatefromstring($output->content))->toBeInstanceOf(GdImage::class);
});

test('JPEG segment bounds are checked even after an otherwise valid size header', function (): void {
    $jpeg = converterContainerFixture('jpeg');
    $truncated = substr($jpeg, 0, -2) . "\xff\xfe\xff\xff\xff\xd9";
    expect(getimagesizefromstring($truncated)['mime'])->toBe('image/jpeg');
    expect(fn() => (new GdPngConverter())->convert($truncated))->toThrow(ConversionException::class);
});
