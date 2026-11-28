<?php

declare(strict_types=1);

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\Converter\ConvertedImage;
use Componenta\Image\Converter\Exception\ConversionException;
use Componenta\Image\Converter\ImageConversionOptions;
use Componenta\Image\Converter\Contract\ImageConversionTargetProviderInterface;
use Componenta\Image\Converter\ImageConverter;
use Componenta\Image\Converter\Contract\ImageConverterInterface;
use Componenta\Image\Converter\Codec\GdJpegConverter;
use Componenta\Image\Converter\Codec\GdPngConverter;
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


test('registered target MIME types are empty before registration and follow registration order', function (): void {
    $service = registrationService();
    expect($service->targetMimeTypes)->toBe([]);
    $first = new RegistrationCodec('IMAGE/X-SPECIAL', ['special', '.other', '123']);
    $service->addConverter($first);
    expect($service->targetMimeTypes)->toBe(['image/x-special']);
    $service->addConverter(new RegistrationCodec('image/pjpeg', ['jpg']));
    $service->addConverter(new RegistrationCodec('image/x-png'));
    expect($service->targetMimeTypes)->toBe(['image/x-special', 'image/jpeg', 'image/png']);
    $service->addConverter($first);
    expect($service->targetMimeTypes)->toBe(['image/x-special', 'image/jpeg', 'image/png']);
});

test('constructor exposes canonical targets without aliases and does not expose mutable registry state', function (): void {
    $service = registrationService([
        new GdJpegConverter(),
        new GdPngConverter(),
        new RegistrationCodec('image/x-special', ['special']),
    ]);
    $snapshot = $service->targetMimeTypes;
    expect($snapshot)->toBe(['image/jpeg', 'image/png', 'image/x-special']);
    $snapshot[] = 'image/x-other';
    expect($service->targetMimeTypes)->toBe(['image/jpeg', 'image/png', 'image/x-special']);
    expect(function () use ($service): void {
        $service->targetMimeTypes = [];
    })->toThrow(Error::class);
});

test('target listing is unchanged by failed registrations and later provider mutation', function (): void {
    $codec = new RegistrationCodec('image/x-special', ['shared']);
    $service = registrationService([$codec]);
    expect(fn() => $service->addConverter(new RegistrationCodec('image/x-other', ['shared'])))
        ->toThrow(InvalidArgumentException::class);
    $codec->targetMimeType = 'image/x-other';
    expect($service->targetMimeTypes)->toBe(['image/x-special']);
    expect(fn() => $service->addConverter($codec))->toThrow(InvalidArgumentException::class);
    expect($service->targetMimeTypes)->toBe(['image/x-special']);
});

test('target discovery does not call the detector or test native codec availability', function (): void {
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $codec = new class implements ImageConverterInterface, ImageConversionTargetProviderInterface {
        public string $targetMimeType { get => 'image/x-unavailable'; }
        public array $targetAliases { get => ['unavailable']; }

        public function supports(string $mimeType): bool
        {
            throw new LogicException('Availability must not be probed.');
        }

        public function convert(string|StreamInterface $source, ?ImageConversionOptions $options = null): ConvertedImage
        {
            throw new LogicException('Conversion must not be attempted.');
        }
    };
    $service = new ImageConverter($detector, [$codec]);
    expect($service->targetMimeTypes)->toBe(['image/x-unavailable']);
});


test('malformed targets cannot normalize to a registered MIME type or alias', function (string $target): void {
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $service = new ImageConverter($detector, [
        new RegistrationCodec('image/x-special', ['special']),
        new GdJpegConverter(),
    ]);
    expect($service->canConvert('source', $target))->toBeFalse();
    expect(fn() => $service->convert('source', $target))->toThrow(ConversionException::class);
})->with([
    "\0special", "special\0", "special\n", "\rspecial", "\x0bspecial",
    "image/jpeg\r\n", "\0image/jpeg", '.image/jpeg', '..jpg', '...special',
]);

test('malformed short alias declarations cannot be published through normalization', function (string $alias): void {
    $service = registrationService();
    expect(fn() => $service->addConverter(new RegistrationCodec('image/x-special', ['good', $alias])))
        ->toThrow(InvalidArgumentException::class);
    expect($service->targetMimeTypes)->toBe([]);
    expect($service->canConvert('source', 'good'))->toBeFalse();
})->with(["\0special", "special\0", "special\n", "\rspecial", "\x0bspecial", '..special', '.image/x-special']);
