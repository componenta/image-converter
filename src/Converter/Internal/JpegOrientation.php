<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;

/** @internal */
final class JpegOrientation
{
    public static function read(string $raw): int
    {
        // No EXIF signature means no EXIF orientation to apply.
        if (!str_contains($raw, "Exif\0\0")) {
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
}
