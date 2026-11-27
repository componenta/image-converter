<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Detector\DetectorException;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\Converter\Internal\SourceReader;
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
        private readonly ImageConversionLimits $limits = new ImageConversionLimits(),
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

        $source = SourceReader::read($source, $this->limits->maxInputBytes);
        try {
            $sourceMimeType = $this->detector->detectMimeType($source);
        } catch (DetectorException $error) {
            throw new ConversionException('Unable to detect source image MIME type.', previous: $error);
        }

        if ($sourceMimeType === null) {
            throw ConversionException::undetectableSourceType();
        }

        if (!$converter->supports($sourceMimeType->value)) {
            throw ConversionException::unsupportedSourceType($sourceMimeType->value, $targetType);
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

        // A capability probe must not consume a one-shot stream.
        if ($source instanceof StreamInterface && !$source->isSeekable()) {
            return false;
        }
        try {
            $source = SourceReader::read($source, $this->limits->maxInputBytes);
            $sourceMimeType = $this->detector->detectMimeType($source);
        } catch (DetectorException|ConversionException) {
            return false;
        }

        return $sourceMimeType !== null
            && $converter->supports($sourceMimeType->value);
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
