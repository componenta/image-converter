# Componenta Image Converter

[English](README.md)

Преобразование статических растровых изображений в выбранный формат. Для повторного сжатия без смены формата используйте [Image Compressor](https://github.com/componenta/image-compressor).

## Установка

```sh
composer require componenta/image-converter
```

Требования: PHP 8.4 или новее в пределах ветки 8.x, расширения `gd` и `exif`. Сборка GD должна поддерживать нужные форматы. Зависимости Composer: Componenta Config 4 и MIME Type Detector 2.

Документация для версии 2.x. [Переход с предыдущей версии](UPGRADE.md).

## Быстрый старт

```php
use Componenta\Detector\FinfoDetector;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\${name};
use Componenta\Image\${opt};

$converter = new ImageConverter(new FinfoDetector(), [
    new GdJpegConverter(),
    new GdPngConverter(),
    new GdWebPConverter(),
    new GdAvifConverter(),
]);

$source = file_get_contents('photo.png');
if ($source === false) {
    throw new \RuntimeException('Не удалось прочитать изображение.');
}

$image = $converter->convert(
    $source,
    'webp',
    new ImageConversionOptions(quality: 85),
);

file_put_contents('photo.webp', $image->content);
```

На вход передаются байты изображения или поток PSR-7 (`Psr\Http\Message\StreamInterface`), а не путь к файлу. Оркестратор определяет MIME-тип источника и выбирает зарегистрированный обработчик. При самостоятельном создании `ImageConverter` используются только обработчики, переданные в конструктор или добавленные позднее.

## Поддерживаемые форматы

| Целевой формат | Исходные форматы | Класс | По умолчанию |
| --- | --- | --- | --- |
| JPEG | PNG, WebP, AVIF, BMP | `GdJpegConverter` | `quality: 82` |
| PNG | JPEG, WebP, AVIF, BMP | `GdPngConverter` | `compressionLevel: 6` |
| WebP | JPEG, PNG, AVIF, BMP | `GdWebPConverter` | `quality: 80` |
| AVIF | JPEG, PNG, WebP, BMP | `GdAvifConverter` | `quality: 80` |

Целевой формат задаётся MIME-типом, коротким именем или зарегистрированным расширением: `image/jpeg`, `jpeg`, `jpg`, `.jpg`. Регистр букв не учитывается. Преобразование в тот же формат встроенными обработчиками не поддерживается.

Фактическая доступность кодеков зависит от сборки GD.

## Публичный API

### `ImageConverter`

```php
public function __construct(
    MimeTypeDetectorInterface $detector,
    iterable $converters = [],
    ImageConversionLimits $limits = new ImageConversionLimits(),
);
public function convert(
    string|StreamInterface $source,
    string $targetType,
    ?ImageConversionOptions $options = null,
): ConvertedImage;
public function canConvert(string|StreamInterface $source, string $targetType): bool;
public function addConverter(ImageConverterInterface&ImageConversionTargetProviderInterface $converter): void;

/** @var list<string> */
public array $targetMimeTypes { get; }
```

`targetMimeTypes` возвращает уникальные канонические MIME-типы зарегистрированных форматов в порядке регистрации. Свойство доступно только для чтения, не содержит псевдонимов и учитывает последующие добавления. Пустой реестр возвращает `[]`.

`canConvert()` проверяет возможность выбора обработчика и ограничение входного размера, но не гарантирует успешную обработку конкретного изображения. Наличие формата в `targetMimeTypes` также не гарантирует наличие кодировщика в GD.

### Прямое использование обработчика

При известном формате можно обойтись без оркестратора:

```php
$codec = new GdWebPConverter(defaultQuality: 85);
if ($codec->supports('image/png')) {
    $image = $codec->convert($source);
}
```

Все встроенные обработчики реализуют `ImageConverterInterface`. Метод `supports(string $mimeType): bool` проверяет поддержку исходного MIME-типа и наличие нужных кодеков GD. Для JPEG, WebP и AVIF начальное качество задаётся параметром конструктора `defaultQuality`, для PNG — `defaultCompressionLevel`. Все конструкторы принимают `limits`.

### `ImageConversionOptions`

Неизменяемые настройки операции.

| Параметр | Форматы | Значение |
| --- | --- | --- |
| `quality` | JPEG, WebP, AVIF | `int` от 0 до 100. Качество кодирования. |
| `compressionLevel` | PNG | `int` от 0 до 9. Уровень сжатия, а не качество изображения. |
| `avifSpeed` | AVIF | `int` от 0 до 10. Меньше — медленнее, с упором на сжатие. |

Для числовых параметров `null` (по умолчанию) означает настройки обработчика; скорость AVIF в этом случае выбирает GD. Неподдерживаемые параметры и значения вне допустимого диапазона отклоняются.

### `ConvertedImage`

| Свойство | Значение |
| --- | --- |
| `content: string` | Байты результата. |
| `mimeType: string` | MIME-тип, например `image/webp`. |
| `extension: string` | Расширение без точки, например `webp`. |
| `size: int` | Размер в байтах. |

Все свойства доступны только для чтения. При создании объекта проверяются непустое содержимое, конкретный MIME-тип изображения без параметров и соответствие расширения известному MIME-типу. Для пользовательских форматов вне стандартного справочника допустимо собственное корректное расширение. Эти проверки не подтверждают корректность самих байтов или безопасность пути сохранения.

## Потоки и ограничения ресурсов

Поток с поддержкой позиционирования читается с начала; исходная позиция восстанавливается при успехе и при ошибке. Поток без поддержки позиционирования читается однократно с текущей позиции, а `canConvert()` возвращает `false`, не читая его.

Неизменяемые настройки `ImageConversionLimits`:

| Параметр | По умолчанию |
| --- | ---: |
| `maxInputBytes` | 33,554,432 (32 МиБ) |
| `maxOutputBytes` | 67,108,864 (64 МиБ) |
| `maxPixels` | 16,000,000 |
| `maxWidth` | 8,192 |
| `maxHeight` | 8,192 |

Все пределы должны быть положительными. Оркестратор проверяет входной и выходной размер в байтах; геометрию и собственные пределы проверяет обработчик. При ручной настройке передавайте один объект ограничений обоим:

```php
$limits = new ImageConversionLimits(maxInputBytes: 8 * 1024 * 1024);
$converter = new ImageConverter(
    new FinfoDetector(),
    [new GdWebPConverter(limits: $limits)],
    limits: $limits,
);
```

Ограничения не гарантируют абсолютный предел нативной памяти или времени обработки. Для недоверенных загрузок используйте проверку входных данных, изоляцию процессов и ограничения ресурсов.

## Ошибки

- `ConversionException` — ошибки чтения, неподдерживаемые форматы и параметры, повреждённые изображения, превышение лимитов и ошибки обработки.
- `ConversionStreamPositionRestoreException` — не удалось восстановить позицию потока; подкласс предыдущего исключения, возможен также при проверке `canConvert()`. Исходная ошибка чтения доступна в `readFailure`, причина восстановления — через `getPrevious()`.
- `InvalidArgumentException` — некорректные пределы ресурсов или объявление целевого формата, конфликт регистрации.

## Особенности обработки

- Поддерживаются только статические изображения. GIF, анимированные PNG (APNG), WebP и последовательности AVIF не поддерживаются. Некорректные контейнеры отклоняются; проверка не является полной валидацией изображения.
- Ориентация JPEG из EXIF применяется при перекодировании. При преобразовании в JPEG прозрачные области накладываются на белый фон.
- Сохранение метаданных, цветовых профилей, HDR и исходной глубины цвета не гарантируется. Дополнительные данные за пределами контейнера JPEG или WebP допускаются и удаляются при перекодировании.
- GD хранит прозрачность с семибитной точностью; восьмибитное значение альфа-канала PNG может измениться на единицу. Низкое качество AVIF также может влиять на точность прозрачности.
- 16-битные PNG с цветовым ключом прозрачности (tRNS) отклоняются; остальные 16-битные PNG преобразуются с уменьшенной точностью. Сохранение прозрачности 32-битного BMP не гарантируется.
- AVIF с ненейтральными преобразованиями отображения основного изображения (поворот, отражение, кадрирование, изменение пропорций пикселей) отклоняется. AVIF с дорожками требует основной марки `avif`.

**AVIF в долгоживущих процессах.** В некоторых сборках libgd имеется [утечка нативной памяти при кодировании AVIF](https://github.com/libgd/libgd/pull/832). Используйте исправленную сборку; до обновления исключите AVIF из обработки либо выполняйте кодирование в изолированных процессах с ограниченным временем жизни.

## Собственные обработчики

Для самостоятельного использования реализуйте `ImageConverterInterface`. Для регистрации в оркестраторе дополнительно реализуйте `ImageConversionTargetProviderInterface`:

```php
interface ImageConverterInterface
{
    public function convert(
        string|StreamInterface $source,
        ?ImageConversionOptions $options = null,
    ): ConvertedImage;

    public function supports(string $mimeType): bool;
}

interface ImageConversionTargetProviderInterface
{
    public string $targetMimeType { get; }

    /** @var list<string> */
    public array $targetAliases { get; }
}
```

Обработчик объявляет один постоянный целевой MIME-тип без параметров. Его подтип регистрируется автоматически. Например, `targetMimeType = 'image/x-special'` и `targetAliases = ['special']` позволяют использовать `image/x-special`, `x-special` и `special`, но не `image/special`. Псевдонимы не могут подменять зарезервированные имена других встроенных форматов. Конфликт регистрации отклоняется целиком; повторная регистрация того же экземпляра не меняет его целевой MIME-тип. Результат должен соответствовать объявленному формату.

При вызове через оркестратор пользовательский обработчик получает байты, а не исходный поток. Он отвечает за корректность результата, соблюдение параметров, геометрических ограничений и безопасное декодирование.

## Интеграция с контейнером зависимостей

Поставщик конфигурации `Componenta\Image\ConfigProvider` регистрирует `ImageConverter` и четыре встроенных обработчика. После загрузки конфигурации получите сервис из контейнера PSR-11:

```php
$converter = $container->get(ImageConverter::class);
```

Для настройки зарегистрируйте `ImageConversionLimits::class` в контейнере; стандартные фабрики применят эти пределы к оркестратору и создаваемым обработчикам. Отдельно переопределённый обработчик сохраняет собственные настройки. Можно также зарегистрировать реализацию `MimeTypeDetectorInterface`; иначе используется `FinfoDetector`. Интерфейс `ImageConverterInterface` в стандартной конфигурации соответствует `GdWebPConverter`, а не оркестратору.

## Лицензия

[MIT](LICENSE).
