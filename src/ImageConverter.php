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
    /** @var array<array-key, array{codec: ImageConverterInterface, format: string}> */
    private array $converters = [];

    /**
     * @param iterable<ImageConverterInterface&ImageConversionTargetProviderInterface> $converters
     * @param ImageConversionLimits $limits Byte limits for the orchestrator; configure each codec's own geometry and byte limits.
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

    public function addConverter(
        ImageConverterInterface&ImageConversionTargetProviderInterface $converter,
    ): void {
        $mime = MimeType::tryCreate($converter->targetMimeType);
        if ($mime === null || !$mime->isImage() || $mime->parameters !== []) {
            throw new \InvalidArgumentException('Target must be a concrete image MIME type without parameters.');
        }
        $format = $this->normalizeTargetType($mime->value);
        $name = substr($format, 6);
        $targets = [$format, $name];

        // Implementations can violate PHPDoc element types at runtime.
        /** @var array<array-key, mixed> $aliases */
        $aliases = $converter->targetAliases;
        if (!array_is_list($aliases)) {
            throw new \InvalidArgumentException('Target aliases must be a list of short names.');
        }
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                throw new \InvalidArgumentException('Invalid image conversion alias.');
            }
            $target = $this->normalizeTargetType($alias);
            if (!preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $target)) {
                throw new \InvalidArgumentException('Invalid image conversion alias.');
            }
            if (in_array($target, ['jpeg', 'png', 'webp', 'avif'], true) && $target !== $name) {
                throw new \InvalidArgumentException('Alias conflicts with a built-in image format.');
            }
            $targets[] = $target;
        }

        // Snapshot the declaration; the same instance cannot change its output format.
        foreach ($this->converters as $registration) {
            if ($registration['codec'] === $converter && $registration['format'] !== $format) {
                throw new \InvalidArgumentException('Target format conflicts with the existing converter registration.');
            }
        }
        foreach ($targets as $target) {
            if (isset($this->converters[$target])
                && ($this->converters[$target]['codec'] !== $converter || $this->converters[$target]['format'] !== $format)) {
                throw new \InvalidArgumentException('A different image converter is already registered for this target.');
            }
        }
        foreach ($targets as $target) {
            $this->converters[$target] = ['codec' => $converter, 'format' => $format];
        }
    }

    public function convert(string|StreamInterface $source, string $targetType, ?ImageConversionOptions $options = null): ConvertedImage
    {
        $normalizedTargetType = $this->normalizeTargetType($targetType);
        $registration = $this->converters[$normalizedTargetType] ?? null;

        if ($registration === null) {
            throw ConversionException::unsupportedTarget($targetType);
        }
        $converter = $registration['codec'];

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
        if ($this->normalizeTargetType($image->mimeType) !== $registration['format']) {
            throw new ConversionException('Converter returned a different image format.');
        }

        return $image;
    }

    public function canConvert(string|StreamInterface $source, string $targetType): bool
    {
        $normalizedTargetType = $this->normalizeTargetType($targetType);
        $registration = $this->converters[$normalizedTargetType] ?? null;

        if ($registration === null) {
            return false;
        }

        // A capability probe must not consume a one-shot stream.
        if ($source instanceof StreamInterface && !$source->isSeekable()) {
            return false;
        }
        try {
            $source = SourceReader::read($source, $this->limits->maxInputBytes);
            $sourceMimeType = $this->detector->detectMimeType($source);
        } catch (ConversionStreamPositionRestoreException $error) {
            // A failed restoration is a broken ownership guarantee, not a negative capability.
            throw $error;
        } catch (DetectorException|ConversionException) {
            return false;
        }

        return $sourceMimeType !== null
            && $registration['codec']->supports($sourceMimeType->value);
    }

    private function normalizeTargetType(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        $isMime = str_starts_with($targetType, 'image/');
        $name = $isMime ? substr($targetType, 6) : ltrim($targetType, '.');
        $name = match ($name) {
            'jpg', 'pjpeg', 'jpeg' => 'jpeg',
            'x-png', 'png' => 'png',
            default => $name,
        };

        return $isMime ? 'image/' . $name : $name;
    }
}
