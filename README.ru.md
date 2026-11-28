# Componenta Image Converter

[English](README.md)

Преобразование статических растровых изображений в выбранный формат. Для повторного сжатия без смены формата используйте [Image Compressor](https://github.com/componenta/image-compressor).

## Установка

```sh
composer require componenta/image-converter
```

Требования: PHP 8.4 или новее в пределах ветки 8.x, расширения `gd` и `exif`. Зависимости: Componenta Config 4 и MIME Type Detector 2.

Документация для версии 2.x. [Переход с предыдущей версии](UPGRADE.md).

## Быстрый старт

```php
use Componenta\Detector\FinfoDetector;
use Componenta\Image\GdAvifConverter;
use Componenta\Image\GdJpegConverter;
use Componenta\Image\GdPngConverter;
use Componenta\Image\GdWebPConverter;
use Componenta\Image\ImageConverter;
use Componenta\Image\ImageConversionOptions;

$converter = new ImageConverter(new FinfoDetector(), [
    new GdJpegConverter(),
    new GdPngConverter(),
    new GdWebPConverter(),
    new GdAvifConverter(),
]);

$image = $converter->convertFile(
    'photo.png',
    'webp',
    new ImageConversionOptions(quality: 85),
);

file_put_contents('photo.webp', $image->content);
```

`convertFile()` принимает путь к локальному файлу; `convert()` — байты изображения или поток PSR-7. Оркестратор определяет MIME-тип по содержимому и выбирает зарегистрированный обработчик.

## Поддерживаемые форматы

| Целевой формат | Исходные форматы | Класс | По умолчанию |
| --- | --- | --- | --- |
| JPEG | PNG, WebP, AVIF, BMP | `GdJpegConverter` | `quality: 82` |
| PNG | JPEG, WebP, AVIF, BMP | `GdPngConverter` | `compressionLevel: 6` |
| WebP | JPEG, PNG, AVIF, BMP | `GdWebPConverter` | `quality: 80` |
| AVIF | JPEG, PNG, WebP, BMP | `GdAvifConverter` | `quality: 80` |

Целевой формат задаётся MIME-типом, коротким именем или зарегистрированным расширением: `image/jpeg`, `jpeg`, `jpg`, `.jpg`. Регистр букв не учитывается. Преобразование в тот же формат встроенными обработчиками не поддерживается.

## Публичный API

### `ImageConverter`

Сигнатуры публичных методов и свойств:

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
public function convertFile(
    string $path,
    string $targetType,
    ?ImageConversionOptions $options = null,
): ConvertedImage;
public function canConvert(string|StreamInterface $source, string $targetType): bool;
public function addConverter(ImageConverterInterface&ImageConversionTargetProviderInterface $converter): void;

/** @var list<string> */
public array $targetMimeTypes { get; }
```

`targetMimeTypes` возвращает уникальные канонические MIME-типы зарегистрированных форматов в порядке регистрации. Свойство доступно только для чтения, не содержит псевдонимов и учитывает последующие добавления. Пустой реестр возвращает `[]`.

`canConvert()` нужен для предварительной проверки, например при выборе доступной операции в интерфейсе приложения. Он читает байты или поток, определяет MIME-тип и проверяет, есть ли подходящий конвертер для заданного целевого формата. Возвращает `false`, если целевой формат не зарегистрирован, обработчик не поддерживает исходный формат, содержимое не удалось прочитать или определить его MIME-тип, либо превышен входной лимит. Поток без поддержки позиционирования не читается. Положительный результат не гарантирует успешной обработки: геометрия, целостность изображения, параметры и выходной лимит проверяются при выполнении операции. Наличие формата в `targetMimeTypes` также не гарантирует доступности кодировщика GD.

`supports()` используется на уровне конкретного обработчика, когда исходный MIME-тип уже известен. Например, `$codec->supports('image/png')` проверяет поддержку формата и наличие необходимых кодеков GD без чтения изображения. Оркестратор сам вызывает `supports()` при выборе обработчика.

Необязательно вызывать `canConvert()` перед `convert()` или `convertFile()`: операция сама выполняет необходимые проверки. Предварительный вызов повторно читает источник и определяет MIME-тип.

`convertFile()` читает обычный локальный файл в пределах `maxInputBytes`, закрывает его и возвращает `ConvertedImage`, не изменяя исходный файл. URL, обёртки потоков и специальные файлы не поддерживаются; символьные ссылки на обычные файлы допустимы. Разрешение доступа к пути остаётся ответственностью вызывающей стороны.

### Прямое использование обработчика

При известном формате можно обойтись без оркестратора:

```php
$source = file_get_contents('photo.png');
if ($source === false) {
    throw new \RuntimeException('Не удалось прочитать изображение.');
}

$codec = new GdWebPConverter(defaultQuality: 85);
if ($codec->supports('image/png')) {
    $image = $codec->convert($source);
}
```

Все встроенные обработчики реализуют `ImageConverterInterface`. Для JPEG, WebP и AVIF начальное качество задаётся параметром конструктора `defaultQuality`, для PNG — `defaultCompressionLevel`. Все конструкторы принимают `limits`.

### `ImageConversionOptions`

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

Создание результата: `new ConvertedImage($content, $mimeType, $extension)`. Свойства доступны только для чтения. Содержимое должно быть непустым, MIME-тип — конкретным типом изображения без параметров, расширение — корректным и соответствующим известному MIME-типу. Для неизвестного справочнику формата допустимо своё корректное расширение. MIME-тип и расширение приводятся к нижнему регистру. Проверка метаданных не подтверждает корректность байтов или безопасность пути сохранения.

## Потоки и ограничения ресурсов

Поток с поддержкой позиционирования читается с начала; исходная позиция восстанавливается при успехе и при ошибке. Поток без поддержки позиционирования читается однократно с текущей позиции, а `canConvert()` возвращает `false`, не читая его.

Неизменяемые настройки `ImageConversionLimits`:

| Параметр | По умолчанию |
| --- | ---: |
| `maxInputBytes` | 33 554 432 (32 МиБ) |
| `maxOutputBytes` | 67 108 864 (64 МиБ) |
| `maxPixels` | 16 000 000 |
| `maxWidth` | 8 192 |
| `maxHeight` | 8 192 |

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

Перехватывайте исключения по интерфейсам: категории ошибок не пересекаются.

| Интерфейс | Причина |
| --- | --- |
| `ConversionExceptionInterface` | Неподдерживаемый формат или параметры, повреждённое изображение, превышение лимитов, ошибки преобразования или конфигурации. |
| `ConversionFileExceptionInterface` | Ошибка доступа к файлу, недопустимый путь или ошибка файлового ввода-вывода в `convertFile()`. |
| `ConversionStreamExceptionInterface` | Ошибка чтения или позиционирования переданного потока. |

Файловые ошибки реализованы классами `ConversionFileUnavailableException` (путь не удалось разрешить или файл недоступен), `ConversionInvalidFileException` (недопустимый путь или тип файла) и `ConversionFileReadException` (ошибка открытия, чтения или закрытия). PHP не всегда позволяет надёжно различить отсутствие файла и отказ в доступе при разрешении пути. Превышение лимита размера файла относится к ошибкам преобразования, а не файлового ввода-вывода.

`ConversionConfigurationException` реализует `ConversionExceptionInterface` и наследует `InvalidArgumentException`. `ConversionStreamPositionRestoreExceptionInterface` наследует `ConversionStreamExceptionInterface`: позиция потока не гарантирована, исходная ошибка чтения доступна в `readFailure`, ошибка восстановления — через `getPrevious()`. В отличие от обычных ошибок чтения, эта ошибка не подавляется методом `canConvert()`.

```php
use Componenta\Image\ConversionExceptionInterface;
use Componenta\Image\ConversionFileExceptionInterface;

try {
    $image = $converter->convertFile('photo.png', 'webp');
} catch (ConversionFileExceptionInterface $error) {
    // Ошибка доступа к источнику.
} catch (ConversionExceptionInterface $error) {
    // Ошибка преобразования.
}
```

## Особенности обработки

- Встроенные обработчики работают со статическими изображениями. GIF, APNG, анимированный WebP и последовательности AVIF не поддерживаются. Проверка структуры не заменяет полную валидацию изображения.
- Ориентация JPEG из EXIF применяется при перекодировании. При преобразовании в JPEG прозрачные области накладываются на белый фон.
- Сохранение метаданных, цветовых профилей, HDR и исходной глубины цвета не гарантируется. Дополнительные данные после конца JPEG или за пределами контейнера WebP удаляются при перекодировании.
- GD хранит прозрачность с семибитной точностью; восьмибитное значение альфа-канала PNG может измениться на единицу. Низкое качество AVIF также может влиять на точность прозрачности.
- 16-битные PNG с цветовым ключом прозрачности (tRNS) отклоняются; остальные 16-битные PNG преобразуются с уменьшенной точностью. Сохранение прозрачности 32-битного BMP не гарантируется.
- AVIF с ненейтральными преобразованиями отображения основного изображения (поворот, отражение, кадрирование, изменение пропорций пикселей) отклоняется. AVIF с дорожками требует основной марки `avif`.

**AVIF в долгоживущих процессах.** В некоторых сборках libgd имеется [утечка нативной памяти при кодировании AVIF](https://github.com/libgd/libgd/pull/832). Используйте исправленную сборку; до обновления не регистрируйте кодировщик AVIF либо выполняйте кодирование в изолированных процессах с ограниченным временем жизни.

## Собственные обработчики

Для самостоятельного использования реализуйте `ImageConverterInterface`. Для регистрации в оркестраторе дополнительно реализуйте `ImageConversionTargetProviderInterface`:

```php
interface ImageConverterInterface
{
    /**
     * @throws ConversionExceptionInterface
     * @throws ConversionStreamExceptionInterface
     */
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

Зарегистрируйте `ImageConversionLimits::class` для настройки пределов стандартных фабрик и `MimeTypeDetectorInterface::class` для собственного детектора (по умолчанию `FinfoDetector`). Переопределённые обработчики сохраняют собственные настройки. В стандартной конфигурации `ImageConverterInterface` соответствует `GdWebPConverter`, а не оркестратору.

## Лицензия

[MIT](LICENSE).
