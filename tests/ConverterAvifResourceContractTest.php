<?php

declare(strict_types=1);

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Internal\AvifStructure;
use Componenta\Image\Converter\Internal\EncodedOutput;

function converterAvifBox(string $type, string $payload): string
{
    return pack('N', strlen($payload) + 8) . $type . $payload;
}

function converterAvifMetadata(string $properties): string
{
    return converterAvifBox('meta', pack('N', 0)
        . converterAvifBox('iprp', converterAvifBox('ipco', $properties)));
}

function converterAvifFileType(): string
{
    return converterAvifBox('ftyp', 'avif' . pack('N', 0) . 'avif');
}

test('AVIF rejects duplicate metadata before reading its properties', function (): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF codec unavailable.');
    }
    $image = imagecreatetruecolor(16, 8);
    $valid = EncodedOutput::capture(static fn(): bool => imageavif($image, null, 80));
    $property = converterAvifBox('ispe', pack('N3', 0, 16, 8));
    $source = $valid . converterAvifMetadata(str_repeat($property, 100000));
    expect(getimagesizefromstring($source)['mime'])->toBe('image/avif');
    expect(strlen($source))->toBeLessThan(33554432);
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class, 'Duplicate AVIF metadata box');
});

test('duplicate AVIF file type and property containers are rejected', function (string $kind): void {
    $ftyp = converterAvifFileType();
    $propertyContainer = converterAvifBox('iprp', converterAvifBox('ipco', ''));
    $raw = $kind === 'ftyp'
        ? $ftyp . $ftyp
        : $ftyp . converterAvifBox('meta', pack('N', 0) . $propertyContainer . $propertyContainer);
    $expected = $kind === 'ftyp' ? 'Duplicate AVIF file type box' : 'Duplicate AVIF item properties box';
    expect(fn() => AvifStructure::assertSupported($raw))->toThrow(ConversionException::class, $expected);
})->with(['ftyp', 'iprp']);

test('AVIF metadata is bounded by the maximum addressable property index', function (): void {
    // An ipma association has at most 15 bits for its property index.
    $ftyp = converterAvifFileType();
    $property = converterAvifBox('ispe', pack('N3', 0, 16, 8));
    $boundary = str_repeat($property, 0x7fff);
    // The structural guard deliberately accepts a partial synthetic container;
    // this isolates the metadata budget from the native decoder.
    AvifStructure::assertSupported($ftyp . converterAvifMetadata($boundary));
    expect(fn() => AvifStructure::assertSupported($ftyp . converterAvifMetadata($boundary . $property)))
        ->toThrow(ConversionException::class, 'AVIF property count exceeds supported limit');
});
