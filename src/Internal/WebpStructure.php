<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\Converter\Exception\ConversionException;

/** Bounded still-image RIFF framing check (RFC 9649, sections 2.3–2.7), not a bitstream validator. @internal */
final class WebpStructure
{
    /** @throws ConversionException */
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
        $hasExtendedHeader = false;
        $hasImage = false;
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
            if ($type === 'VP8X') {
                if ($hasExtendedHeader || $hasImage || $length < 10) {
                    throw new ConversionException('Invalid or duplicate WebP extended header.');
                }
                $hasExtendedHeader = true;
                if ((ord($raw[$payload]) & 0x02) !== 0) {
                    throw new ConversionException('Animated WebP is not supported.');
                }
            } elseif ($type === 'VP8 ' || $type === 'VP8L') {
                if ($hasImage) {
                    throw new ConversionException('Multiple WebP image bitstreams are not supported.');
                }
                $hasImage = true;
            } elseif ($type === 'ANMF') {
                // Even without the animation flag, a frame chunk must not be silently discarded.
                throw new ConversionException('Animated WebP is not supported.');
            }
            // RFC 9649 requires readers to ignore ANIM when the animation flag is clear.
        }
        if (!$hasImage) {
            throw new ConversionException('Missing WebP image bitstream.');
        }
    }
}
