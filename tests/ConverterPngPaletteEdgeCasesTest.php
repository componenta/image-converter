<?php

declare(strict_types=1);

use Componenta\Image\GdWebPConverter;
use Componenta\Image\GdAvifConverter;

// Independently generated PNG chunks, packed samples and CRCs. Expectations
// come from the original sample values and tRNS table, not a GD round trip.
test('preserves nonzero grayscale keys and independently transparent palette entries', function (string $fixture, array $expected, string $target): void {
    $source = base64_decode($fixture, true);
    expect($source)->toBeString();
    $codec = match ($target) {
        'webp' => new GdWebPConverter(defaultQuality: 100),
        'avif' => new GdAvifConverter(defaultQuality: 100),
    };
    if (!$codec->supports('image/png')) {
        $this->markTestSkipped('Required native codec unavailable.');
    }
    $result = $codec->convert($source);
    $decoded = imagecreatefromstring($result->content);
    expect($decoded)->toBeInstanceOf(GdImage::class);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([count($expected) * 8, 8]);
    $key = imagecolortransparent($decoded);
    foreach ($expected as $index => [$expectedAlpha, $red, $green, $blue]) {
        $pixel = imagecolorat($decoded, $index * 8 + 4, 4);
        $color = imagecolorsforindex($decoded, $pixel);
        $alpha = $key >= 0 && $pixel === $key ? 127 : $color['alpha'];
        // WebP and AVIF are lossy color encodings; the alpha values at these
        // quality settings must remain distinct. Allow one unit of rounding.
        expect(abs($alpha - $expectedAlpha))->toBeLessThanOrEqual(1);
    }
})->with([
    'grayscale 1-bit nonzero key' => ['iVBORw0KGgoAAAANSUhEUgAAABgAAAAIAQAAAADL2gLOAAAAAnRSTlMAAQGU/a4AAAAPSURBVHicY/jPwMCABwMAh5gH+SM46EoAAAAASUVORK5CYII=', [[127, 255, 255, 255], [0, 0, 0, 0], [0, 0, 0, 0]]],
    'grayscale 2-bit nonzero key' => ['iVBORw0KGgoAAAANSUhEUgAAABgAAAAIAgAAAACMengeAAAAAnRSTlMAAQGU/a4AAAASSURBVHicYwgNZWD4/5+BVAoAPhYVQWjrKEcAAAAASUVORK5CYII=', [[127, 85, 85, 85], [0, 0, 0, 0], [0, 255, 255, 255]]],
    'grayscale 4-bit nonzero key' => ['iVBORw0KGgoAAAANSUhEUgAAABgAAAAIBAAAAAADOo2+AAAAAnRSTlMAB+j3WJsAAAAUSURBVHicYygHAgYg+A8EDLTjAAA77y7BfyXa6gAAAABJRU5ErkJggg==', [[127, 119, 119, 119], [0, 0, 0, 0], [0, 255, 255, 255]]],
    'grayscale 8-bit nonzero key' => ['iVBORw0KGgoAAAANSUhEUgAAABgAAAAICAAAAADGymC/AAAAAnRSTlMAf7YpoZUAAAAUSURBVHicY6iHAgYo+A8FDENLAgBQ5F+BQD3zMAAAAABJRU5ErkJggg==', [[127, 127, 127, 127], [0, 0, 0, 0], [0, 255, 255, 255]]],
    'duplicate RGB with different alpha' => ['iVBORw0KGgoAAAANSUhEUgAAACAAAAAIAgMAAADl6xU8AAAADFBMVEUoUIwoUIwUZMjmHiiHfZEeAAAABHRSTlMA/38Apaw3TgAAABRJREFUeJxjYGAIDV216v9/BsoYAEbkH+FFSHNPAAAAAElFTkSuQmCC', [[127, 40, 80, 140], [0, 40, 80, 140], [64, 20, 100, 200], [127, 230, 30, 40]]],
    'short alpha table' => ['iVBORw0KGgoAAAANSUhEUgAAACAAAAAIAgMAAADl6xU8AAAADFBMVEUoUIwoUIwUZMjmHiiHfZEeAAAAA3RSTlP/AH+EVJyeAAAAFElEQVR4nGNgYAgNXbXq/38GyhgARuQf4UVIc08AAAAASUVORK5CYII=', [[0, 40, 80, 140], [127, 40, 80, 140], [64, 20, 100, 200], [0, 230, 30, 40]]],
])->with(['webp', 'avif']);;
