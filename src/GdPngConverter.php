<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Image\Converter\Internal\GdProcessor;
use Psr\Http\Message\StreamInterface;

final readonly class GdPngConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED = ['image/jpeg', 'image/webp', 'image/avif', 'image/bmp'];

    public function __construct(
        private int $defaultCompressionLevel = 6,
        private ImageConversionLimits $limits = new ImageConversionLimits(),
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
        ?int $quality = null,
    ): ConvertedImage {
        $quality = $quality;
        if ($quality !== null && ($quality < 0 || $quality > 100)) {
            throw ConversionException::invalidQuality($quality);
        }
        $level = $quality === null ? $this->defaultCompressionLevel : 9 - (int) round($quality / 100 * 9);
        $content = GdProcessor::process($source, self::SUPPORTED, 'image/png', $level, $this->limits);

        return new ConvertedImage($content, 'image/png', 'png');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return GdProcessor::supports($mimeType, self::SUPPORTED, 'image/png');
    }
}
