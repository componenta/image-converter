<?php

declare(strict_types=1);

namespace Componenta\Image\Converter\Exception;

/** Invalid converter configuration or registration. */
final class ConversionConfigurationException extends \InvalidArgumentException implements ConversionExceptionInterface {}
