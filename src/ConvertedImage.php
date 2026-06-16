<?php

declare(strict_types=1);

namespace Componenta\Image;

/**
 * Value object representing a converted image.
 */
final class ConvertedImage
{
    public function __construct(
        /** Raw image bytes. */
        public readonly string $content,

        /** MIME type of the converted image (e.g. 'image/webp'). */
        public readonly string $mimeType,

        /** File extension without dot (e.g. 'webp'). */
        public readonly string $extension,
    ) {}

    /** Converted image size in bytes. */
    public int $size {
        get => strlen($this->content);
    }
}
