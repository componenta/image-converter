<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final class GdPngConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/jpeg', 'image/webp', 'image/avif', 'image/bmp'];

    public function __construct(
        private readonly int $defaultCompressionLevel = 6,
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
    ) {
        if ($defaultCompressionLevel < 0 || $defaultCompressionLevel > 9) {
            throw new ConversionException('PNG compression level must be between 0 and 9.');
        }
    }

    /** @var list<string> */
    public array $conversionTargets {
        get => ['png', 'image/png'];
    }

    #[\Override]
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage {
        if ($options?->quality !== null || $options?->avifSpeed !== null) {
            throw new ConversionException('PNG supports compressionLevel, not quality or avifSpeed.');
        }
        $level = $options?->compressionLevel ?? $this->defaultCompressionLevel;
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/png', $level, $this->limits);

        return new ConvertedImage($content, 'image/png', 'png');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/png');
    }
}
