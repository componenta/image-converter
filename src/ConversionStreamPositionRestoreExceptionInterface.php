<?php

declare(strict_types=1);

namespace Componenta\Image;

/** A stream position could not be restored; the caller must not reuse it blindly. */
interface ConversionStreamPositionRestoreExceptionInterface extends ConversionStreamExceptionInterface
{
    /** Original read failure, if the read also failed. */
    public ?\Throwable $readFailure { get; }
}
