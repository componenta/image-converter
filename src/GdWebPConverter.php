<?php

declare(strict_types=1);

namespace Componenta\Image;

use Psr\Http\Message\StreamInterface;

/**
 * GD-based image converter to WebP format.
 *
 * Converts JPEG, PNG and BMP images to WebP using the built-in GD extension.
 * GIF is excluded because GD loses animation (only the first frame is kept).
 * WebP is excluded because it is already the target format.
 *
 * Preserves PNG alpha transparency in the resulting WebP.
 */
final class GdWebPConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> MIME types that can be converted to WebP. */
    private const array CONVERTIBLE = [
        'image/jpeg',
        'image/png',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    /**
     * @param int $defaultQuality Default WebP quality (0-100). Higher = better quality, larger file.
     */
    public function __construct(
        private readonly int $defaultQuality = 80,
    ) {}

    /**
     * @var list<string>
     */
    public array $conversionTargets {
        get => ['webp', 'image/webp'];
    }

    #[\Override]
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage
    {
        $quality ??= $this->defaultQuality;
        $this->assertQuality($quality);

        $raw = $source instanceof StreamInterface ? (string) $source : $source;

        $gd = @imagecreatefromstring($raw);

        if ($gd === false) {
            throw new ConversionException('Failed to create image from source data');
        }

        try {
            // Preserve alpha transparency (important for PNG -> WebP)
            if (!imageistruecolor($gd)) {
                imagepalettetotruecolor($gd);
            }

            imagesavealpha($gd, true);
            imagealphablending($gd, false);

            ob_start();
            $success = imagewebp($gd, null, $quality);
            $webp = ob_get_clean();

            if (!$success || $webp === false || $webp === '') {
                throw new ConversionException('WebP encoding failed');
            }

            return new ConvertedImage(
                content: $webp,
                mimeType: 'image/webp',
                extension: 'webp',
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
