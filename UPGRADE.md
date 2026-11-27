# Upgrading to 2.x

- Require Componenta Config 4 and MIME Type Detector 2. Custom detectors return `?MimeType`, not a string. EXIF is now required.
- Built-in GD codecs reject unsupported source formats, unavailable codecs, declared animation and input limits; constructor defaults are validated immediately.
- PNG options use `compressionLevel: 0..9`, not `quality`. For the old mapping, use `9 - (int) round($oldQuality / 100 * 9)`.
- Replace the third argument to `ImageConverter::convert()` and the second argument to a fixed converter with `new ImageConversionOptions(quality: $quality)`. Custom implementations of `ImageConverterInterface` must accept `?ImageConversionOptions $options = null`. Conflicting target registration now throws instead of overwriting.
- Seekable stream positions are preserved; one-shot streams are consumed by the operation, never by capability probes. Custom codecs receive raw bytes from the orchestrator.
- Limits default to 32 MiB, 16 million pixels and 8192 pixels in either dimension, and 64 MiB encoded output. Configure explicit limits for larger trusted sources.
- JPEG EXIF orientation is applied during re-encoding. Full metadata and ICC profile preservation are not promised.

- Each converter registration identifies a single output format; multiple custom aliases require its canonical image MIME type. A single custom short alias without a declaration implies `image/<alias>`.

- Result objects reject empty bytes, non-image or parameterized MIME types, and invalid extensions. MIME and extension metadata are normalized to lowercase.

- A failed seekable stream restoration now throws `ConversionStreamPositionRestoreException` from both operations and capability probes. It preserves both failure causes instead of silently returning false.

- For image MIME types mapped in the default MIME catalog, result extensions must match that catalog. Unmapped custom image types can supply their own valid extension.

- AVIF input with non-identity display transformations of the primary color item is rejected instead of silently dropping those transformations during re-encoding. Identity, unused and independent thumbnail properties remain accepted.

- AVIF movie containers require the explicit still-image major brand `avif`; an ambiguous major brand can cause libavif to select a track and silently lose frames. The existing rejection of `avis` remains unchanged.
