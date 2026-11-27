<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final class GdWebPConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/jpeg', 'image/png', 'image/avif', 'image/bmp'];

    public function __construct(
        private readonly int $defaultQuality = 80,
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
    ) {
        if ($defaultQuality < 0 || $defaultQuality > 100) {
            throw ConversionException::invalidQuality($defaultQuality);
        }
    }

    /** @var list<string> */
    public array $conversionTargets {
        get => ['webp', 'image/webp'];
    }

    #[\Override]
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage {
        if ($options?->compressionLevel !== null || $options?->avifSpeed !== null) {
            throw new ConversionException('Options are not supported by this image codec.');
        }
        $level = $options?->quality ?? $this->defaultQuality;
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/webp', $level, $this->limits);

        return new ConvertedImage($content, 'image/webp', 'webp');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/webp');
    }
}
