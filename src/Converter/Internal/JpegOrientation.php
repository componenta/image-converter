<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;

/** @internal */
final class JpegOrientation
{
    public static function read(string $raw): int
    {
        // Only an APP1 marker in the JPEG header can declare EXIF orientation.
        if (!self::hasExifSegment($raw)) {
            return 1;
        }

        // A bounded JPEG snapshot is already owned by the processor.
        // The EXIF reader requires a seekable stream and does not own it.
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new ConversionException('Unable to open JPEG metadata stream.');
        }
        try {
            if (fwrite($stream, $raw) !== strlen($raw) || !rewind($stream)) {
                throw new ConversionException('Unable to prepare JPEG metadata stream.');
            }
            $metadata = exif_read_data($stream, 'IFD0', true, false);
            $orientation = $metadata === false ? 1 : ($metadata['IFD0']['Orientation'] ?? 1);
            if (!is_int($orientation) || $orientation < 1 || $orientation > 8) {
                throw new ConversionException('Invalid JPEG orientation.');
            }

            return $orientation;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private static function hasExifSegment(string $raw): bool
    {
        $size = strlen($raw);
        // Skip SOI, then walk segment lengths without inspecting compressed pixels.
        $offset = 2;
        while ($offset < $size && $raw[$offset] === "\\xff") {
            do {
                ++$offset;
            } while ($offset < $size && $raw[$offset] === "\\xff");
            if ($offset >= $size) {
                return false;
            }
            $marker = ord($raw[$offset++]);
            if ($marker === 0xda || $marker === 0xd9) {
                return false;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            if ($size - $offset < 2) {
                return false;
            }
            $length = ord($raw[$offset]) * 256 + ord($raw[$offset + 1]);
            if ($length < 2 || $length > $size - $offset) {
                return false;
            }
            if ($marker === 0xe1 && $length >= 8 && substr($raw, $offset + 2, 6) === "Exif\\0\\0") {
                return true;
            }
            $offset += $length;
        }

        return false;
    }
}
