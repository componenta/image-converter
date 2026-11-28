<?php

declare(strict_types=1);

namespace Componenta\Image;

/** Opening, reading or closing a regular image file failed. */
final class ConversionFileReadException extends \RuntimeException implements ConversionFileExceptionInterface {}
