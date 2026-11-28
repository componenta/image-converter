<?php

declare(strict_types=1);

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Codec\GdAvifConverter;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
use Componenta\Image\Converter\Codec\GdWebPConverter;
use Componenta\Image\Converter\ImageConversionLimits;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\Utils;

test('bounded deterministic mutations preserve process and stream ownership', function (string $from, string $to): void {
    $limits = new ImageConversionLimits(maxInputBytes: 262144, maxPixels: 4096, maxWidth: 64, maxHeight: 64, maxOutputBytes: 262144);
    $codec = match ($to) {
        'jpeg' => new GdJpegConverter(limits: $limits),
        'png' => new GdPngConverter(limits: $limits),
        'webp' => new GdWebPConverter(limits: $limits),
        'avif' => new GdAvifConverter(limits: $limits),
    };
    if (!$codec->supports('image/' . $from)) {
        $this->markTestSkipped('Native codec unavailable.');
    }
    $image = imagecreatetruecolor(32, 24);
    imagefilledrectangle($image, 0, 0, 15, 23, imagecolorallocate($image, 250, 30, 40));
    imagefilledrectangle($image, 16, 0, 31, 23, imagecolorallocate($image, 20, 70, 220));
    $raw = EncodedOutput::capture(static fn(): bool => match ($from) {
        'jpeg' => imagejpeg($image, null, 90),
        'png' => imagepng($image, null, 6),
        'webp' => imagewebp($image, null, 90),
        'avif' => imageavif($image, null, 90, 8),
    });
    unset($image);
    $length = strlen($raw);
    $samples = [$raw, '', substr($raw, 0, 2), substr($raw, 0, intdiv($length, 2)), substr($raw, 0, -1)];
    for ($i = 0; $i < 24; ++$i) {
        $offset = intdiv($i * ($length - 1), 23);
        $mutated = $raw;
        $mutated[$offset] = chr(ord($mutated[$offset]) ^ (1 << ($i % 8)));
        $samples[] = $mutated;
    }
    $handler = static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    };
    set_error_handler($handler);
    ob_start();
    try {
        echo 'parent';
        $level = ob_get_level();
        foreach ($samples as $index => $sample) {
            $stream = Utils::streamFor($sample);
            $position = min(3, strlen($sample));
            $stream->seek($position);
            try {
                $result = $codec->convert($index % 2 === 0 ? $stream : $sample);
                expect($result->mimeType)->toBe('image/' . $to);
                expect($result->size)->toBeGreaterThan(0)->toBeLessThanOrEqual($limits->maxOutputBytes);
                $decoded = imagecreatefromstring($result->content);
                expect($decoded)->toBeInstanceOf(GdImage::class);
                expect(imagesx($decoded))->toBeGreaterThan(0)->toBeLessThanOrEqual($limits->maxWidth);
                expect(imagesy($decoded))->toBeGreaterThan(0)->toBeLessThanOrEqual($limits->maxHeight);
                unset($decoded, $result);
            } catch (ConversionException $error) {
                if ($index === 0) {
                    throw $error;
                }
            }
            expect($stream->tell())->toBe($position);
            expect(ob_get_level())->toBe($level);
            expect(ob_get_contents())->toBe('parent');
            $installed = set_error_handler(static fn(): bool => true);
            restore_error_handler();
            expect($installed)->toBe($handler);
        }
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
})->with([['jpeg', 'png'], ['png', 'webp'], ['webp', 'avif'], ['avif', 'jpeg']]);
