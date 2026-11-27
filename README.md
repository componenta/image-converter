# Componenta Image Converter

Convert still images to a selected output format. [Русская документация](README.ru.md).

## Installation

```sh
composer require componenta/image-converter
```

Requires PHP 8.4+, GD, EXIF, Componenta Config 4 and MIME Type Detector 2.

## Usage

The DI provider registers the `ImageConverter` orchestrator and the four built-in GD codecs. Obtain the orchestrator from the container, or construct it with a `MimeTypeDetectorInterface` and an iterable of codecs.

```php
use Componenta\Image\ImageConversionOptions;

$image = $converter->convert($source, 'webp', new ImageConversionOptions(quality: 85));
$png = $converter->convert($jpegSource, 'png', new ImageConversionOptions(compressionLevel: 7));
```

The source is raw bytes (not a filesystem path) or a PSR-7 `StreamInterface`. The result is `ConvertedImage` with read-only `content`, `mimeType`, `extension` and computed `size` in bytes. Results require nonempty content, a concrete image MIME type without parameters, and a valid extension without a leading dot. MIME and extension metadata are normalized to lowercase. For image MIME types with registered extensions, the extension must agree with the MIME Type Detector default catalog. Unmapped custom image types may declare their own valid extension. These checks do not validate the encoded bytes or authorize a filesystem path; applications still need a storage and serving policy.

## Formats and options

| Target | Source formats |
| --- | --- |
| JPEG | PNG, WebP, AVIF, BMP |
| PNG | JPEG, WebP, AVIF, BMP |
| WebP | JPEG, PNG, AVIF, BMP |
| AVIF | JPEG, PNG, WebP, BMP |

Same-format operations belong to the image-compressor package.

Actual codec availability depends on the GD build. `supports()` reports the supported source MIME types and available native codecs. `canConvert($source, 'webp')` checks routing and input-byte limits, not complete image validity.

- `quality`: JPEG, WebP and AVIF, integer 0–100.
- `compressionLevel`: PNG, integer 0–9; changes compression effort, not visual quality.
- `avifSpeed`: AVIF, integer 0–10; lower values favor compression.

Omitted settings use the codec defaults: JPEG 82, WebP and AVIF 80, PNG level 6, AVIF speed selected by GD. Unsupported options are rejected.

## Input ownership and resource limits

A seekable stream is read from its beginning and its position is restored on success or failure. If restoration itself fails, the operation and capability probe throw `ConversionStreamPositionRestoreException` (a subtype of `ConversionException`); the stream's position is no longer guaranteed. The exception retains the restoration error as its cause and any original read failure in `readFailure`. A non-seekable stream is consumed once from its current position; capability probes return false without consuming it. The orchestrator sends the same bounded byte snapshot to detection and processing, including custom codecs.

Default `ImageConversionLimits` values: 32 MiB input, 16,000,000 pixels, width and height up to 8192, and 64 MiB encoded output. Register a limits object in the DI container to configure the orchestrator and its built-in codecs together. For manual construction, pass the same limits object to the orchestrator and each codec: the orchestrator enforces byte limits, and the codec enforces dimensions and its own byte limits. An independently constructed or overridden codec retains its own configuration. Built-in codecs check input bytes and declared dimensions before decoding and cap retained encoded output. The orchestrator also rejects oversized results from custom codecs; custom codecs must enforce their own decoding and allocation limits.

These limits are not an absolute native-memory or CPU bound. Process isolation, upload validation and resource/time restrictions remain necessary for untrusted inputs.

## Image fidelity and errors

Built-in codecs process still images. GIF, declared APNG, animated WebP and AVIF sequences declaring the `avis` brand are unsupported. JPEG EXIF orientation is applied before re-encoding; JPEG output has a white background where transparency must be removed. PNG palette data is not unnecessarily converted to true color. GD does not provide full metadata, ICC profile, HDR or high-bit-depth preservation. AVIF quality also controls alpha quantization in GD; at low quality transparency values may be approximate. The API does not expose independent lossless alpha for AVIF; PNG compression rejects 16-bit PNG. The output header is checked for format and dimensions, not fully re-decoded after every operation.

Operational failures throw `ConversionException`. Invalid registration or limit configuration throws `InvalidArgumentException`. Unexpected programming errors are not silently suppressed.

## Extension and integration

Implement `ImageConverterInterface` for a custom codec. Implement `ImageConversionTargetsProviderInterface` for automatic target aliases, or pass aliases explicitly to `addConverter()`. Registration of a different converter for an existing normalized alias is rejected atomically. Built-in targets accept names, extensions and image MIME types (e.g. `jpg`, `jpeg`, `image/jpeg`). All registered aliases are associated with one canonical output format. For multiple custom aliases, include the canonical MIME type (for example `special`, `image/x-special`); a single undeclared short alias implies `image/<alias>`. The declared or already registered format of a codec also applies to new explicit aliases. A fixed codec cannot be registered for a different canonical format later. The result MIME type must agree with the operation; custom codecs are responsible for valid bytes.

The package declares `Componenta\Image\ConfigProvider` in Composer metadata for provider discovery. The factories use a registered MIME detector or create `FinfoDetector`. For fixed WebP conversion the provider aliases `ImageConverterInterface` to `GdWebPConverter`.

## Development

```sh
composer test
composer analyse
composer bench
```

PHPBench scenarios require a GD build with all built-in codecs. They use deterministic synthetic images at 1, 4 and 12 MP (subject to input limits), and compare raw bytes with streams. See [UPGRADE.md](UPGRADE.md) for migration.

The `Compare performance` workflow compares v1.0.1 and the candidate on the same PHP 8.4 runner with equivalent codec settings, three isolated iterations per common case. It excludes conversions not supported by v1; stream measurements use 1 MP inputs. PHPBench reports PHP-managed peak memory, not total native RSS.
