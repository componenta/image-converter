<?php

declare(strict_types=1);

use Componenta\Image\GdJpegConverter;
use Componenta\Image\ImageConversionLimits;
use Componenta\Image\ConversionException;
use Componenta\Image\Converter\Internal\EncodedOutput;

test('output capture retains exactly the configured limit without forwarding bytes', function (): void {
    ob_start();
    try {
        echo 'parent';
        $level = ob_get_level();
        $payload = str_repeat('x', 150000);
        $result = EncodedOutput::capture(static function () use ($payload): bool {
            echo $payload;
            return true;
        }, strlen($payload));
        expect($result)->toBe($payload);
        expect(ob_get_level())->toBe($level);
        expect(ob_get_contents())->toBe('parent');
    } finally {
        ob_end_clean();
    }
});

test('output overflow discards all chunks and does not leak output buffers', function (): void {
    ob_start();
    try {
        echo 'parent';
        $level = ob_get_level();
        expect(fn() => EncodedOutput::capture(static function (): bool {
            echo str_repeat('a', 70000);
            echo str_repeat('b', 70000);
            return true;
        }, 100000))->toThrow(ConversionException::class, 'output byte limit');
        expect(ob_get_level())->toBe($level);
        expect(ob_get_contents())->toBe('parent');
    } finally {
        ob_end_clean();
    }
});

test('direct GD codec rejects oversized encoded images', function (): void {
    $image = imagecreatetruecolor(16, 16);
    $source = EncodedOutput::capture(static fn(): bool => imagepng($image));
    $result = (new GdJpegConverter())->convert($source);
    $exact = new GdJpegConverter(limits: new ImageConversionLimits(maxOutputBytes: $result->size));
    expect($exact->convert($source)->content)->toBe($result->content);
    $small = new GdJpegConverter(limits: new ImageConversionLimits(maxOutputBytes: $result->size - 1));
    expect(fn() => $small->convert($source))->toThrow(ConversionException::class, 'output byte limit');
});

test('invalid output limit is rejected', function (): void {
    expect(fn() => new ImageConversionLimits(maxOutputBytes: 0))->toThrow(InvalidArgumentException::class);
});
