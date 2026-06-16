<?php

declare(strict_types=1);

namespace Componenta\Image;

/**
 * Exposes target image types a converter can produce.
 *
 * This is intentionally separate from ImageConverterInterface::supports().
 * The supports() method describes acceptable source MIME types, while this
 * property describes target formats used by the conversion orchestrator.
 */
interface ImageConversionTargetsProviderInterface
{
    /**
     * Target type aliases accepted by the orchestrator, for example
     * "webp", "image/webp", "jpg", or "image/jpeg".
     *
     * @var list<string>
     */
    public array $conversionTargets { get; }
}
