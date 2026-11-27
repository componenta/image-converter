<?php

declare(strict_types=1);

use Componenta\Image\Factory\ImageConverterFactory;
use Componenta\Image\ImageConversionLimits;
use Componenta\Image\ConversionException;
use Componenta\Image\Converter\Internal\EncodedOutput;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Container\ContainerInterface;

test('factory integrates the current MIME detector with one-shot PSR-7 input', function (): void {
    $container = $this->createStub(ContainerInterface::class);
    $container->method('has')->willReturn(false);
    $service = (new ImageConverterFactory())($container);
    $image = imagecreatetruecolor(3, 2);
    $source = EncodedOutput::capture(static fn(): bool => imagepng($image));
    $stream = new NoSeekStream(Utils::streamFor($source));
    expect($service->canConvert($stream, 'jpeg'))->toBeFalse();
    $result = $service->convert($stream, 'jpeg');
    expect($result->mimeType)->toBe('image/jpeg');
    expect(imagecreatefromstring($result->content))->toBeInstanceOf(GdImage::class);
});
