<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConvertedImage;
use Componenta\Image\ConversionException;
use Componenta\Image\ImageConversionTargetsProviderInterface;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class ImageConverterTest extends TestCase
{
    public function testConvertsUsingConverterRegisteredThroughConversionTargets(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector('image/png'),
            converters: [$targetConverter = new TargetAwareConverter()],
        );

        $image = $converter->convert('source-bytes', 'image/webp', 91);

        self::assertSame('converted-webp', $image->content);
        self::assertSame('image/webp', $image->mimeType);
        self::assertSame('webp', $image->extension);
        self::assertSame('source-bytes', $targetConverter->source);
        self::assertSame(91, $targetConverter->quality);
    }

    public function testCanAddConverterWithExplicitTargetTypes(): void
    {
        $converter = new ImageConverter(new FixedMimeTypeDetector('image/png'));
        $converter->addConverter(new ExplicitTargetConverter(), 'custom');

        $image = $converter->convert('source-bytes', 'custom');

        self::assertSame('converted-custom', $image->content);
    }

    public function testCanCheckWhetherSourceCanBeConvertedToTarget(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector('image/png'),
            converters: [new TargetAwareConverter()],
        );

        self::assertTrue($converter->canConvert('source-bytes', 'webp'));
        self::assertTrue($converter->canConvert('source-bytes', 'image/webp'));
        self::assertFalse($converter->canConvert('source-bytes', 'jpeg'));
    }

    public function testCannotConvertWhenSourceMimeTypeIsNotSupported(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector('image/gif'),
            converters: [new TargetAwareConverter()],
        );

        self::assertFalse($converter->canConvert('source-bytes', 'webp'));
    }

    public function testCannotConvertWhenSourceMimeTypeCannotBeDetected(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector(null),
            converters: [new TargetAwareConverter()],
        );

        self::assertFalse($converter->canConvert('source-bytes', 'webp'));
    }

    public function testThrowsWhenNoConverterIsRegisteredForTargetType(): void
    {
        $converter = new ImageConverter(new FixedMimeTypeDetector('image/png'));

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No image converter is registered for target type "webp".');

        $converter->convert('source-bytes', 'webp');
    }

    public function testThrowsWhenSourceMimeTypeCannotBeDetected(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector(null),
            converters: [new TargetAwareConverter()],
        );

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Unable to detect source image MIME type.');

        $converter->convert('source-bytes', 'webp');
    }

    public function testThrowsWhenConverterDoesNotSupportSourceMimeType(): void
    {
        $converter = new ImageConverter(
            detector: new FixedMimeTypeDetector('image/gif'),
            converters: [new TargetAwareConverter()],
        );

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage(
            'Image converter for target type "webp" does not support source MIME type "image/gif".',
        );

        $converter->convert('source-bytes', 'webp');
    }

    public function testRequiresExplicitTargetTypesWhenConverterDoesNotExposeConversionTargets(): void
    {
        $converter = new ImageConverter(new FixedMimeTypeDetector('image/png'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Target types must be provided for image converters that do not expose conversion targets.',
        );

        $converter->addConverter(new ExplicitTargetConverter());
    }
}

final readonly class FixedMimeTypeDetector implements MimeTypeDetectorInterface
{
    public function __construct(
        private ?string $mimeType,
    ) {}

    #[\Override]
    public function detectMimeType(string|StreamInterface $content, bool $asObject = false): string|MimeType|null
    {
        return $this->mimeType;
    }
}

final class TargetAwareConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    public string|StreamInterface|null $source = null;
    public ?int $quality = null;

    /**
     * @var list<string>
     */
    public array $conversionTargets {
        get => ['webp', 'image/webp'];
    }

    #[\Override]
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage
    {
        $this->source = $source;
        $this->quality = $quality;

        return new ConvertedImage('converted-webp', 'image/webp', 'webp');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'image/png';
    }
}

final readonly class ExplicitTargetConverter implements ImageConverterInterface
{
    #[\Override]
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage
    {
        return new ConvertedImage('converted-custom', 'image/custom', 'custom');
    }

    #[\Override]
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'image/png';
    }
}
