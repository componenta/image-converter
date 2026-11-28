<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;

/** Bounded RIFF framing check (RFC 9649, sections 2.3 and 2.4), not a bitstream validator. @internal */
final class WebpStructure
{
    public static function assertStatic(string $raw): void
    {
        $size = strlen($raw);
        if ($size < 12 || !str_starts_with($raw, 'RIFF') || substr($raw, 8, 4) !== 'WEBP') {
            throw new ConversionException('Invalid WebP container header.');
        }
        $riffSize = unpack('Vsize', $raw, 4)['size'] ?? null;
        if (!is_int($riffSize) || $riffSize < 4 || $riffSize > $size - 8 || ($riffSize & 1) !== 0) {
            throw new ConversionException('Invalid WebP RIFF size.');
        }
        // RFC 9649 permits readers to ignore bytes outside the RIFF envelope.
        $end = 8 + $riffSize;
        for ($offset = 12; $offset < $end;) {
            $remaining = $end - $offset;
            if ($remaining < 8) {
                throw new ConversionException('Truncated WebP chunk header.');
            }
            $type = substr($raw, $offset, 4);
            $length = unpack('Vlength', $raw, $offset + 4)['length'] ?? null;
            $payload = $offset + 8;
            if (!is_int($length) || $length < 0 || $length > $end - $payload) {
                throw new ConversionException('Invalid WebP chunk length.');
            }
            $offset = $payload + $length;
            if (($length & 1) !== 0) {
                if ($offset >= $end || $raw[$offset] !== "\0") {
                    throw new ConversionException('Invalid WebP chunk padding.');
                }
                ++$offset;
            }
            if ($type === 'VP8X' && $length >= 1 && (ord($raw[$payload]) & 0x02) !== 0) {
                throw new ConversionException('Animated WebP is not supported.');
            }
        }
    }
}
