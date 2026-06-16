<?php

declare(strict_types=1);

namespace Componenta\Image;

/**
 * Thrown when image conversion fails.
 */
final class ConversionException extends \RuntimeException
{
    public static function unsupportedTarget(string $targetType): self
    {
        return new self(sprintf('No image converter is registered for target type "%s".', $targetType));
    }

    public static function undetectableSourceType(): self
    {
        return new self('Unable to detect source image MIME type.');
    }

    public static function unsupportedSourceType(string $sourceMimeType, string $targetType): self
    {
        return new self(sprintf(
            'Image converter for target type "%s" does not support source MIME type "%s".',
            $targetType,
            $sourceMimeType,
        ));
    }

    public static function invalidQuality(int $quality): self
    {
        return new self(sprintf('Image quality must be between 0 and 100, %d given.', $quality));
    }
}
