<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final class GdAvifConverter implements ImageConverterInterface, ImageConversionTargetProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'];

    /** @throws ConversionExceptionInterface */
    public function __construct(
        private readonly int $defaultQuality = 80,
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
    ) {
        if ($defaultQuality < 0 || $defaultQuality > 100) {
            throw new ConversionConfigurationException(sprintf('Default quality must be between 0 and 100, %d given.', $defaultQuality));
        }
    }

    public string $targetMimeType {
        get => 'image/avif';
    }

    /** @var list<string> */
    public array $targetAliases {
        get => [];
    }

    #[\Override]
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage {
        if ($options?->compressionLevel !== null) {
            throw new ConversionException('Options are not supported by this image codec.');
        }
        $level = $options->quality ?? $this->defaultQuality;
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/avif', $level, $this->limits, avifSpeed: $options?->avifSpeed);

        return new ConvertedImage($content, 'image/avif', 'avif');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/avif');
    }
}
