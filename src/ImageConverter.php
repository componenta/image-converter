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

    /** @var array<string, string> */
    private array $targetFormats = [];

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
        $declared = $converter instanceof ImageConversionTargetsProviderInterface
            ? $converter->conversionTargets
            : [];
        if ($targetTypes === []) {
            $targetTypes = $declared;
        }
        if ($targetTypes === []) {
            throw new \InvalidArgumentException(
                'Target types must be provided for image converters that do not expose conversion targets.',
            );
        }

        $normalized = [];
        foreach ($targetTypes as $targetType) {
            if (!is_string($targetType)) {
                throw new \InvalidArgumentException('Invalid image conversion target.');
            }
            $target = $this->normalizeTargetType($targetType);
            $this->assertValidTarget($target);
            $normalized[$target] = $target;
        }

        $declaredFormat = $this->declaredFormat($declared);
        $explicitFormat = $this->declaredFormat($targetTypes);
        if ($declaredFormat !== null && $explicitFormat !== null && $declaredFormat !== $explicitFormat) {
            throw new \InvalidArgumentException('Target format conflicts with the converter declaration.');
        }
        $format = $declaredFormat ?? $explicitFormat;
        if ($format === null) {
            if (count($normalized) !== 1) {
                throw new \InvalidArgumentException('Multiple custom aliases require a canonical image MIME type.');
            }
            $format = reset($normalized);
        }

        foreach ($normalized as $target) {
            if (isset($this->converters[$target])
                && ($this->converters[$target] !== $converter || $this->targetFormats[$target] !== $format)) {
                throw new \InvalidArgumentException('A different image converter is already registered for this target.');
            }
        }
        foreach ($normalized as $target) {
            $this->converters[$target] = $converter;
            $this->targetFormats[$target] = $format;
        }
    }

    /** @param array<array-key, mixed> $targets */
    private function declaredFormat(array $targets): ?string
    {
        $format = null;
        foreach ($targets as $alias) {
            if (!is_string($alias)) {
                throw new \InvalidArgumentException('Invalid image conversion target.');
            }
            $target = $this->normalizeTargetType($alias);
            $this->assertValidTarget($target);
            if (!str_starts_with(strtolower(trim($alias)), 'image/')
                && !in_array($target, ['jpeg', 'png', 'webp', 'avif'], true)) {
                continue;
            }
            if ($format !== null && $format !== $target) {
                throw new \InvalidArgumentException('A fixed converter must declare one target image format.');
            }
            $format = $target;
        }

        return $format;
    }

    private function assertValidTarget(string $target): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $target)
            || MimeType::tryCreate('image/' . $target) === null) {
            throw new \InvalidArgumentException('Invalid image conversion target.');
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
        if ($image->size > $this->limits->maxOutputBytes) {
            throw new ConversionException('Encoded image exceeds the output byte limit.');
        }
        $resultMime = MimeType::tryCreate($image->mimeType);
        if ($image->content === '' || $resultMime === null || !$resultMime->isImage()) {
            throw new ConversionException('Converter returned an invalid image result.');
        }
        if ($this->normalizeTargetType($resultMime->value) !== $this->targetFormats[$normalizedTargetType]) {
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
