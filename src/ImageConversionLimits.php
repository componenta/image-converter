<?php

declare(strict_types=1);

namespace Componenta\Image\Converter;

use Componenta\Image\Converter\Exception\ConversionConfigurationException;

/** Byte limits for orchestration and codecs; geometry limits for codecs. Not a native memory or CPU bound. */
final readonly class ImageConversionLimits
{
    /** @throws ConversionConfigurationException */
    public function __construct(
        public int $maxInputBytes = 33554432,
        public int $maxPixels = 16000000,
        public int $maxWidth = 8192,
        public int $maxHeight = 8192,
        public int $maxOutputBytes = 67108864,
    ) {
        if ($maxInputBytes < 1 || $maxPixels < 1 || $maxWidth < 1 || $maxHeight < 1 || $maxOutputBytes < 1) {
            throw new ConversionConfigurationException('Image resource limits must be positive.');
        }
    }
}
