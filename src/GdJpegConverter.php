<?php

declare(strict_types=1);

namespace Componenta\Image;

use Psr\Http\Message\StreamInterface;

/**
 * GD-based image converter to JPEG format.
 */
final class GdJpegConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    /** @var list<string> MIME types that can be converted to JPEG. */
    private const array CONVERTIBLE = [
        'image/png',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    public function __construct(
        private readonly int $defaultQuality = 82,
    ) {}

    /**
     * @var list<string>
     */
    public array $conversionTargets {
        get => ['jpeg', 'jpg', 'image/jpeg'];
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

        $canvas = null;

        try {
            $width = imagesx($gd);
            $height = imagesy($gd);
            $canvas = imagecreatetruecolor($width, $height);

            if ($canvas === false) {
                throw new ConversionException('Failed to create JPEG canvas');
            }

            $background = imagecolorallocate($canvas, 255, 255, 255);

            if ($background === false) {
                throw new ConversionException('Failed to allocate JPEG background color');
            }

            imagefill($canvas, 0, 0, $background);
            imagecopy($canvas, $gd, 0, 0, 0, 0, $width, $height);

            ob_start();
            $success = imagejpeg($canvas, null, $quality);
            $jpeg = ob_get_clean();

            if (!$success || $jpeg === false || $jpeg === '') {
                throw new ConversionException('JPEG encoding failed');
            }

            return new ConvertedImage(
                content: $jpeg,
                mimeType: 'image/jpeg',
                extension: 'jpg',
            );
        } finally {
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }

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
