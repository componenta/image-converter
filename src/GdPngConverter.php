<?php

declare(strict_types=1);

namespace Componenta\Image;

use Psr\Http\Message\StreamInterface;

/**
 * GD-based image converter to PNG format.
 */
final class GdPngConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> MIME types that can be converted to PNG. */
    private const array CONVERTIBLE = [
        'image/jpeg',
        'image/pjpeg',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    /**
     * @param int $defaultCompressionLevel PNG compression level (0-9).
     */
    public function __construct(
        private readonly int $defaultCompressionLevel = 6,
    ) {}

    /**
     * @var list<string>
     */
    public array $conversionTargets {
        get => ['png', 'image/png'];
    }

    #[\Override]
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage
    {
        $compressionLevel = $quality === null
            ? $this->defaultCompressionLevel
            : $this->qualityToCompressionLevel($quality);

        if ($compressionLevel < 0 || $compressionLevel > 9) {
            throw new ConversionException(sprintf(
                'PNG compression level must be between 0 and 9, %d given.',
                $compressionLevel,
            ));
        }

        $raw = $source instanceof StreamInterface ? (string) $source : $source;
        $gd = @imagecreatefromstring($raw);

        if ($gd === false) {
            throw new ConversionException('Failed to create image from source data');
        }

        try {
            if (!imageistruecolor($gd)) {
                imagepalettetotruecolor($gd);
            }

            imagealphablending($gd, false);
            imagesavealpha($gd, true);

            ob_start();
            $success = imagepng($gd, null, $compressionLevel);
            $png = ob_get_clean();

            if (!$success || $png === false || $png === '') {
                throw new ConversionException('PNG encoding failed');
            }

            return new ConvertedImage(
                content: $png,
                mimeType: 'image/png',
                extension: 'png',
            );
        } finally {
            imagedestroy($gd);
        }
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::CONVERTIBLE, true);
    }

    private function qualityToCompressionLevel(int $quality): int
    {
        if ($quality < 0 || $quality > 100) {
            throw ConversionException::invalidQuality($quality);
        }

        return 9 - (int) round($quality / 100 * 9);
    }
}
