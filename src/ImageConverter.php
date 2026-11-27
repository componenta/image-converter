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
    /** @var array<string, array{codec: ImageConverterInterface, format: string}> */
    private array $converters = [];

    /**
     * @param iterable<ImageConverterInterface> $converters
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

        // Resolve the immutable format before publishing aliases. A fixed converter
        // cannot acquire a different output format through a later registration.
        $provider = $this->parseTargets($declared);
        $requested = $this->parseTargets($targetTypes);
        $format = $provider['format']
            ?? (count($provider['aliases']) === 1 ? $provider['aliases'][0] : null);
        foreach ($this->converters as $registration) {
            if ($registration['codec'] === $converter) {
                if ($format !== null && $format !== $registration['format']) {
                    throw new \InvalidArgumentException('Target format conflicts with the existing converter registration.');
                }
                $format = $registration['format'];
                break;
            }
        }
        if ($format !== null && $requested['format'] !== null && $format !== $requested['format']) {
            throw new \InvalidArgumentException('Target format conflicts with the converter declaration.');
        }
        $format ??= $requested['format'];
        $normalized = $requested['aliases'];
        if ($format === null) {
            if (count($normalized) !== 1 || count($provider['aliases']) > 1) {
                throw new \InvalidArgumentException('Multiple custom aliases require a canonical image MIME type.');
            }
            $format = $normalized[0];
        }

        foreach ($normalized as $target) {
            if (isset($this->converters[$target])
                && ($this->converters[$target]['codec'] !== $converter || $this->converters[$target]['format'] !== $format)) {
                throw new \InvalidArgumentException('A different image converter is already registered for this target.');
            }
        }
        foreach ($normalized as $target) {
            $this->converters[$target] = ['codec' => $converter, 'format' => $format];
        }
    }

    /**
     * @param array<array-key, mixed> $targets
     * @return array{aliases: list<string>, format: ?string}
     */
    private function parseTargets(array $targets): array
    {
        $aliases = [];
        $format = null;
        foreach ($targets as $alias) {
            if (!is_string($alias)) {
                throw new \InvalidArgumentException('Invalid image conversion target.');
            }
            $target = $this->normalizeTargetType($alias);
            $this->assertValidTarget($target);
            $aliases[$target] = $target;
            if (!str_starts_with(strtolower(trim($alias)), 'image/')
                && !in_array($target, ['jpeg', 'png', 'webp', 'avif'], true)) {
                continue;
            }
            if ($format !== null && $format !== $target) {
                throw new \InvalidArgumentException('A fixed converter must declare one target image format.');
            }
            $format = $target;
        }

        return ['aliases' => array_values($aliases), 'format' => $format];
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
