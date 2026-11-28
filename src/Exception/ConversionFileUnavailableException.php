<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** The path cannot be resolved or accessed; PHP does not always distinguish absence from denied access. */
final class ConversionFileUnavailableException extends \RuntimeException implements ConversionFileExceptionInterface {}
