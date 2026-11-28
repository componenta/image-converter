<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Internal;

use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Exception\ConversionFileExceptionInterface;
use Componenta\Image\Converter\Exception\ConversionFileReadException;
use Componenta\Image\Converter\Exception\ConversionFileUnavailableException;
use Componenta\Image\Converter\Exception\ConversionInvalidFileException;

/** @internal */
final class FileReader
{
    /**
     * Read a caller-authorized regular file, bounded even if it grows.
     *
     * @throws ConversionFileExceptionInterface
     * @throws ConversionException If the input byte limit is exceeded.
     */
    public static function read(string $path, int $maxBytes): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')
            || strncasecmp($path, 'data:', 5) === 0) {
            throw new ConversionInvalidFileException('Expected a local image file path.');
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            $resolved = self::resolve($path);
            // Nonblocking open protects against a FIFO substituted after resolution.
            $handle = fopen($resolved, PHP_OS_FAMILY === 'Windows' ? 'rb' : 'rbn');
            if ($handle === false) {
                throw new ConversionFileReadException('Unable to open image file.');
            }

            try {
                $stat = fstat($handle);
                if ($stat === false) {
                    throw new ConversionFileReadException('Unable to inspect opened image file.');
                }
                if (($stat['mode'] & 0170000) !== 0100000) {
                    throw new ConversionInvalidFileException('Image source is not a regular file.');
                }
                if ($stat['size'] > $maxBytes) {
                    throw new ConversionException('Image source exceeds the input byte limit.');
                }

                $bytes = '';
                $remaining = $maxBytes;
                while (!feof($handle)) {
                    $chunk = fread($handle, $remaining === 0 ? 1 : min(65536, $remaining));
                    if ($chunk === false) {
                        throw new ConversionFileReadException('Unable to read image file.');
                    }
                    $length = strlen($chunk);
                    if ($length > $remaining) {
                        throw new ConversionException('Image source exceeds the input byte limit.');
                    }
                    if ($length === 0 && !feof($handle)) {
                        throw new ConversionFileReadException('Image file made no progress before EOF.');
                    }
                    $bytes .= $chunk;
                    $remaining -= $length;
                }

                return $bytes;
            } finally {
                fclose($handle);
            }
        } catch (\ErrorException|\ValueError $error) {
            throw new ConversionFileReadException('Unable to read image file.', previous: $error);
        } finally {
            restore_error_handler();
        }
    }

    /** @throws ConversionFileExceptionInterface */
    private static function resolve(string $path): string
    {
        try {
            clearstatcache(true, $path);
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new ConversionFileUnavailableException('Image file does not exist or cannot be accessed.');
            }
            $stat = stat($resolved);
            if ($stat === false) {
                throw new ConversionFileUnavailableException('Image file does not exist or cannot be accessed.');
            }
        } catch (\ErrorException|\ValueError $error) {
            throw new ConversionFileUnavailableException('Image file does not exist or cannot be accessed.', previous: $error);
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new ConversionInvalidFileException('Image source is not a regular file.');
        }

        return $resolved;
    }
}
