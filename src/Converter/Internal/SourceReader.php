<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;
use Psr\Http\Message\StreamInterface;

/** @internal */
final class SourceReader
{
    public static function read(string|StreamInterface $source, int $maxBytes): string
    {
        if (is_string($source)) {
            self::assertSize($source, $maxBytes);

            return $source;
        }

        $position = null;
        try {
            if (!$source->isReadable()) {
                throw new ConversionException('Image source is not readable.');
            }
            if ($source->isSeekable()) {
                $position = $source->tell();
                $source->rewind();
            }

            $bytes = '';
            $remaining = $maxBytes;
            while (!$source->eof()) {
                $length = $remaining === 0 ? 1 : min(65536, $remaining);
                $chunk = $source->read($length);
                $count = strlen($chunk);
                if ($count > $length || $count > $remaining) {
                    throw new ConversionException('Image source exceeds the input byte limit.');
                }
                if ($count === 0) {
                    if ($source->eof()) {
                        break;
                    }
                    throw new ConversionException('Image source made no progress before EOF.');
                }
                $bytes .= $chunk;
                $remaining -= $count;
            }

            return $bytes;
        } catch (ConversionException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new ConversionException('Unable to read image source.', previous: $error);
        } finally {
            if ($position !== null) {
                try {
                    $source->seek($position);
                } catch (\RuntimeException $error) {
                    throw new ConversionException('Unable to restore image source position.', previous: $error);
                }
            }
        }
    }

    private static function assertSize(string $source, int $maxBytes): void
    {
        if (strlen($source) > $maxBytes) {
            throw new ConversionException('Image source exceeds the input byte limit.');
        }
    }
}
