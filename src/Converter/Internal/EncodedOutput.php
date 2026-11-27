<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;

/** @internal */
final class EncodedOutput
{
    /** @param callable(): bool $encode */
    public static function capture(callable $encode): string
    {
        if (!ob_start()) {
            throw new ConversionException('Unable to open image output buffer.');
        }
        try {
            $success = $encode();
            $content = ob_get_contents();
            if (!$success || $content === false || $content === '') {
                throw new ConversionException('Image encoding failed.');
            }

            return $content;
        } finally {
            ob_end_clean();
        }
    }
}
