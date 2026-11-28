<?php

declare(strict_types=1);

use Componenta\Image\GdPngConverter;
use Componenta\Image\GdJpegConverter;

test('source MIME normalization preserves case and horizontal whitespace but not control bytes', function (): void {
    foreach ([['image/jpeg', new GdPngConverter()], ['image/png', new GdJpegConverter()], ['image/webp', new GdPngConverter()], ['image/avif', new GdPngConverter()], ['image/bmp', new GdPngConverter()]] as [$mime, $codec]) {
        // Native availability is orthogonal to MIME normalization.
        expect($codec->supports(" \t" . strtoupper($mime) . "\t "))->toBe($codec->supports($mime));
        foreach (["\0" . $mime, $mime . "\0", $mime . "\r\n", "\x0b" . $mime, $mime . "\x0b"] as $invalid) {
            expect($codec->supports($invalid))->toBeFalse();
        }
    }
});
