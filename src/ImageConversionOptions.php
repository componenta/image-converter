<?php

declare(strict_types=1);

namespace Componenta\Image;

final readonly class ImageConversionOptions
{
    public function __construct(
        public ?int $quality = null,
        public ?int $compressionLevel = null,
        public ?int $avifSpeed = null,
    ) {
        if ($quality !== null && ($quality < 0 || $quality > 100)) {
            throw ConversionException::invalidQuality($quality);
        }
        if ($compressionLevel !== null && ($compressionLevel < 0 || $compressionLevel > 9)) {
            throw new ConversionException('PNG compression level must be between 0 and 9.');
        }
        if ($avifSpeed !== null && ($avifSpeed < 0 || $avifSpeed > 10)) {
            throw new ConversionException('AVIF speed must be between 0 and 10.');
        }
        if ($compressionLevel !== null && ($quality !== null || $avifSpeed !== null)) {
            throw new ConversionException('PNG compression level cannot be combined with lossy codec settings.');
        }
    }
}
