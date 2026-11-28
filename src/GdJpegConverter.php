<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final class GdJpegConverter implements ImageConverterInterface, ImageConversionTargetProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/png', 'image/webp', 'image/avif', 'image/bmp'];

    public function __construct(
        private readonly int $defaultQuality = 82,
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
    ) {
        if ($defaultQuality < 0 || $defaultQuality > 100) {
            throw ConversionException::invalidQuality($defaultQuality);
        }
    }

    public string $targetMimeType {
        get => 'image/jpeg';
    }

    /** @var list<string> */
    public array $targetAliases {
        get => ['jpg'];
    }

    #[\Override]
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage {
        if ($options?->compressionLevel !== null || $options?->avifSpeed !== null) {
            throw new ConversionException('Options are not supported by this image codec.');
        }
        $level = $options->quality ?? $this->defaultQuality;
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/jpeg', $level, $this->limits);

        return new ConvertedImage($content, 'image/jpeg', 'jpg');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/jpeg');
    }
}
