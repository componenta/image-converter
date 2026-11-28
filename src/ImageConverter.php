<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Detector\DetectorException;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Detector\MimeType;
use Componenta\Image\Converter\Internal\FileReader;
use Componenta\Image\Converter\Internal\SourceReader;
use Psr\Http\Message\StreamInterface;

final class ImageConverter
{
    /** @var array<array-key, array{codec: ImageConverterInterface, format: string}> */
    private array $converters = [];

    /**
     * Registered canonical target MIME types, in registration order.
     * Does not probe codec availability or inspect any source image.
     *
     * @var list<string>
     */
    public array $targetMimeTypes {
        get {
            $types = [];
            foreach ($this->converters as $registration) {
                $types[$registration['format']] = true;
            }

            return array_keys($types);
        }
    }

    /**
     * @param iterable<ImageConverterInterface&ImageConversionTargetProviderInterface> $converters
     * @param ImageConversionLimits $limits Byte limits for the orchestrator; configure each codec's own geometry and byte limits.
     * @throws ConversionExceptionInterface
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

    /** @throws ConversionExceptionInterface */
    public function addConverter(
        ImageConverterInterface&ImageConversionTargetProviderInterface $converter,
    ): void {
        $mime = MimeType::tryCreate($converter->targetMimeType);
        if ($mime === null || !$mime->isImage() || $mime->parameters !== []) {
            throw new ConversionConfigurationException('Target must be a concrete image MIME type without parameters.');
        }
        $format = $this->normalizeTargetType($mime->value);
        $name = substr($format, 6);
        $targets = [$format, $name];

        // Implementations can violate PHPDoc element types at runtime.
        /** @var array<array-key, mixed> $aliases */
        $aliases = $converter->targetAliases;
        if (!array_is_list($aliases)) {
            throw new ConversionConfigurationException('Target aliases must be a list of short names.');
        }
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                throw new ConversionConfigurationException('Invalid image conversion alias.');
            }
            $target = $this->normalizeTargetType($alias);
            if (!preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $target)) {
                throw new ConversionConfigurationException('Invalid image conversion alias.');
            }
            if (in_array($target, ['jpeg', 'png', 'webp', 'avif'], true) && $target !== $name) {
                throw new ConversionConfigurationException('Alias conflicts with a built-in image format.');
            }
            $targets[] = $target;
        }

        // Snapshot the declaration; the same instance cannot change its output format.
        foreach ($this->converters as $registration) {
            if ($registration['codec'] === $converter && $registration['format'] !== $format) {
                throw new ConversionConfigurationException('Target format conflicts with the existing converter registration.');
            }
        }
        foreach ($targets as $target) {
            if (isset($this->converters[$target])
                && ($this->converters[$target]['codec'] !== $converter || $this->converters[$target]['format'] !== $format)) {
                throw new ConversionConfigurationException('A different image converter is already registered for this target.');
            }
        }
        foreach ($targets as $target) {
            $this->converters[$target] = ['codec' => $converter, 'format' => $format];
        }
    }

    /**
     * @throws ConversionExceptionInterface
     * @throws ConversionStreamExceptionInterface
     */
    public function convert(string|StreamInterface $source, string $targetType, ?ImageConversionOptions $options = null): ConvertedImage
    {
        $registration = $this->requireTarget($targetType);
        $source = SourceReader::read($source, $this->limits->maxInputBytes);

        return $this->convertBytes($source, $targetType, $options, $registration);
    }

    /**
     * Convert a caller-authorized local file without modifying it.
     * @throws ConversionExceptionInterface
     * @throws ConversionFileExceptionInterface
     */
    public function convertFile(string $path, string $targetType, ?ImageConversionOptions $options = null): ConvertedImage
    {
        $registration = $this->requireTarget($targetType);
        $source = FileReader::read($path, $this->limits->maxInputBytes);

        return $this->convertBytes($source, $targetType, $options, $registration);
    }

    /** @return array{codec: ImageConverterInterface, format: string} */
    private function requireTarget(string $targetType): array
    {
        return $this->converters[$this->normalizeTargetType($targetType)]
            ?? throw ConversionException::unsupportedTarget($targetType);
    }

    /** @param array{codec: ImageConverterInterface, format: string} $registration */
    private function convertBytes(
        string $source,
        string $targetType,
        ?ImageConversionOptions $options,
        array $registration,
    ): ConvertedImage {
        $converter = $registration['codec'];
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

    /** @throws ConversionStreamPositionRestoreExceptionInterface */
    public function canConvert(string|StreamInterface $source, string $targetType): bool
    {
        $normalizedTargetType = $this->normalizeTargetType($targetType);
        $registration = $this->converters[$normalizedTargetType] ?? null;

        if ($registration === null) {
            return false;
        }

        // A capability probe must not consume a one-shot stream.
        if ($source instanceof StreamInterface) {
            try {
                if (!$source->isSeekable()) {
                    return false;
                }
            } catch (\RuntimeException) {
                return false;
            }
        }
        try {
            $source = SourceReader::read($source, $this->limits->maxInputBytes);
            $sourceMimeType = $this->detector->detectMimeType($source);
        } catch (ConversionStreamPositionRestoreExceptionInterface $error) {
            // A failed restoration is a broken ownership guarantee, not a negative capability.
            throw $error;
        } catch (DetectorException|ConversionExceptionInterface|ConversionStreamExceptionInterface) {
            return false;
        }

        return $sourceMimeType !== null
            && $registration['codec']->supports($sourceMimeType->value);
    }

    private function normalizeTargetType(string $targetType): string
    {
        // Only horizontal whitespace is permitted around a MIME type or short alias.
        $targetType = strtolower(trim($targetType, " \t"));
        $isMime = str_starts_with($targetType, 'image/');
        $name = $isMime ? substr($targetType, 6) : $targetType;
        if (!$isMime && str_starts_with($name, '.') && !str_contains($name, '/')) {
            $name = substr($name, 1);
        }
        $name = match ($name) {
            'jpg', 'pjpeg', 'jpeg' => 'jpeg',
            'x-png', 'png' => 'png',
            default => $name,
        };

        return $isMime ? 'image/' . $name : $name;
    }
}
