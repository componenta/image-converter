<?php

declare(strict_types=1);

use Componenta\Detector\FinfoDetector;
use Componenta\Image\ConversionException;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConversionOptions;
use Componenta\Image\Converter\Internal\EncodedOutput;

require __DIR__ . '/../vendor/autoload.php';

/** @return array{rss_kib: int, php_bytes: int} */
function workerMemorySample(): array
{
    gc_collect_cycles();
    $status = file_get_contents('/proc/self/status');
    if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
        throw new RuntimeException('Linux process RSS is unavailable.');
    }

    return ['rss_kib' => (int) $matches[1], 'php_bytes' => memory_get_usage(false)];
}

$image = imagecreatetruecolor(512, 384);
imagefilledrectangle($image, 0, 0, 255, 383, imagecolorallocate($image, 200, 30, 50));
imagefilledrectangle($image, 256, 0, 511, 383, imagecolorallocate($image, 30, 80, 220));
$fixtures = [
    'jpeg' => EncodedOutput::capture(static fn(): bool => imagejpeg($image, null, 90)),
    'png' => EncodedOutput::capture(static fn(): bool => imagepng($image, null, 6)),
    'webp' => EncodedOutput::capture(static fn(): bool => imagewebp($image, null, 90)),
];
unset($image);
$service = new ImageConverter(new FinfoDetector(), [
    new GdJpegConverter(), new GdPngConverter(), new GdWebPConverter(), new GdAvifConverter(),
]);
$cases = [['jpeg', 'png'], ['png', 'jpeg'], ['png', 'webp']];
$options = [
    'jpeg' => new ImageConversionOptions(quality: 80),
    'png' => new ImageConversionOptions(compressionLevel: 6),
    'webp' => new ImageConversionOptions(quality: 80),
];
$process = static function (int $cycles) use ($service, $fixtures, $cases, $options): void {
    for ($cycle = 0; $cycle < $cycles; ++$cycle) {
        foreach ($cases as [$from, $to]) {
            $result = $service->convert($fixtures[$from], $to, $options[$to]);
            $header = getimagesizefromstring($result->content);
            if ($header === false || $header[0] !== 512 || $header[1] !== 384 || $header['mime'] !== 'image/' . $to) {
                throw new RuntimeException('Unexpected output during worker memory probe.');
            }
            unset($result);
        }
        try {
            $service->convert('not an image', 'png');
            throw new RuntimeException('Invalid image was accepted.');
        } catch (ConversionException) {
            // Expected rejection.
        }
    }
};
$process(20);
$baseline = workerMemorySample();
echo json_encode(['stage' => 'warmup', ...$baseline], JSON_THROW_ON_ERROR), PHP_EOL;
$overLimit = 0;
for ($batch = 1; $batch <= 3; ++$batch) {
    $process(60);
    $sample = workerMemorySample();
    echo json_encode(['stage' => 'batch-' . $batch, ...$sample], JSON_THROW_ON_ERROR), PHP_EOL;
    $exceeded = $sample['rss_kib'] - $baseline['rss_kib'] > 16384
        || $sample['php_bytes'] - $baseline['php_bytes'] > 2097152;
    $overLimit = $exceeded ? $overLimit + 1 : 0;
    if ($overLimit >= 2) {
        throw new RuntimeException('Persistent memory growth after worker warmup.');
    }
}
echo 'Repeated image processing passed.', PHP_EOL;
