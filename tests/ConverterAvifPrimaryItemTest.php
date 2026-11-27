<?php

declare(strict_types=1);

use Componenta\Image\ConversionException;
use Componenta\Image\GdPngConverter;

// Two independently encoded AV1 image items, with a thmb reference 2 -> 1.
// libavif 1.2.1 decodes the primary item as 32x16. Only the associated irot
// property differs between fixtures; the actual AV1 samples are unchanged.
test('AVIF display checks apply to the primary image, not its unused thumbnail', function (string $fixture, bool $rotatedPrimary): void {
    $codec = new GdPngConverter();
    if (!$codec->supports('image/avif')) {
        $this->markTestSkipped('AVIF decoding unavailable.');
    }
    $source = base64_decode($fixture, true);
    $info = getimagesizefromstring($source);
    expect($info['mime'])->toBe('image/avif');
    expect([$info[0], $info[1]])->toBe([32, 16]);
    $native = imagecreatefromstring($source);
    expect($native)->toBeInstanceOf(GdImage::class);
    expect([imagesx($native), imagesy($native)])->toBe([32, 16]);
    if ($rotatedPrimary) {
        expect(fn() => $codec->convert($source))
            ->toThrow(ConversionException::class, 'AVIF display transformation');

        return;
    }
    $result = $codec->convert($source);
    $output = imagecreatefromstring($result->content);
    expect($output)->toBeInstanceOf(GdImage::class);
    expect([imagesx($output), imagesy($output)])->toBe([32, 16]);
})->with([
    'rotated thumbnail' => ['AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAGBbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAACxpbG9jAAAAAEQAAAIAAQAAAAEAAAGpAAAAKgACAAAAAQAAAdMAAAAsAAAAQmlpbmYAAAAAAAIAAAAaaW5mZQIAAAAAAQAAYXYwMUNvbG9yAAAAABppbmZlAgAAAAACAABhdjAxQ29sb3IAAAAAGmlyZWYAAAAAAAAADnRobWIAAgABAAEAAAC+aXBycAAAAJdpcGNvAAAAFGlzcGUAAAAAAAAAIAAAABAAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABRpc3BlAAAAAAAAABAAAAAIAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQAMAAAAABNjb2xybmNseAABAA0ABoAAAAAJaXJvdAEAAAAfaXBtYQAAAAAAAAACAAEEAQKDBAACBQUGhwiJAAAAXm1kYXQSAAoJGBD/7RAQ0GhAMhsTB7e2aQEEEF4QAAAAV9suKREhBt+xs7LWEUASAAoIGAy/tEBDQaEyHhMHt7ZpAQQQXhAAAADLqenr2WFMP9+C48wRZeU+8A==', false],
    'rotated primary' => ['AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAAGBbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAAAAAAAOcGl0bQAAAAAAAQAAACxpbG9jAAAAAEQAAAIAAQAAAAEAAAGpAAAAKgACAAAAAQAAAdMAAAAsAAAAQmlpbmYAAAAAAAIAAAAaaW5mZQIAAAAAAQAAYXYwMUNvbG9yAAAAABppbmZlAgAAAAACAABhdjAxQ29sb3IAAAAAGmlyZWYAAAAAAAAADnRobWIAAgABAAEAAAC+aXBycAAAAJdpcGNvAAAAFGlzcGUAAAAAAAAAIAAAABAAAAAQcGl4aQAAAAADCAgIAAAADGF2MUOBAAwAAAAAE2NvbHJuY2x4AAEADQAGgAAAABRpc3BlAAAAAAAAABAAAAAIAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQAMAAAAABNjb2xybmNseAABAA0ABoAAAAAJaXJvdAEAAAAfaXBtYQAAAAAAAAACAAEFAQKDBIkAAgQFBocIAAAAXm1kYXQSAAoJGBD/7RAQ0GhAMhsTB7e2aQEEEF4QAAAAV9suKREhBt+xs7LWEUASAAoIGAy/tEBDQaEyHhMHt7ZpAQQQXhAAAADLqenr2WFMP9+C48wRZeU+8A==', true],
]);
