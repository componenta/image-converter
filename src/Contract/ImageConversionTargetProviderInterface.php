<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Contract;

use Componenta\Image\Converter\ImageConverter;

/** Describes the one fixed output format of a converter registered with ImageConverter. */
interface ImageConversionTargetProviderInterface
{
    /** Concrete image MIME type without parameters, stable for the lifetime of the converter. */
    public string $targetMimeType { get; }

    /**
     * Additional short target names or extensions (e.g. "jpg").
     * The canonical MIME type and its subtype are registered automatically.
     *
     * @var list<string>
     */
    public array $targetAliases { get; }
}
