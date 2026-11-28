<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\Converter\Exception\ConversionException;

/** @internal */
final class JpegOrientation
{
    /** @throws ConversionException */
    public static function read(string $raw): int
    {
        // Only an APP1 marker in the JPEG header can declare EXIF orientation.
        $tiff = self::exifPayload($raw);
        if ($tiff === null) {
            return 1;
        }

        // The APP1 TIFF payload is at most 65,527 bytes. Parse only that block,
        // without unrelated XMP APP1 segments or the JPEG image data.
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new ConversionException('Unable to open JPEG metadata stream.');
        }
        try {
            if (fwrite($stream, $tiff) !== strlen($tiff) || !rewind($stream)) {
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

    private static function exifPayload(string $raw): ?string
    {
        $size = strlen($raw);
        // Skip SOI, then walk segment lengths without inspecting compressed pixels.
        $offset = 2;
        while ($offset < $size && $raw[$offset] === "\xff") {
            do {
                ++$offset;
            } while ($offset < $size && $raw[$offset] === "\xff");
            if ($offset >= $size) {
                return null;
            }
            $marker = ord($raw[$offset++]);
            if ($marker === 0xda || $marker === 0xd9) {
                return null;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            if ($size - $offset < 2) {
                return null;
            }
            $length = ord($raw[$offset]) * 256 + ord($raw[$offset + 1]);
            if ($length < 2 || $length > $size - $offset) {
                return null;
            }
            if ($marker === 0xe1 && $length >= 8 && substr($raw, $offset + 2, 6) === "Exif\0\0") {
                return substr($raw, $offset + 8, $length - 8);
            }
            $offset += $length;
        }

        return null;
    }
}
