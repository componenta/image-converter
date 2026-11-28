<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** A caller-owned stream could not be read. */
class ConversionStreamException extends \RuntimeException implements ConversionStreamExceptionInterface {}
