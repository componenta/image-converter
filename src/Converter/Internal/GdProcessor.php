<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;
use Componenta\Image\ImageConversionLimits;
use GdImage;
use Psr\Http\Message\StreamInterface;

/** @internal */
final class GdProcessor
{
    public static function normalize(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'image/x-ms-bmp' => 'image/bmp',
            default => strtolower(trim($mimeType)),
        };
    }

    /** @param list<string> $allowed */
    public static function supports(string $source, array $allowed, string $target): bool
    {
        $source = self::normalize($source);

        return in_array($source, $allowed, true)
            && self::available($source)
            && self::available($target)
            && function_exists(match ($target) {
                'image/jpeg' => 'imagejpeg',
                'image/png' => 'imagepng',
                'image/webp' => 'imagewebp',
                'image/avif' => 'imageavif',
                default => 'image_codec_not_supported',
            });
    }

    /** @param list<string> $allowed */
    public static function process(
        string|StreamInterface $source,
        array $allowed,
        string $target,
        int $quality,
        ImageConversionLimits $limits,
        ?int $avifSpeed = null,
    ): string {
        $raw = SourceReader::read($source, $limits->maxInputBytes);
        $info = self::native(static fn() => getimagesizefromstring($raw));
        if ($info === false) {
            throw new ConversionException('Unable to identify image dimensions and format.');
        }
        $mime = self::normalize($info['mime']);
        if (!self::supports($mime, $allowed, $target)) {
            throw new ConversionException('Source or target image format is unsupported by this GD codec.');
        }
        $width = $info[0];
        $height = $info[1];
        if ($width < 1 || $height < 1 || $width > $limits->maxWidth || $height > $limits->maxHeight
            || $height > intdiv($limits->maxPixels, $width)) {
            throw new ConversionException('Image dimensions exceed configured limits.');
        }
        if ($mime === 'image/jpeg') {
            JpegStructure::assertComplete($raw);
        }
        self::assertStatic($raw, $mime, $info['bits'] ?? 8);
        if ($mime === 'image/png' && $target === 'image/png' && ($info['bits'] ?? 8) > 8) {
            throw new ConversionException('GD cannot preserve the bit depth of this PNG.');
        }

        $orientation = $mime === 'image/jpeg'
            ? self::native(static fn(): int => JpegOrientation::read($raw))
            : 1;
        if ($orientation >= 5 && ($height > $limits->maxWidth || $width > $limits->maxHeight)) {
            throw new ConversionException('Oriented image dimensions exceed configured limits.');
        }

        $image = self::native(static fn() => imagecreatefromstring($raw));
        if (!$image instanceof GdImage) {
            throw new ConversionException('Failed to decode image.');
        }
        if (imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new ConversionException('Decoded dimensions differ from the image header.');
        }
        if ($orientation !== 1) {
            $image = self::orientJpeg($image, $orientation);
        }
        if ($target === 'image/jpeg' && $mime !== 'image/jpeg') {
            $image = self::flatten($image);
        } elseif ($target === 'image/webp' || $target === 'image/avif') {
            if (!imageistruecolor($image)) {
                self::requireSuccess(static fn() => imagepalettetotruecolor($image), 'Failed to convert image palette.');
            } elseif (imagecolortransparent($image) >= 0) {
                // Truecolor PNG tRNS is stored as a color key by GD, not per-pixel alpha.
                // WebP and AVIF need actual alpha samples. Copy over a transparent canvas,
                // letting GD skip the keyed pixels without a PHP loop over the image.
                $image = self::expandColorKey($image);
            }
            self::preserveAlpha($image);
        } elseif ($target === 'image/png' && imageistruecolor($image)) {
            if ($mime === 'image/jpeg' || ($mime === 'image/bmp' && ($info['bits'] ?? null) === 24)) {
                // JPEG (including EXIF orientation) and 24-bit BMP produce opaque
                // RGB pixels. Avoid a redundant fourth channel in lossless PNG.
                self::requireSuccess(static fn() => imagesavealpha($image, false), 'Failed to configure opaque PNG encoding.');
            } elseif (imagecolortransparent($image) >= 0) {
                // An RGB PNG with a transparent color is encoded via tRNS. Enabling
                // full-alpha saving here would discard the color key.
                self::requireSuccess(static fn() => imagesavealpha($image, false), 'Failed to preserve PNG color key.');
            } else {
                self::preserveAlpha($image);
            }
        }

        $encoded = EncodedOutput::capture(static fn(): bool => self::native(static fn(): bool => match ($target) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/png' => imagepng($image, null, $quality),
            'image/webp' => imagewebp($image, null, $quality),
            'image/avif' => imageavif($image, null, $quality, $avifSpeed ?? -1),
            default => throw new ConversionException('Unsupported encoding format.'),
        }), $limits->maxOutputBytes);
        $output = self::native(static fn() => getimagesizefromstring($encoded));
        if ($output === false || self::normalize($output['mime']) !== $target
            || $output[0] !== imagesx($image) || $output[1] !== imagesy($image)) {
            throw new ConversionException('Encoded image header does not match the requested format and dimensions.');
        }

        return $encoded;
    }

    private static function available(string $mime): bool
    {
        if (!function_exists('imagetypes') || !function_exists('imagecreatefromstring')) {
            return false;
        }
        $flag = match ($mime) {
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/webp' => IMG_WEBP,
            'image/avif' => defined('IMG_AVIF') ? IMG_AVIF : 0,
            'image/bmp' => IMG_BMP,
            default => 0,
        };

        return $flag !== 0 && (imagetypes() & $flag) !== 0;
    }

    private static function orientJpeg(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            self::requireSuccess(static fn() => imageflip($image, IMG_FLIP_HORIZONTAL), 'Failed to mirror JPEG orientation.');
        } elseif ($orientation === 4) {
            self::requireSuccess(static fn() => imageflip($image, IMG_FLIP_VERTICAL), 'Failed to mirror JPEG orientation.');
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = self::native(static fn() => imagerotate($image, $angle, 0));
        if (!$rotated instanceof GdImage) {
            throw new ConversionException('Failed to rotate JPEG orientation.');
        }

        return $rotated;
    }

    private static function flatten(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = self::native(static fn() => imagecreatetruecolor($width, $height));
        if (!$canvas instanceof GdImage) {
            throw new ConversionException('Failed to create JPEG canvas.');
        }
        $background = self::native(static fn() => imagecolorallocate($canvas, 255, 255, 255));
        if ($background === false) {
            throw new ConversionException('Failed to allocate JPEG background.');
        }
        self::requireSuccess(static fn() => imagefill($canvas, 0, 0, $background), 'Failed to fill JPEG canvas.');
        self::requireSuccess(static fn() => imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height), 'Failed to flatten JPEG transparency.');

        return $canvas;
    }

    private static function expandColorKey(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = self::native(static fn() => imagecreatetruecolor($width, $height));
        if (!$canvas instanceof GdImage) {
            throw new ConversionException('Failed to allocate transparent canvas.');
        }
        self::preserveAlpha($canvas);
        $transparent = self::native(static fn() => imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        if ($transparent === false) {
            throw new ConversionException('Failed to allocate transparent color.');
        }
        self::requireSuccess(static fn() => imagefill($canvas, 0, 0, $transparent), 'Failed to fill transparent canvas.');
        self::requireSuccess(static fn() => imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height), 'Failed to expand color-key transparency.');

        return $canvas;
    }

    private static function preserveAlpha(GdImage $image): void
    {
        self::requireSuccess(static fn() => imagealphablending($image, false), 'Failed to configure alpha blending.');
        self::requireSuccess(static fn() => imagesavealpha($image, true), 'Failed to preserve alpha channel.');
    }

    /** @param callable(): bool $operation */
    private static function requireSuccess(callable $operation, string $message): void
    {
        // PHP 8.5 narrows several GD return types to true; PHP 8.4 also needs false handling.
        if (!self::native($operation)) {
            throw new ConversionException($message);
        }
    }

    private static function assertStatic(string $raw, string $mime, int $bitDepth): void
    {
        if ($mime === 'image/png') {
            // APNG declares animation before the first IDAT.
            $size = strlen($raw);
            for ($offset = 8; $offset <= $size - 12;) {
                $header = unpack('Nlength', substr($raw, $offset, 4));
                $length = $header['length'] ?? null;
                if (!is_int($length) || $length < 0 || $length > $size - $offset - 12) {
                    throw new ConversionException('Invalid PNG chunk length.');
                }
                $type = substr($raw, $offset + 4, 4);
                if ($type === 'acTL') {
                    throw new ConversionException('Animated PNG is not supported.');
                }
                if ($type === 'tRNS' && $bitDepth === 16) {
                    // GD strips the low bytes before resolving the color key.
                    // Distinct opaque samples can become transparent.
                    throw new ConversionException('GD cannot preserve 16-bit PNG color-key transparency.');
                }
                if ($type === 'IDAT' || $type === 'IEND') {
                    break;
                }
                $offset += $length + 12;
            }
        } elseif ($mime === 'image/webp' && strlen($raw) >= 21
            && substr($raw, 12, 4) === 'VP8X' && (ord($raw[20]) & 0x02) !== 0) {
            throw new ConversionException('Animated WebP is not supported.');
        } elseif ($mime === 'image/avif') {
            AvifStructure::assertSupported($raw);
        }
    }

    /**
     * Native calls are synchronous and execute no user callbacks.
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function native(callable $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            return $operation();
        } catch (\ErrorException|\ValueError $error) {
            throw new ConversionException('GD image operation failed.', previous: $error);
        } finally {
            restore_error_handler();
        }
    }
}
