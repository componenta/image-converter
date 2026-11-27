<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Detector\DetectorException;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Detector\MimeType;
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

        $normalized = [];
        foreach ($targetTypes as $targetType) {
            $target = $this->normalizeTargetType($targetType);
            if (!preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $target)) {
                throw new \InvalidArgumentException('Invalid image conversion target.');
            }
            if (isset($this->converters[$target]) && $this->converters[$target] !== $converter) {
                throw new \InvalidArgumentException('A different image converter is already registered for this target.');
            }
            $normalized[] = $target;
        }
        foreach ($normalized as $target) {
            $this->converters[$target] = $converter;
        }
    }

    public function convert(string|StreamInterface $source, string $targetType, ?ImageConversionOptions $options = null): ConvertedImage
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

        $image = $converter->convert($source, $options);
        $resultMime = MimeType::tryCreate($image->mimeType);
        if ($image->content === '' || $resultMime === null || !$resultMime->isImage()) {
            throw new ConversionException('Converter returned an invalid image result.');
        }
        if ((in_array($normalizedTargetType, ['jpeg', 'png', 'webp', 'avif'], true)
                || str_starts_with(strtolower(trim($targetType)), 'image/'))
            && $this->normalizeTargetType($resultMime->value) !== $normalizedTargetType) {
            throw new ConversionException('Converter returned a different image format.');
        }

        return $image;
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
