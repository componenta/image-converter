<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Codec;

use Componenta\Image\Converter\Contract\ImageConverterInterface;
use Componenta\Image\Converter\Contract\ImageConversionTargetProviderInterface;
use Componenta\Image\Converter\ImageConversionOptions;
use Componenta\Image\Converter\ImageConversionLimits;
use Componenta\Image\Converter\ConvertedImage;
use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\Exception\ConversionConfigurationException;
use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final class GdWebPConverter implements ImageConverterInterface, ImageConversionTargetProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/jpeg', 'image/png', 'image/avif', 'image/bmp'];

    /** @throws ConversionConfigurationException */
    public function __construct(
        private readonly int $defaultQuality = 80,
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
    ) {
        if ($defaultQuality < 0 || $defaultQuality > 100) {
            throw new ConversionConfigurationException(sprintf('Default quality must be between 0 and 100, %d given.', $defaultQuality));
        }
    }

    public string $targetMimeType {
        get => 'image/webp';
    }

    /** @var list<string> */
    public array $targetAliases {
        get => [];
    }

    /** @inheritDoc */
    #[\Override]
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage {
        if ($options?->compressionLevel !== null || $options?->avifSpeed !== null) {
            throw new ConversionException('Options are not supported by this image codec.');
        }
        $level = $options->quality ?? $this->defaultQuality;
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/webp', $level, $this->limits);

        return new ConvertedImage($content, 'image/webp', 'webp');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/webp');
    }
}
