<?php

declare(strict_types=1);

namespace Componenta\Image;

use Componenta\Detector\Ext;
use Componenta\Detector\MimeType;

/** Image bytes with validated, normalized format metadata. */
final class ConvertedImage
{
    public readonly string $mimeType;
    public readonly string $extension;

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
        $this->mimeType = $mime->value;
        $this->extension = $ext->value;
    }

    public int $size {
        get => strlen($this->content);
    }
}
