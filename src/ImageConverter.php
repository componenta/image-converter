<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Detector\MimeTypeDetectorInterface;
use Psr\Http\Message\StreamInterface;

final class ImageConverter
{
    /** @var array<string, ImageConverterInterface> */
    private array $converters = [];

    /**
     * @param iterable<ImageConverterInterface> $converters
     */
    public function __construct(
        private readonly MimeTypeDetectorInterface $detector,
        iterable $converters = [],
    ) {
        foreach ($converters as $converter) {
            $this->addConverter($converter);
        }
    }

    public function addConverter(ImageConverterInterface $converter, string ...$targetTypes): void
    {
        if ($targetTypes === [] && $converter instanceof ImageConversionTargetsProviderInterface) {
            $targetTypes = $converter->conversionTargets;
        }

        if ($targetTypes === []) {
            throw new \InvalidArgumentException(
                'Target types must be provided for image converters that do not expose conversion targets.',
            );
        }

        foreach ($targetTypes as $targetType) {
            $this->converters[$this->normalizeTargetType($targetType)] = $converter;
        }
    }

    public function convert(string|StreamInterface $source, string $targetType, ?int $quality = null): ConvertedImage
    {
        $normalizedTargetType = $this->normalizeTargetType($targetType);
        $converter = $this->converters[$normalizedTargetType] ?? null;

        if (!$converter instanceof ImageConverterInterface) {
            throw ConversionException::unsupportedTarget($targetType);
        }

        $sourceMimeType = $this->detector->detectMimeType($source);

        if (!is_string($sourceMimeType) || $sourceMimeType === '') {
            throw ConversionException::undetectableSourceType();
        }

        if (!$converter->supports($sourceMimeType)) {
            throw ConversionException::unsupportedSourceType($sourceMimeType, $targetType);
        }

        return $converter->convert($source, $quality);
    }

    public function canConvert(string|StreamInterface $source, string $targetType): bool
    {
        $normalizedTargetType = $this->normalizeTargetType($targetType);
        $converter = $this->converters[$normalizedTargetType] ?? null;

        if (!$converter instanceof ImageConverterInterface) {
            return false;
        }

        try {
            $sourceMimeType = $this->detector->detectMimeType($source);
        } catch (\Throwable) {
            return false;
        }

        return is_string($sourceMimeType)
            && $sourceMimeType !== ''
            && $converter->supports($sourceMimeType);
    }

    private function normalizeTargetType(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        $targetType = ltrim($targetType, '.');

        if (str_starts_with($targetType, 'image/')) {
            $targetType = substr($targetType, 6);
        }

        return match ($targetType) {
            'jpg', 'pjpeg', 'jpeg' => 'jpeg',
            'x-png', 'png' => 'png',
            default => $targetType,
        };
    }
}
