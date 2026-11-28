<?php

declare(strict_types=1);

use Componenta\Detector\FinfoDetector;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\ImageConverter;
use Componenta\Image\Converter\ImageConversionOptions;
use Componenta\Image\Converter\ImageConversionLimits;
use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\Utils;

function converterCodecMatrixFixture(string $format): string
{
    $image = imagecreatetruecolor(48, 24);
    if ($image === false) {
        throw new RuntimeException('Could not allocate image fixture.');
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $alpha = in_array($format, ['png', 'webp', 'avif'], true);
    $left = imagecolorallocatealpha($image, 0, 0, 0, $alpha ? 127 : 0);
    $middle = imagecolorallocatealpha($image, 20, 110, 210, $alpha ? 64 : 0);
    $right = imagecolorallocatealpha($image, 180, 50, 70, 0);
    imagefilledrectangle($image, 0, 0, 15, 23, $left);
    imagefilledrectangle($image, 16, 0, 31, 23, $middle);
    imagefilledrectangle($image, 32, 0, 47, 23, $right);

    return EncodedOutput::capture(static fn(): bool => match ($format) {
        'jpeg' => imagejpeg($image, null, 100),
        'png' => imagepng($image),
        'webp' => imagewebp($image, null, 100),
        'avif' => imageavif($image, null, 100, 10),
        'bmp' => imagebmp($image),
        default => throw new InvalidArgumentException('Unexpected fixture format.'),
    });
}

/** All built-in format pairs, checked through both direct codecs and the orchestrator. */
test('advertised format pairs handle both endpoints of their encoding options', function (string $sourceFormat, string $targetFormat, bool $maximum): void {
    $codec = match ($targetFormat) {
        'jpeg' => new GdJpegConverter(),
        'png' => new GdPngConverter(),
        'webp' => new GdWebPConverter(),
        'avif' => new GdAvifConverter(),
        default => throw new InvalidArgumentException('Unexpected target format.'),
    };
    if (!$codec->supports('image/' . $sourceFormat)) {
        $this->markTestSkipped('Required GD source or target format unavailable.');
    }
    $source = converterCodecMatrixFixture($sourceFormat);
    $options = match ($targetFormat) {
        'png' => new ImageConversionOptions(compressionLevel: $maximum ? 9 : 0),
        'avif' => new ImageConversionOptions(quality: $maximum ? 100 : 0, avifSpeed: $maximum ? 0 : 10),
        default => new ImageConversionOptions(quality: $maximum ? 100 : 0),
    };
    $direct = $codec->convert($source, $options);
    $service = new ImageConverter(new FinfoDetector(), [$codec]);
    $stream = Utils::streamFor($source);
    $stream->seek(7);
    expect($service->canConvert($stream, $targetFormat))->toBeTrue();
    expect($stream->tell())->toBe(7);
    $routed = $service->convert($stream, $targetFormat, $options);
    expect($stream->tell())->toBe(7);
    $nativeAlpha = null;
    if ($targetFormat === 'avif' && !$maximum && in_array($sourceFormat, ['png', 'webp', 'avif'], true)) {
        // libgd quantizes AVIF alpha with the same quality parameter as color.
        // Compare with its native output, rather than requiring lossless alpha at quality 0.
        $native = imagecreatefromstring($source);
        expect($native)->toBeInstanceOf(GdImage::class);
        imagealphablending($native, false);
        imagesavealpha($native, true);
        $nativeBytes = EncodedOutput::capture(static fn(): bool => imageavif($native, null, 0, 10));
        $nativeDecoded = imagecreatefromstring($nativeBytes);
        expect($nativeDecoded)->toBeInstanceOf(GdImage::class);
        $nativeAlpha = static fn(int $x): int => imagecolorsforindex($nativeDecoded, imagecolorat($nativeDecoded, $x, 12))['alpha'];
    }
    foreach ([$direct, $routed] as $result) {
        expect($result->mimeType)->toBe('image/' . $targetFormat);
        expect($result->extension)->toBe($targetFormat === 'jpeg' ? 'jpg' : $targetFormat);
        expect($result->size)->toBeGreaterThan(0);
        $decoded = imagecreatefromstring($result->content);
        expect($decoded)->toBeInstanceOf(GdImage::class);
        expect(imagesx($decoded))->toBe(48);
        expect(imagesy($decoded))->toBe(24);
        if (in_array($sourceFormat, ['png', 'webp', 'avif'], true)
            && $targetFormat !== 'jpeg') {
            // Check real pixel alpha, not just the output MIME or presence of an alpha channel.
            $alpha = static fn(int $x): int => imagecolorsforindex($decoded, imagecolorat($decoded, $x, 12))['alpha'];
            if ($nativeAlpha !== null) {
                foreach ([8, 24, 40] as $x) {
                    expect($alpha($x))->toBe($nativeAlpha($x));
                }
                expect($alpha(8))->toBeGreaterThan($alpha(24));
                expect($alpha(24))->toBeGreaterThan($alpha(40));
            } else {
                expect($alpha(8))->toBe(127);
                expect(abs($alpha(24) - 64))->toBeLessThanOrEqual(2);
                expect($alpha(40))->toBe(0);
            }
        }
    }
})->with([
    'jpeg to png' => ['jpeg', 'png'],
    'jpeg to webp' => ['jpeg', 'webp'],
    'jpeg to avif' => ['jpeg', 'avif'],
    'png to jpeg' => ['png', 'jpeg'],
    'png to webp' => ['png', 'webp'],
    'png to avif' => ['png', 'avif'],
    'webp to jpeg' => ['webp', 'jpeg'],
    'webp to png' => ['webp', 'png'],
    'webp to avif' => ['webp', 'avif'],
    'avif to jpeg' => ['avif', 'jpeg'],
    'avif to png' => ['avif', 'png'],
    'avif to webp' => ['avif', 'webp'],
    'bmp to jpeg' => ['bmp', 'jpeg'],
    'bmp to png' => ['bmp', 'png'],
    'bmp to webp' => ['bmp', 'webp'],
    'bmp to avif' => ['bmp', 'avif'],
])->with(['minimum' => false, 'maximum' => true]);

test('all built-in encoders enforce output limits at the native boundary', function (string $targetFormat): void {
    $sourceFormat = $targetFormat === 'png' ? 'jpeg' : 'png';
    $limits = new ImageConversionLimits(maxOutputBytes: 1);
    $codec = match ($targetFormat) {
        'jpeg' => new GdJpegConverter(limits: $limits),
        'png' => new GdPngConverter(limits: $limits),
        'webp' => new GdWebPConverter(limits: $limits),
        'avif' => new GdAvifConverter(limits: $limits),
    };
    if (!$codec->supports('image/' . $sourceFormat)) {
        $this->markTestSkipped('Required codec unavailable.');
    }
    $source = converterCodecMatrixFixture($sourceFormat);
    $level = ob_get_level();
    expect(fn() => $codec->convert($source))->toThrow(ConversionException::class, 'output byte limit');
    expect(ob_get_level())->toBe($level);
})->with(['jpeg', 'png', 'webp', 'avif']);
