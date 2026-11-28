# Upgrading to 2.x

- The package now has its own PSR-4 namespace, `Componenta\Image\Converter`. Update imports and DI configuration: codecs move to `Componenta\Image\Converter\Codec`, interfaces to `Componenta\Image\Converter\Contract`, exceptions (including exception interfaces) to `Componenta\Image\Converter\Exception`, and factories to `Componenta\Image\Converter\Factory`. The orchestrator, result, options, limits and config provider remain directly in the package namespace. No compatibility aliases are exported.

- Require Componenta Config 4 and MIME Type Detector 2. Custom detectors return `?MimeType`, not a string. EXIF is now required.
- Built-in GD codecs reject unsupported source formats, unavailable codecs, declared animation and input limits; constructor defaults are validated immediately.
- PNG options use `compressionLevel: 0..9`, not `quality`. For the old mapping, use `9 - (int) round($oldQuality / 100 * 9)`.
- Replace the third argument to `ImageConverter::convert()` and the second argument to a fixed converter with `new ImageConversionOptions(quality: $quality)`. Custom implementations of `ImageConverterInterface` must accept `?ImageConversionOptions $options = null`. Conflicting target registration now throws instead of overwriting.
- Seekable stream positions are preserved; one-shot streams are consumed by the operation, never by capability probes. Custom codecs receive raw bytes from the orchestrator.
- Limits default to 32 MiB, 16 million pixels and 8192 pixels in either dimension, and 64 MiB encoded output. Configure explicit limits for larger trusted sources.
- JPEG EXIF orientation is applied during re-encoding. Full metadata and ICC profile preservation are not promised.

- Register fixed-format codecs that implement both `ImageConverterInterface` and `ImageConversionTargetProviderInterface`. The previous plural `ImageConversionTargetsProviderInterface` is replaced by the singular interface, with `targetMimeType` and `targetAliases` instead of `conversionTargets`. Move target arguments from `addConverter($codec, ...)` into these declarations and call `addConverter($codec)`. The canonical MIME type and subtype are registered automatically; short aliases no longer imply additional MIME types. Direct codec use still requires only `ImageConverterInterface`.

- Result objects reject empty bytes, non-image or parameterized MIME types, and invalid extensions. MIME and extension metadata are normalized to lowercase.

- Catch operation failures via `ConversionExceptionInterface`, filesystem access via `ConversionFileExceptionInterface`, and stream I/O via `ConversionStreamExceptionInterface`. Stream restoration failures implement `ConversionStreamPositionRestoreExceptionInterface`, retain both causes and no longer implement the conversion exception interface. They are propagated by capability probes. Invalid configuration is a `ConversionConfigurationException` (also an `InvalidArgumentException`). Custom codecs must declare and implement the exception interfaces in their operation contracts.

- For image MIME types mapped in the default MIME catalog, result extensions must match that catalog. Unmapped custom image types can supply their own valid extension.

- AVIF input with non-identity display transformations of the primary color item is rejected instead of silently dropping those transformations during re-encoding. Identity, unused and independent thumbnail properties remain accepted.

- AVIF movie containers require the explicit still-image major brand `avif`; an ambiguous major brand can cause libavif to select a track and silently lose frames. The existing rejection of `avis` remains unchanged.

- Conversion rejects 16-bit PNGs with a tRNS color key to avoid silent loss of distinct opaque pixels during GD's bit-depth reduction. Other 16-bit PNGs remain convertible at GD's reduced precision.

- WebP RIFF framing is checked through the entire declared container, including trailing metadata chunks and padding. Data outside the declared RIFF boundary remains permitted.

- GD uses seven-bit internal alpha precision. PNG's lossless encoding does not guarantee exact preservation of every original eight-bit alpha sample through GD.

- `ImageConverter::$targetMimeTypes` exposes registered canonical target MIME types (unique, ordered, read-only); it does not probe codec availability.

- WebP still-image validation also rejects multiple image bitstreams, duplicate or misplaced extended headers, and animation frame chunks even when the animation flag is clear. Unflagged animation control metadata alone remains accepted.
