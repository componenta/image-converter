<?php

declare(strict_types=1);

namespace Componenta\Image;

/** Byte and dimension limits for image processing; not a guarantee of native codec memory or CPU usage. */
final readonly class ImageConversionLimits
{
    public function __construct(
        public int $maxInputBytes = 33554432,
        public int $maxPixels = 16000000,
        public int $maxWidth = 8192,
        public int $maxHeight = 8192,
        public int $maxOutputBytes = 67108864,
    ) {
        if ($maxInputBytes < 1 || $maxPixels < 1 || $maxWidth < 1 || $maxHeight < 1 || $maxOutputBytes < 1) {
            throw new \InvalidArgumentException('Image resource limits must be positive.');
        }
    }
}
