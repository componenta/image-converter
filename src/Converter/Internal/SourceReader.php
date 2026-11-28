<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\ConversionException;
use Componenta\Image\ConversionStreamPositionRestoreException;
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
        $failure = null;
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
            $madeProgress = true;
            while (!$source->eof()) {
                if (!$madeProgress) {
                    throw new ConversionException('Image source made no progress before EOF.');
                }
                $length = $remaining === 0 ? 1 : min(65536, $remaining);
                $chunk = $source->read($length);
                $count = strlen($chunk);
                if ($count > $length || $count > $remaining) {
                    throw new ConversionException('Image source exceeds the input byte limit.');
                }
                $madeProgress = $count !== 0;
                $bytes .= $chunk;
                $remaining -= $count;
            }

            return $bytes;
        } catch (\Throwable $error) {
            $failure = $error instanceof \RuntimeException && !$error instanceof ConversionException
                ? new ConversionException('Unable to read image source.', previous: $error)
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

    /**
     * Read a local regular file through one handle, bounded even if it grows.
     * This is not a path authorization sandbox.
     */
    public static function readFile(string $path, int $maxBytes): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')
            || strncasecmp($path, 'data:', 5) === 0) {
            throw new ConversionException('Expected a local image file path.');
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            clearstatcache(true, $path);
            // Resolve before opening so that a local filename cannot be interpreted
            // as a stream wrapper. Symlinks to regular files are allowed.
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved)) {
                throw new ConversionException('Image source is not a readable regular file.');
            }

            // Avoid blocking on a FIFO substituted between the path check and open.
            // On Windows the nonblocking fopen flag is not supported.
            $handle = fopen($resolved, PHP_OS_FAMILY === 'Windows' ? 'rb' : 'rbn');
            if ($handle === false) {
                throw new ConversionException('Unable to open image file.');
            }
            try {
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    throw new ConversionException('Image source is not a regular file.');
                }
                if ($stat['size'] > $maxBytes) {
                    throw new ConversionException('Image source exceeds the input byte limit.');
                }

                $bytes = '';
                $remaining = $maxBytes;
                while (!feof($handle)) {
                    $chunk = fread($handle, $remaining === 0 ? 1 : min(65536, $remaining));
                    if ($chunk === false) {
                        throw new ConversionException('Unable to read image file.');
                    }
                    $length = strlen($chunk);
                    if ($length > $remaining) {
                        throw new ConversionException('Image source exceeds the input byte limit.');
                    }
                    if ($length === 0 && !feof($handle)) {
                        throw new ConversionException('Image file made no progress before EOF.');
                    }
                    $bytes .= $chunk;
                    $remaining -= $length;
                }

                return $bytes;
            } finally {
                fclose($handle);
            }
        } catch (\ErrorException|\ValueError $error) {
            throw new ConversionException('Unable to read image file.', previous: $error);
        } finally {
            restore_error_handler();
        }
    }

    private static function assertSize(string $source, int $maxBytes): void
    {
        if (strlen($source) > $maxBytes) {
            throw new ConversionException('Image source exceeds the input byte limit.');
        }
    }
}
