<?php

declare(strict_types=1);

namespace Componenta\Image\Tests;

use Componenta\Image\ConvertedImage;
use PHPUnit\Framework\TestCase;

final class ConvertedImageTest extends TestCase
{
    public function testExposesConvertedImageMetadata(): void
    {
        $image = new ConvertedImage('bytes', 'image/webp', 'webp');

        self::assertSame('bytes', $image->content);
        self::assertSame('image/webp', $image->mimeType);
        self::assertSame('webp', $image->extension);
        self::assertSame(5, $image->size);
    }
}
