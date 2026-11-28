<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** The original position could not be restored; the caller must not reuse the stream blindly. */
final class ConversionStreamPositionRestoreException extends ConversionStreamException implements ConversionStreamPositionRestoreExceptionInterface
{
    public function __construct(
        \Throwable $restorationFailure,
        public readonly ?\Throwable $readFailure = null,
    ) {
        parent::__construct(
            $readFailure === null
                ? 'Unable to restore image source position.'
                : 'Unable to read image source and restore its original position.',
            previous: $restorationFailure,
        );
    }
}
