# Componenta Image Converter

Image conversion contracts and GD-backed image converters for Componenta. The package keeps low-level GD calls behind small contracts and provides an `ImageConverter` orchestrator that selects a target converter by requested output type and validates the source MIME type through `componenta/mimetype-detector`.

## Requirements

- PHP 8.4+
- GD extension
- `componenta/mimetype-detector` for source MIME detection

## Installation

```bash
composer require componenta/image-converter
```

The package declares `Componenta\Image\ConfigProvider` in `extra.componenta.config-providers`.
When `componenta/composer-plugin` is installed, the provider is added to the generated provider list automatically.

## Main API

`ImageConverterInterface` is the low-level contract implemented by single-target converters:

```php
use Componenta\Image\ImageConverterInterface;
use Psr\Http\Message\StreamInterface;

interface ImageConverterInterface
{
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage;

    public function supports(string $mimeType): bool;
}
```

`ImageConverter` is the orchestrator for application code that needs to choose the target format at runtime:

```php
use Componenta\Image\ImageConverter;

if ($converter->canConvert($source, 'webp')) {
    $image = $converter->convert($source, 'webp', quality: 82);
}

$image->content;   // binary converted bytes
$image->mimeType;  // image/webp
$image->extension; // webp
$image->size;      // byte length
```

`$source` may be a binary string or `Psr\Http\Message\StreamInterface`. Target types accept short names and MIME types, for example `webp`, `image/webp`, `jpg`, `jpeg`, `image/jpeg`, `png`, and `image/avif`.

## Built-In Converters

| Converter | Target | Supported source MIME types |
|---|---|---|
| `GdWebPConverter` | `webp`, `image/webp` | JPEG, PNG, BMP. GIF is intentionally not supported because GD would drop animation. |
| `GdJpegConverter` | `jpeg`, `jpg`, `image/jpeg` | PNG, WebP, AVIF, BMP. Alpha is flattened onto a white background. |
| `GdPngConverter` | `png`, `image/png` | JPEG, WebP, AVIF, BMP. Alpha is preserved when available. |
| `GdAvifConverter` | `avif`, `image/avif` | JPEG, PNG, WebP, BMP when the local GD build supports `imageavif()`. |

For JPEG, WebP, and AVIF, quality is `0..100`. For PNG, the same quality range is mapped to GD compression level `0..9`.

## Extension Points

Implement `ImageConverterInterface` for a new backend or target format. If the converter also implements `ImageConversionTargetsProviderInterface`, the orchestrator can read its `conversionTargets` property automatically:

```php
use Componenta\Image\ImageConversionTargetsProviderInterface;
use Componenta\Image\ImageConverterInterface;

final class ImagickWebPConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    public array $conversionTargets {
        get => ['webp', 'image/webp'];
    }

    // convert() and supports()
}
```

Converters can be added after construction:

```php
$converter->addConverter(new ImagickWebPConverter());
$converter->addConverter(new CustomFormatConverter(), 'custom', 'image/x-custom');
```

When target types are passed explicitly to `addConverter()`, they override the optional provider property for registration.

## Failure Model

`ImageConverter` throws `ConversionException` when:

- no converter is registered for the requested target type;
- the source MIME type cannot be detected;
- the target converter does not support the detected source MIME type;
- GD cannot decode or encode the image;
- quality is outside `0..100`.

Treat conversion as an infrastructure boundary: validate upload constraints before conversion and handle conversion failure as a user-facing invalid media error.

## DI Registration

`ConfigProvider` registers:

- `ImageConverter` through `ImageConverterFactory`;
- `GdWebPConverter`, `GdJpegConverter`, `GdPngConverter`, and `GdAvifConverter`;
- `ImageConverterInterface` as an alias to `GdWebPConverter` for code that wants a fixed WebP converter.

`ImageConverterFactory` uses `MimeTypeDetectorInterface` from the container when available and falls back to `FinfoDetector`.
