<?php

declare(strict_types=1);

namespace Componenta\Image\Converter;

use Componenta\Image\Converter\Exception\ConversionException;

use Componenta\Detector\Ext;
use Componenta\Detector\MimeType;
use Componenta\Detector\MimeMap;

/** Image bytes with validated, normalized format metadata. */
final class ConvertedImage
{
    public readonly string $mimeType;
    public readonly string $extension;

    /** @throws ConversionException */
    public function __construct(
        public readonly string $content,
        string $mimeType,
        string $extension,
    ) {
        $mime = MimeType::tryCreate($mimeType);
        $ext = Ext::tryCreate($extension);
        if ($content === '' || $mime === null || !$mime->isImage()
            || $mime->parameters !== [] || $ext === null || str_starts_with($extension, '.')) {
            throw new ConversionException('Invalid image result metadata or empty content.');
        }
        $extensions = MimeMap::getDefaultMap()[$mime->value] ?? [];
        if ($extensions !== [] && !in_array($ext->value, $extensions, true)) {
            throw new ConversionException('Image result extension does not match its MIME type.');
        }
        $this->mimeType = $mime->value;
        $this->extension = $ext->value;
    }

    public int $size {
        get => strlen($this->content);
    }
}
