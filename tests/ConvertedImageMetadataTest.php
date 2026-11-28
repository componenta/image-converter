<?php

declare(strict_types=1);

use Componenta\Image\Converter\ConvertedImage;
use Componenta\Image\Converter\Exception\ConversionException;

test('image results normalize valid format metadata', function (): void {
    $result = new ConvertedImage('bytes', 'IMAGE/JPEG', 'JPG');
    expect($result->mimeType)->toBe('image/jpeg');
    expect($result->extension)->toBe('jpg');
    expect($result->size)->toBe(5);
});

test('image results reject empty bytes and malformed metadata', function (string $content, string $mime, string $extension): void {
    expect(fn() => new ConvertedImage($content, $mime, $extension))->toThrow(ConversionException::class);
})->with([
    ['', 'image/png', 'png'],
    ['bytes', 'text/plain', 'png'],
    ['bytes', 'image/*', 'png'],
    ['bytes', 'image/png; charset=utf-8', 'png'],
    ['bytes', 'image/png', ''],
    ['bytes', 'image/png', '.png'],
    ['bytes', 'image/png', '../png'],
    ['bytes', 'image/png', 'foo/bar'],
    ['bytes', 'image/png', 'foo\\bar'],
    ['bytes', 'image/png', 'foo:bar'],
]);

test('known image types reject extensions that contradict the MIME catalog', function (string $mime, string $extension): void {
    expect(fn() => new ConvertedImage('bytes', $mime, $extension))
        ->toThrow(ConversionException::class, 'extension does not match');
})->with([
    ['image/png', 'jpg'],
    ['image/png', 'png.php'],
    ['image/jpeg', 'webp'],
    ['image/pjpeg', 'png'],
    ['image/x-png', 'jpeg'],
    ['image/avif', 'heic'],
    ['image/webp', 'php'],
    ['image/tiff', 'gif'],
]);

test('known aliases and unmapped custom image types retain valid extensions', function (string $mime, string $extension, string $expected): void {
    $result = new ConvertedImage('bytes', $mime, $extension);
    expect($result->extension)->toBe($expected);
})->with([
    ['image/jpeg', 'JPE', 'jpe'],
    ['image/pjpeg', 'jpeg', 'jpeg'],
    ['image/x-png', 'PNG', 'png'],
    ['image/tiff', 'tif', 'tif'],
    ['image/x-componenta-test-format', 'ctf', 'ctf'],
]);
