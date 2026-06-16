<?php

declare(strict_types=1);

namespace Componenta\Image;

use Psr\Http\Message\StreamInterface;

/**
 * GD-based image converter to AVIF format.
 */
final class GdAvifConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> MIME types that can be converted to AVIF. */
    private const array CONVERTIBLE = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    public function __construct(
        private readonly int $defaultQuality = 80,
    ) {}

    /**
     * @var list<string>
     */
    public array $conversionTargets {
        get => ['avif', 'image/avif'];
    }

    #[\Override]
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage
    {
        if (!function_exists('imageavif')) {
            throw new ConversionException('AVIF encoding is not available in the current GD build');
        }

        $quality ??= $this->defaultQuality;
        $this->assertQuality($quality);

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
            $success = imageavif($gd, null, $quality);
            $avif = ob_get_clean();

            if (!$success || $avif === false || $avif === '') {
                throw new ConversionException('AVIF encoding failed');
            }

            return new ConvertedImage(
                content: $avif,
                mimeType: 'image/avif',
                extension: 'avif',
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

    private function assertQuality(int $quality): void
    {
        if ($quality < 0 || $quality > 100) {
            throw ConversionException::invalidQuality($quality);
        }
    }
}
