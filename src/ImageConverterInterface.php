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
     * Convert image to the fixed target format; reject unsupported options.
     *
     * @param string|StreamInterface $source Raw image content or PSR-7 stream
     * @param ImageConversionOptions|null $options Format-specific settings, null for defaults
     * @return ConvertedImage Converted image data
     *
     * @throws ConversionException If conversion fails
     */
    public function convert(string|StreamInterface $source, ?ImageConversionOptions $options = null): ConvertedImage;

    /**
     * Whether the given MIME type can be converted.
     */
    public function supports(string $mimeType): bool;
}
