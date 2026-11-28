<?php

declare(strict_types=1);

use Componenta\Detector\FinfoDetector;
use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConversionException;
use Componenta\Image\ConvertedImage;
use Componenta\Image\Converter\Internal\EncodedOutput;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\ImageConversionLimits;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\ImageConversionTargetProviderInterface;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConverterInterface;
use Psr\Http\Message\StreamInterface;

function converterFileFixture(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'image-convert-');
    if ($path === false) {
        throw new RuntimeException('Unable to create temporary fixture.');
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        unlink($path);
        throw new RuntimeException('Unable to write temporary fixture.');
    }

    return $path;
}

test('file conversion uses the same format detection, options and bytes as ordinary conversion', function (): void {
    $image = imagecreatetruecolor(17, 13);
    imagefilledrectangle($image, 0, 0, 7, 12, imagecolorallocate($image, 80, 120, 200));
    $source = EncodedOutput::capture(static fn(): bool => imagepng($image));
    $path = converterFileFixture($source);
    try {
        $limits = new ImageConversionLimits(maxInputBytes: strlen($source));
        $service = new ImageConverter(new FinfoDetector(), [new GdJpegConverter(limits: $limits)], $limits);
        $options = new ImageConversionOptions(quality: 92);
        $expected = $service->convert($source, 'image/jpeg', $options);
        $actual = $service->convertFile($path, '.jpg', $options);
        expect($actual->content)->toBe($expected->content);
        expect($actual->mimeType)->toBe('image/jpeg');
        expect($actual->extension)->toBe('jpg');
        expect(file_get_contents($path))->toBe($source);
        $decoded = imagecreatefromstring($actual->content);
        expect($decoded)->toBeInstanceOf(GdImage::class);
        expect([imagesx($decoded), imagesy($decoded)])->toBe([17, 13]);
    } finally {
        unlink($path);
    }
});

test('file conversion passes one exact bounded byte snapshot to detection and custom conversion', function (int $size): void {
    $bytes = str_repeat('x', $size - 1) . "\0";
    $path = converterFileFixture($bytes);
    try {
        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects($this->once())->method('detectMimeType')->with($bytes)->willReturn(new MimeType('image/png'));
        $options = new ImageConversionOptions(quality: 83);
        $codec = new class($bytes, $options) implements ImageConverterInterface, ImageConversionTargetProviderInterface {
            public string $targetMimeType { get => 'image/webp'; }
            public array $targetAliases { get => []; }

            public function __construct(private readonly string $expected, private readonly ImageConversionOptions $options) {}

            public function supports(string $mimeType): bool { return $mimeType === 'image/png'; }

            public function convert(string|StreamInterface $source, ?ImageConversionOptions $options = null): ConvertedImage
            {
                if ($source !== $this->expected || $options !== $this->options) {
                    throw new LogicException('File conversion changed the source or options.');
                }

                return new ConvertedImage('result', 'image/webp', 'webp');
            }
        };
        $service = new ImageConverter($detector, [$codec], new ImageConversionLimits(maxInputBytes: $size));
        expect($service->convertFile($path, 'webp', $options)->content)->toBe('result');
    } finally {
        unlink($path);
    }
})->with([1, 65535, 65536, 65537, 131072]);

test('file input limits are enforced before detection', function (): void {
    $path = converterFileFixture(str_repeat('x', 65537));
    try {
        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects($this->never())->method('detectMimeType');
        $service = new ImageConverter($detector, [new GdJpegConverter()], new ImageConversionLimits(maxInputBytes: 65536));
        expect(fn() => $service->convertFile($path, 'jpeg'))->toThrow(ConversionException::class, 'input byte limit');
        expect(filesize($path))->toBe(65537);
    } finally {
        unlink($path);
    }
});

test('file conversion rejects wrappers, malformed paths and non-files before detection', function (string $kind): void {
    $fixture = converterFileFixture('bytes');
    try {
        $path = match ($kind) {
            'empty' => '',
            'null byte' => $fixture . "\0",
            'directory' => dirname($fixture),
            'missing' => $fixture . '.missing',
            'file wrapper' => 'file://' . $fixture,
            'php wrapper' => 'php://memory',
            'data wrapper' => 'data:text/plain,bytes',
            'data wrapper uppercase' => 'DATA://text/plain,bytes',
            'remote' => 'https://example.invalid/image.png',
        };
        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects($this->never())->method('detectMimeType');
        $service = new ImageConverter($detector, [new GdJpegConverter()]);
        $handler = static function (): never {
            throw new LogicException('File errors must not escape to the application error handler.');
        };
        set_error_handler($handler);
        try {
            expect(fn() => $service->convertFile($path, 'jpeg'))->toThrow(ConversionException::class);
            $installed = set_error_handler(static fn(): bool => true);
            restore_error_handler();
            expect($installed)->toBe($handler);
        } finally {
            restore_error_handler();
        }
    } finally {
        unlink($fixture);
    }
})->with(['empty', 'null byte', 'directory', 'missing', 'file wrapper', 'php wrapper', 'data wrapper', 'data wrapper uppercase', 'remote']);

test('unknown targets fail before opening a file', function (): void {
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $service = new ImageConverter($detector, [new GdJpegConverter()]);
    expect(fn() => $service->convertFile('', 'unregistered'))->toThrow(ConversionException::class, 'No image converter is registered');
});

test('file conversion rejects a FIFO without blocking', function (): void {
    if (!function_exists('posix_mkfifo')) {
        $this->markTestSkipped('POSIX FIFOs are unavailable.');
    }
    $path = converterFileFixture('');
    unlink($path);
    if (!posix_mkfifo($path, 0600)) {
        $this->markTestSkipped('Unable to create FIFO.');
    }
    try {
        $detector = $this->createMock(MimeTypeDetectorInterface::class);
        $detector->expects($this->never())->method('detectMimeType');
        $service = new ImageConverter($detector, [new GdJpegConverter()]);
        expect(fn() => $service->convertFile($path, 'jpeg'))->toThrow(ConversionException::class, 'regular file');
    } finally {
        unlink($path);
    }
});

test('file conversion accepts a symlink to a regular image and leaves the source unchanged', function (): void {
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
        $this->markTestSkipped('Symlinks are unavailable.');
    }
    $image = imagecreatetruecolor(3, 2);
    $bytes = EncodedOutput::capture(static fn(): bool => imagepng($image));
    $path = converterFileFixture($bytes);
    $link = $path . '.link';
    try {
        if (!symlink($path, $link)) {
            $this->markTestSkipped('Unable to create symbolic link.');
        }
        $service = new ImageConverter(new FinfoDetector(), [new GdJpegConverter()]);
        expect($service->convertFile($link, 'jpeg')->content)->toBe($service->convert($bytes, 'jpeg')->content);
        expect(file_get_contents($path))->toBe($bytes);
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        unlink($path);
    }
});

test('file conversion enforces codec geometry and output limits', function (): void {
    $image = imagecreatetruecolor(3, 2);
    $path = converterFileFixture(EncodedOutput::capture(static fn(): bool => imagepng($image)));
    try {
        foreach ([new ImageConversionLimits(maxPixels: 5), new ImageConversionLimits(maxOutputBytes: 1)] as $limits) {
            $service = new ImageConverter(new FinfoDetector(), [new GdJpegConverter(limits: $limits)], $limits);
            expect(fn() => $service->convertFile($path, 'jpeg'))->toThrow(ConversionException::class);
        }
    } finally {
        unlink($path);
    }
});
