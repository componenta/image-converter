<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\Converter\Exception\ConversionException;

/** @internal */
final class EncodedOutput
{
    /**
     * Encoder callback exceptions propagate unchanged.
     * @param callable(): bool $encode
     * @throws ConversionException If buffering, encoding or the output limit fails.
     */
    public static function capture(callable $encode, int $maxBytes = PHP_INT_MAX): string
    {
        $content = '';
        $overflow = false;
        $level = ob_get_level();
        // GD writes to the PHP output layer. Consume chunks without forwarding any
        // bytes to the caller's buffer; retain at most maxBytes of encoded data.
        if (!ob_start(static function (string $chunk) use (&$content, &$overflow, $maxBytes): string {
            if (!$overflow) {
                if (strlen($chunk) > $maxBytes - strlen($content)) {
                    $overflow = true;
                    $content = '';
                } else {
                    $content .= $chunk;
                }
            }

            return '';
        }, 65536)) {
            throw new ConversionException('Unable to open image output buffer.');
        }

        try {
            $success = $encode();
            if (!ob_end_flush()) {
                throw new ConversionException('Unable to finalize image output buffer.');
            }
            if ($overflow) {
                throw new ConversionException('Encoded image exceeds the output byte limit.');
            }
            if (!$success || $content === '') {
                throw new ConversionException('Image encoding failed.');
            }

            return $content;
        } finally {
            if (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
