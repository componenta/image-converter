<?php

declare(strict_types=1);

namespace Componenta\Image;

use Psr\Http\Message\StreamInterface;

/**
 * Interface for image format conversion.
 */
interface ImageConverterInterface
{
    /**
     * Convert image to an optimized format.
     *
     * @param string|StreamInterface $source Raw image content or PSR-7 stream
     * @param int|null $quality Quality level (0-100), null for default
     * @return ConvertedImage Converted image data
     *
     * @throws ConversionException If conversion fails
     */
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage;

    /**
     * Whether the given MIME type can be converted.
     */
    public function supports(string $mimeType): bool;
}
