# Componenta Image Converter

[Русский](README.ru.md)

Convert still raster images to a selected format. For same-format recompression, use [Image Compressor](https://github.com/componenta/image-compressor).

## Installation

```sh
composer require componenta/image-converter
```

Requires PHP 8.4 or later within PHP 8.x, the `gd` and `exif` extensions, and the required GD codecs. Dependencies: Componenta Config 4 and MIME Type Detector 2.

Documentation for version 2.x. [Upgrade guide](UPGRADE.md).

## Quick start

```php
use Componenta\Detector\FinfoDetector;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConversionOptions;

$converter = new ImageConverter(new FinfoDetector(), [
    new GdJpegConverter(),
    new GdPngConverter(),
    new GdWebPConverter(),
    new GdAvifConverter(),
]);

$image = $converter->convertFile(
    'photo.png',
    'webp',
    new ImageConversionOptions(quality: 85),
);

file_put_contents('photo.webp', $image->content);
```

`convertFile()` accepts a local file path; `convert()` accepts image bytes or a PSR-7 stream. The orchestrator detects the MIME type from the content and selects a registered codec.

## Supported formats

| Target | Sources | Codec | Default |
| --- | --- | --- | --- |
| JPEG | PNG, WebP, AVIF, BMP | `GdJpegConverter` | `quality: 82` |
| PNG | JPEG, WebP, AVIF, BMP | `GdPngConverter` | `compressionLevel: 6` |
| WebP | JPEG, PNG, AVIF, BMP | `GdWebPConverter` | `quality: 80` |
| AVIF | JPEG, PNG, WebP, BMP | `GdAvifConverter` | `quality: 80` |

Specify the target using its MIME type, short name or registered extension: `image/jpeg`, `jpeg`, `jpg`, `.jpg`. Matching is case-insensitive. Built-in converters do not perform same-format operations.

## Public API

### `ImageConverter`

Public method and property signatures:

```php
public function __construct(
    MimeTypeDetectorInterface $detector,
    iterable $converters = [],
    ImageConversionLimits $limits = new ImageConversionLimits(),
);
public function convert(
    string|StreamInterface $source,
    string $targetType,
    ?ImageConversionOptions $options = null,
): ConvertedImage;
public function convertFile(
    string $path,
    string $targetType,
    ?ImageConversionOptions $options = null,
): ConvertedImage;
public function canConvert(string|StreamInterface $source, string $targetType): bool;
public function addConverter(ImageConverterInterface&ImageConversionTargetProviderInterface $converter): void;

/** @var list<string> */
public array $targetMimeTypes { get; }
```

`targetMimeTypes` returns unique registered canonical MIME types in registration order. The read-only property excludes aliases and reflects later registrations; an empty registry returns `[]`.

`canConvert()` checks routing and the input byte limit, not full image validity or successful processing. A registered target in `targetMimeTypes` does not guarantee native encoder availability.

`convertFile()` reads a local regular file within `maxInputBytes`, closes it and returns `ConvertedImage` without modifying the source. URLs, stream wrappers and special files are unsupported; symlinks to regular files are allowed. The caller is responsible for path authorization.

### Direct codec use

Use a codec directly when the format is known:

```php
$source = file_get_contents('photo.png');
if ($source === false) {
    throw new \RuntimeException('Unable to read image.');
}

$codec = new GdWebPConverter(defaultQuality: 85);
if ($codec->supports('image/png')) {
    $image = $codec->convert($source);
}
```

All built-in codecs implement `ImageConverterInterface`. `supports(string $mimeType): bool` checks source MIME support and required GD codec availability. Set constructor defaults with `defaultQuality` for JPEG, WebP and AVIF or `defaultCompressionLevel` for PNG. All codecs accept `limits`.

### `ImageConversionOptions`

| Parameter | Formats | Value |
| --- | --- | --- |
| `quality` | JPEG, WebP, AVIF | `int`, 0–100. Encoding quality. |
| `compressionLevel` | PNG | `int`, 0–9. Compression effort, not image quality. |
| `avifSpeed` | AVIF | `int`, 0–10. Lower values favor compression over speed. |

Numeric options default to `null`, which uses the codec's defaults; GD selects the default AVIF speed. Unsupported settings and out-of-range values are rejected.

### `ConvertedImage`

| Property | Value |
| --- | --- |
| `content: string` | Result bytes. |
| `mimeType: string` | MIME type, e.g. `image/webp`. |
| `extension: string` | Extension without a leading dot, e.g. `webp`. |
| `size: int` | Size in bytes. |

Create a result with `new ConvertedImage($content, $mimeType, $extension)`. All properties are read-only. Content must be nonempty; the MIME type must be a concrete image type without parameters; the extension must be valid and match known types. Unknown types may use a valid custom extension. MIME type and extension are normalized to lowercase. Metadata validation does not validate bytes or authorize a storage path.

## Streams and resource limits

Seekable streams are read from the beginning and their original position is restored on success or failure. Non-seekable streams are consumed once from the current position; `canConvert()` returns `false` without reading them.

Immutable `ImageConversionLimits` defaults:

| Parameter | Default |
| --- | ---: |
| `maxInputBytes` | 33,554,432 (32 MiB) |
| `maxOutputBytes` | 67,108,864 (64 MiB) |
| `maxPixels` | 16,000,000 |
| `maxWidth` | 8,192 |
| `maxHeight` | 8,192 |

All limits must be positive. The orchestrator enforces input and output byte limits; codecs enforce geometry and their own byte limits. Pass the same limits object to both when constructing manually:

```php
$limits = new ImageConversionLimits(maxInputBytes: 8 * 1024 * 1024);
$converter = new ImageConverter(
    new FinfoDetector(),
    [new GdWebPConverter(limits: $limits)],
    limits: $limits,
);
```

Limits are not absolute bounds on native memory or processing time. For untrusted uploads, use input validation, process isolation and resource limits.

## Errors

- `ConversionException` — read failures, unsupported formats or options, invalid images, exceeded limits and processing failures.
- `ConversionStreamPositionRestoreException` — stream position could not be restored; a subtype of the above, also possible from `canConvert()`. The original read failure is available in `readFailure` and the restoration error through `getPrevious()`.
- `InvalidArgumentException` — invalid resource limits or invalid/conflicting target registration.

## Image processing behavior

- Built-in codecs process still images only. GIF, APNG, animated WebP and AVIF sequences are unsupported. Structural checks are not complete image validation.
- JPEG EXIF orientation is applied during re-encoding. When converting to JPEG, transparency is composited onto white.
- Metadata, ICC profiles, HDR and original bit depth are not guaranteed to be preserved. Trailing data beyond the JPEG end marker or WebP container is discarded during re-encoding.
- GD uses seven-bit internal alpha precision; eight-bit PNG alpha samples may change by one. Low AVIF quality can also affect transparency precision.
- 16-bit PNGs with a transparency color key (tRNS) are rejected; other 16-bit PNGs are converted with reduced precision. Alpha preservation for 32-bit BMP is not guaranteed.
- AVIF with non-identity primary-image display transforms (rotation, reflection, cropping or non-square pixels) is rejected. AVIF with movie tracks requires the `avif` major brand.

**AVIF in long-lived workers.** Some libgd builds have a [native memory leak during AVIF encoding](https://github.com/libgd/libgd/pull/832). Use a patched build; until then, omit AVIF encoding or run it in isolated, resource-limited processes with a bounded lifetime.

## Custom codecs

Implement `ImageConverterInterface` for direct use. A codec registered with the orchestrator must additionally implement `ImageConversionTargetProviderInterface`:

```php
interface ImageConverterInterface
{
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage;

    public function supports(string $mimeType): bool;
}

interface ImageConversionTargetProviderInterface
{
    public string $targetMimeType { get; }

    /** @var list<string> */
    public array $targetAliases { get; }
}
```

Declare one fixed concrete target MIME type without parameters. Its subtype is registered automatically. For example, `targetMimeType = 'image/x-special'` and `targetAliases = ['special']` accept `image/x-special`, `x-special` and `special`, not `image/special`. Aliases cannot claim names reserved for other built-in formats. Conflicting registrations are rejected atomically; re-registering the same instance cannot change its target MIME type. The result must match the declared format.

When called through the orchestrator, a custom codec receives raw bytes rather than the original stream. It is responsible for valid output, option and geometry limits, and safe decoding.

## Container integration

The `Componenta\Image\ConfigProvider` config provider registers `ImageConverter` and the four built-in codecs. After loading the configuration, resolve the service from a PSR-11 container:

```php
$converter = $container->get(ImageConverter::class);
```

Register `ImageConversionLimits::class` to configure default factory limits, and `MimeTypeDetectorInterface::class` to replace the default `FinfoDetector`. Overridden codecs retain their own settings. The default configuration aliases `ImageConverterInterface` to `GdWebPConverter`, not the orchestrator.

## License

[MIT](LICENSE).
