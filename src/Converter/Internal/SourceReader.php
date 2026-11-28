<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;
use Componenta\Image\ConversionExceptionInterface;
use Componenta\Image\ConversionStreamException;
use Componenta\Image\ConversionStreamExceptionInterface;
use Componenta\Image\ConversionStreamPositionRestoreException;
use Psr\Http\Message\StreamInterface;

/** @internal */
final class SourceReader
{
    /**
     * @throws ConversionExceptionInterface
     * @throws ConversionStreamExceptionInterface
     */
    public static function read(string|StreamInterface $source, int $maxBytes): string
    {
        if (is_string($source)) {
            self::assertSize($source, $maxBytes);

            return $source;
        }

        $position = null;
        $failure = null;
        try {
            if (!$source->isReadable()) {
                throw new ConversionStreamException('Image source is not readable.');
            }
            if ($source->isSeekable()) {
                $position = $source->tell();
                $source->rewind();
            }

            $bytes = '';
            $remaining = $maxBytes;
            $madeProgress = true;
            while (!$source->eof()) {
                if (!$madeProgress) {
                    throw new ConversionStreamException('Image source made no progress before EOF.');
                }
                $length = $remaining === 0 ? 1 : min(65536, $remaining);
                $chunk = $source->read($length);
                $count = strlen($chunk);
                if ($count > $remaining) {
                    throw new ConversionException('Image source exceeds the input byte limit.');
                }
                if ($count > $length) {
                    throw new ConversionStreamException('Image stream returned more bytes than requested.');
                }
                $madeProgress = $count !== 0;
                $bytes .= $chunk;
                $remaining -= $count;
            }

            return $bytes;
        } catch (\Throwable $error) {
            $failure = $error instanceof \RuntimeException
                && !$error instanceof ConversionExceptionInterface && !$error instanceof ConversionStreamExceptionInterface
                ? new ConversionStreamException('Unable to read image source.', previous: $error)
                : $error;
            throw $failure;
        } finally {
            if ($position !== null) {
                try {
                    $source->seek($position);
                } catch (\Throwable $error) {
                    throw new ConversionStreamPositionRestoreException($error, $failure);
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
