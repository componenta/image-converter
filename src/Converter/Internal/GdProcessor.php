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
        self::assertStatic($raw, $mime);
        if ($mime === 'image/png' && $target === 'image/png' && ($info['bits'] ?? 8) > 8) {
            throw new ConversionException('GD cannot preserve the bit depth of this PNG.');
        }

        $image = self::native(static fn() => imagecreatefromstring($raw));
        if (!$image instanceof GdImage) {
            throw new ConversionException('Failed to decode image.');
        }
        if (imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new ConversionException('Decoded dimensions differ from the image header.');
        }
        if ($mime === 'image/jpeg') {
            $image = self::orientJpeg($image, $raw);
            if (imagesx($image) > $limits->maxWidth || imagesy($image) > $limits->maxHeight) {
                throw new ConversionException('Oriented image dimensions exceed configured limits.');
            }
        }
        if ($target === 'image/jpeg' && $mime !== 'image/jpeg') {
            $image = self::flatten($image);
        } elseif ($target === 'image/webp' || $target === 'image/avif') {
            if (!imageistruecolor($image) && !self::native(static fn() => imagepalettetotruecolor($image))) {
                throw new ConversionException('Failed to convert image palette.');
            }
            self::preserveAlpha($image);
        } elseif ($target === 'image/png' && imageistruecolor($image)) {
            self::preserveAlpha($image);
        }

        $encoded = EncodedOutput::capture(static fn(): bool => self::native(static fn(): bool => match ($target) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/png' => imagepng($image, null, $quality),
            'image/webp' => imagewebp($image, null, $quality),
            'image/avif' => imageavif($image, null, $quality, $avifSpeed ?? -1),
            default => throw new ConversionException('Unsupported encoding format.'),
        }));
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

    private static function orientJpeg(GdImage $image, string $raw): GdImage
    {
        $orientation = self::native(static fn(): int => JpegOrientation::read($raw));
        if (in_array($orientation, [2, 5, 7], true)) {
            if (!self::native(static fn() => imageflip($image, IMG_FLIP_HORIZONTAL))) {
                throw new ConversionException('Failed to mirror JPEG orientation.');
            }
        } elseif ($orientation === 4) {
            if (!self::native(static fn() => imageflip($image, IMG_FLIP_VERTICAL))) {
                throw new ConversionException('Failed to mirror JPEG orientation.');
            }
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
        if ($background === false || !self::native(static fn() => imagefill($canvas, 0, 0, $background))
            || !self::native(static fn() => imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height))) {
            throw new ConversionException('Failed to flatten JPEG transparency.');
        }

        return $canvas;
    }

    private static function preserveAlpha(GdImage $image): void
    {
        if (!imagealphablending($image, false) || !imagesavealpha($image, true)) {
            throw new ConversionException('Failed to configure alpha preservation.');
        }
    }

    private static function assertStatic(string $raw, string $mime): void
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
                if ($type === 'IDAT' || $type === 'IEND') {
                    break;
                }
                $offset += $length + 12;
            }
        } elseif ($mime === 'image/webp' && strlen($raw) >= 21
            && substr($raw, 12, 4) === 'VP8X' && (ord($raw[20]) & 0x02) !== 0) {
            throw new ConversionException('Animated WebP is not supported.');
        } elseif ($mime === 'image/avif' && strlen($raw) >= 16 && substr($raw, 4, 4) === 'ftyp') {
            $header = unpack('Nlength', substr($raw, 0, 4));
            $length = $header['length'] ?? null;
            if (!is_int($length) || $length < 16 || $length > strlen($raw) || ($length - 16) % 4 !== 0) {
                throw new ConversionException('Unsupported AVIF file type box.');
            }
            if (substr($raw, 8, 4) === 'avis') {
                throw new ConversionException('Animated AVIF is not supported.');
            }
            for ($offset = 16; $offset < $length; $offset += 4) {
                if (substr($raw, $offset, 4) === 'avis') {
                    throw new ConversionException('Animated AVIF is not supported.');
                }
            }
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
