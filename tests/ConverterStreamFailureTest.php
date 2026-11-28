<?php

declare(strict_types=1);

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Image\ConversionException;
use Componenta\Image\ConversionStreamPositionRestoreException;
use Componenta\Image\ImageConverter;
use Componenta\Image\GdJpegConverter;
use Psr\Http\Message\StreamInterface;

test('a restoration failure is never hidden by the capability probe', function (bool $probe, bool $readFails): void {
    $restoreError = new RuntimeException('seek failed');
    $readError = new RuntimeException('read failed');
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('isReadable')->willReturn(true);
    $stream->method('isSeekable')->willReturn(true);
    $stream->method('tell')->willReturn(7);
    $stream->expects($this->once())->method('rewind');
    $stream->expects($this->once())->method('seek')->with(7)->willThrowException($restoreError);
    $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
    if ($readFails) {
        $stream->method('read')->willThrowException($readError);
    } else {
        $stream->method('read')->willReturn('bytes');
    }
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $codec = new GdJpegConverter();
    $service = new ImageConverter($detector);
    $service->addConverter($codec);
    try {
        if ($probe) {
            $service->canConvert($stream, 'jpeg');
        } else {
            $service->convert($stream, 'jpeg');
        }
        $this->fail('Expected stream restoration failure.');
    } catch (ConversionStreamPositionRestoreException $error) {
        expect($error)->toBeInstanceOf(ConversionException::class);
        expect($error->getPrevious())->toBe($restoreError);
        if ($readFails) {
            expect($error->readFailure)->toBeInstanceOf(ConversionException::class);
            expect($error->readFailure->getPrevious())->toBe($readError);
        } else {
            expect($error->readFailure)->toBeNull();
        }
    }
})->with([true, false])->with([true, false]);

test('a non-progressing stream is rejected without invoking detection', function (): void {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('isReadable')->willReturn(true);
    $stream->method('isSeekable')->willReturn(false);
    $stream->method('eof')->willReturn(false);
    $stream->expects($this->once())->method('read')->willReturn('');
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $codec = new GdJpegConverter();
    $service = new ImageConverter($detector);
    $service->addConverter($codec);
    expect(fn() => $service->convert($stream, 'jpeg'))->toThrow(ConversionException::class, 'no progress');
});

test('ordinary read failure restores the seekable source', function (): void {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('isReadable')->willReturn(true);
    $stream->method('isSeekable')->willReturn(true);
    $stream->method('tell')->willReturn(5);
    $stream->method('eof')->willReturn(false);
    $stream->method('read')->willThrowException(new RuntimeException('read failed'));
    $stream->expects($this->once())->method('seek')->with(5);
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $codec = new GdJpegConverter();
    $service = new ImageConverter($detector);
    $service->addConverter($codec);
    expect($service->canConvert($stream, 'jpeg'))->toBeFalse();
});
