<?php

declare(strict_types=1);

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConvertedImage;
use Componenta\Image\ConversionException;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\ImageConversionTargetProviderInterface;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Psr\Http\Message\StreamInterface;

/** A fixed-format test double; mutable declarations exercise defensive registration. */
final class RegistrationCodec implements ImageConverterInterface, ImageConversionTargetProviderInterface
{
    public function __construct(
        public string $targetMimeType,
        public array $targetAliases = [],
        private readonly ?ConvertedImage $result = null,
    ) {}

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'image/png';
    }

    public function convert(string|StreamInterface $source, ?ImageConversionOptions $options = null): ConvertedImage
    {
        return $this->result ?? new ConvertedImage('bytes', 'image/x-special', 'special');
    }
}

function registrationService(iterable $codecs = []): ImageConverter
{
    $detector = new class implements MimeTypeDetectorInterface {
        public function detectMimeType(string|StreamInterface $content): ?MimeType
        {
            return new MimeType('image/png');
        }
    };

    return new ImageConverter($detector, $codecs);
}

test('canonical MIME, subtype and short aliases route to the same fixed codec', function (): void {
    $codec = new RegistrationCodec('IMAGE/X-SPECIAL', ['special', '.other', 'SPECIAL', '123']);
    $service = registrationService([$codec]);
    foreach (['image/x-special', 'x-special', 'special', '.SPECIAL', 'other', '.other', '123'] as $target) {
        expect($service->canConvert('source', $target))->toBeTrue();
        expect($service->convert('source', $target)->mimeType)->toBe('image/x-special');
    }
    expect($service->canConvert('source', 'image/special'))->toBeFalse();
    expect($service->canConvert('source', 'image/other'))->toBeFalse();
    expect(fn() => $service->convert('source', 'image/special'))->toThrow(ConversionException::class);
});

test('built-in MIME and extension aliases are preserved', function (): void {
    $service = registrationService([new GdJpegConverter()]);
    foreach (['jpeg', 'jpg', '.jpg', 'pjpeg', 'image/jpeg', 'image/jpg', 'image/pjpeg', ' IMAGE/JPEG '] as $target) {
        expect($service->canConvert('source', $target))->toBeTrue();
    }
    $png = registrationService([new RegistrationCodec('image/png', result: new ConvertedImage('bytes', 'image/x-png', 'png'))]);
    foreach (['png', '.png', 'x-png', 'image/png', 'image/x-png'] as $target) {
        expect($png->convert('source', $target)->mimeType)->toBe('image/x-png');
    }
    expect((new GdPngConverter())->targetMimeType)->toBe('image/png');
});

test('a codec needs no additional aliases to register its canonical MIME and subtype', function (): void {
    $service = registrationService([new RegistrationCodec('image/x-special')]);
    expect($service->canConvert('source', 'image/x-special'))->toBeTrue();
    expect($service->canConvert('source', 'x-special'))->toBeTrue();
    expect($service->canConvert('source', 'special'))->toBeFalse();
});

test('registration rejects conflicts without publishing any of the new keys', function (): void {
    $first = new RegistrationCodec('image/x-special', ['shared']);
    $service = registrationService([$first]);
    $second = new RegistrationCodec('image/x-other', ['new-alias', 'shared']);
    expect(fn() => $service->addConverter($second))->toThrow(InvalidArgumentException::class);
    foreach (['image/x-other', 'x-other', 'new-alias'] as $target) {
        expect($service->canConvert('source', $target))->toBeFalse();
    }
    expect($service->canConvert('source', 'shared'))->toBeTrue();
    expect(fn() => $service->addConverter(new RegistrationCodec('image/x-special')))->toThrow(InvalidArgumentException::class);
});

test('invalid canonical declarations are rejected atomically', function (string $mime): void {
    $service = registrationService();
    expect(fn() => $service->addConverter(new RegistrationCodec($mime, ['valid-alias'])))->toThrow(InvalidArgumentException::class);
    expect($service->canConvert('source', 'valid-alias'))->toBeFalse();
})->with(['', 'image/', 'image/*', 'webp', 'text/plain', 'image/png; charset=utf-8']);

test('invalid aliases cannot publish the canonical format or preceding valid aliases', function (array $aliases): void {
    $service = registrationService();
    expect(fn() => $service->addConverter(new RegistrationCodec('image/x-special', $aliases)))->toThrow(InvalidArgumentException::class);
    expect($service->canConvert('source', 'image/x-special'))->toBeFalse();
    expect($service->canConvert('source', 'valid-alias'))->toBeFalse();
})->with([
    [['valid-alias', '']],
    [['valid-alias', 42]],
    [['valid-alias', 'image/x-other']],
    [['valid-alias', 'jpeg']],
    [['valid-alias', '.JPG']],
    [['valid-alias', 'x-png']],
    [['valid-alias', 'bad/alias']],
    [['key' => 'valid-alias']],
]);

test('repeat registration is idempotent and may not change the canonical target', function (): void {
    $codec = new RegistrationCodec('image/x-special', ['special']);
    $service = registrationService([$codec]);
    $service->addConverter($codec);
    $codec->targetMimeType = 'image/x-other';
    expect(fn() => $service->addConverter($codec))->toThrow(InvalidArgumentException::class);
    expect($service->canConvert('source', 'image/x-other'))->toBeFalse();
    expect($service->convert('source', 'special')->mimeType)->toBe('image/x-special');
});

test('all routing keys enforce the canonical result MIME type rather than a short alias', function (): void {
    $codec = new RegistrationCodec('image/x-special', ['special'], new ConvertedImage('bytes', 'image/special', 'special'));
    $service = registrationService([$codec]);
    foreach (['special', 'image/x-special', 'x-special'] as $target) {
        expect(fn() => $service->convert('source', $target))->toThrow(ConversionException::class, 'different image format');
    }
});

test('constructor and addConverter require both interfaces but direct codec use does not', function (): void {
    $codec = new class implements ImageConverterInterface {
        public function supports(string $mimeType): bool { return true; }

        public function convert(string|StreamInterface $source, ?ImageConversionOptions $options = null): ConvertedImage
        {
            return new ConvertedImage('bytes', 'image/x-special', 'special');
        }
    };
    expect($codec->convert('source')->content)->toBe('bytes');
    $service = registrationService();
    expect(fn() => $service->addConverter($codec))->toThrow(TypeError::class);
    expect(fn() => registrationService([$codec]))->toThrow(TypeError::class);
    expect((new ReflectionMethod(ImageConverter::class, 'addConverter'))->getNumberOfParameters())->toBe(1);
});
