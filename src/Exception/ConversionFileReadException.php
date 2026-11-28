<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** Opening, reading or closing a regular image file failed. */
final class ConversionFileReadException extends \RuntimeException implements ConversionFileExceptionInterface {}
