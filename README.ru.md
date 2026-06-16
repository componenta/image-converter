# Componenta Image Converter

Контракты конвертации изображений и GD-конвертеры для Componenta. Пакет прячет низкоуровневые вызовы GD за небольшими контрактами и предоставляет оркестратор `ImageConverter`, который выбирает конвертер по целевому формату и проверяет MIME-тип источника через `componenta/mimetype-detector`.

## Требования

- PHP 8.4+
- расширение GD
- `componenta/mimetype-detector` для определения MIME-типа источника

## Установка

```bash
composer require componenta/image-converter
```

Пакет объявляет `Componenta\Image\ConfigProvider` в `extra.componenta.config-providers`.
Если установлен `componenta/composer-plugin`, провайдер автоматически добавляется в сгенерированный список провайдеров.

## Основной API

`ImageConverterInterface` — низкоуровневый контракт для конвертеров одного целевого формата:

```php
use Componenta\Image\ImageConverterInterface;
use Psr\Http\Message\StreamInterface;

interface ImageConverterInterface
{
    public function convert(string|StreamInterface $source, ?int $quality = null): ConvertedImage;

    public function supports(string $mimeType): bool;
}
```

`ImageConverter` — оркестратор для прикладного кода, которому нужно выбирать целевой формат во время выполнения:

```php
use Componenta\Image\ImageConverter;

if ($converter->canConvert($source, 'webp')) {
    $image = $converter->convert($source, 'webp', quality: 82);
}

$image->content;   // бинарные байты результата
$image->mimeType;  // image/webp
$image->extension; // webp
$image->size;      // размер в байтах
```

`$source` может быть бинарной строкой или `Psr\Http\Message\StreamInterface`. Целевой тип можно передавать коротким именем или MIME-типом: `webp`, `image/webp`, `jpg`, `jpeg`, `image/jpeg`, `png`, `image/avif`.

## Встроенные конвертеры

| Конвертер | Цель | Поддерживаемые MIME-типы источника |
|---|---|---|
| `GdWebPConverter` | `webp`, `image/webp` | JPEG, PNG, BMP. GIF намеренно не поддерживается, потому что GD потеряет анимацию. |
| `GdJpegConverter` | `jpeg`, `jpg`, `image/jpeg` | PNG, WebP, AVIF, BMP. Альфа-канал сглаживается на белый фон. |
| `GdPngConverter` | `png`, `image/png` | JPEG, WebP, AVIF, BMP. Альфа-канал сохраняется, когда он есть. |
| `GdAvifConverter` | `avif`, `image/avif` | JPEG, PNG, WebP, BMP, если локальная сборка GD поддерживает `imageavif()`. |

Для JPEG, WebP и AVIF качество задается в диапазоне `0..100`. Для PNG тот же диапазон мапится на уровень сжатия GD `0..9`.

## Точки расширения

Для нового backend или формата реализуйте `ImageConverterInterface`. Если конвертер также реализует `ImageConversionTargetsProviderInterface`, оркестратор сможет автоматически прочитать его свойство `conversionTargets`:

```php
use Componenta\Image\ImageConversionTargetsProviderInterface;
use Componenta\Image\ImageConverterInterface;

final class ImagickWebPConverter implements ImageConverterInterface, ImageConversionTargetsProviderInterface
{
    public array $conversionTargets {
        get => ['webp', 'image/webp'];
    }

    // convert() и supports()
}
```

Конвертеры можно добавлять после создания оркестратора:

```php
$converter->addConverter(new ImagickWebPConverter());
$converter->addConverter(new CustomFormatConverter(), 'custom', 'image/x-custom');
```

Если целевые типы переданы в `addConverter()` явно, они используются для регистрации вместо опционального свойства провайдера.

## Ошибки

`ImageConverter` выбрасывает `ConversionException`, если:

- для целевого типа не зарегистрирован конвертер;
- MIME-тип источника не удалось определить;
- конвертер целевого формата не поддерживает MIME-тип источника;
- GD не смог декодировать или закодировать изображение;
- качество выходит за пределы `0..100`.

Ограничения upload лучше валидировать до конвертации. Ошибку конвертации обрабатывайте как пользовательскую ошибку медиафайла, а не как доменное правило.

## DI-регистрация

`ConfigProvider` регистрирует:

- `ImageConverter` через `ImageConverterFactory`;
- `GdWebPConverter`, `GdJpegConverter`, `GdPngConverter` и `GdAvifConverter`;
- `ImageConverterInterface` как alias на `GdWebPConverter` для кода, которому нужен фиксированный WebP-конвертер.

`ImageConverterFactory` берет `MimeTypeDetectorInterface` из контейнера, если он есть, и иначе использует `FinfoDetector`.
