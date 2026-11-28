<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** The supplied path or resolved file type is invalid. */
final class ConversionInvalidFileException extends \RuntimeException implements ConversionFileExceptionInterface {}
