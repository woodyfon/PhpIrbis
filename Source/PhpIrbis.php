<?php

//
// Простой клиент для АБИС ИРБИС64.
//

// Статус записи

const LOGICALLY_DELETED  = 1;  // Запись логически удалена
const PHYSICALLY_DELETED = 2;  // Запись физически удалена
const ABSENT             = 4;  // Запись отсутствует
const NON_ACTUALIZED     = 8;  // Запись не актуализирована
const LAST_VERSION       = 32; // Последняя версия записи
const LOCKED_RECORD      = 64; // Запись заблокирована на ввод

// Распространённые форматы

const ALL_FORMAT       = "&uf('+0')";
const BRIEF_FORMAT     = '@brief';
const IBIS_FORMAT      = '@ibiskw_h';
const INFO_FORMAT      = '@info_w';
const OPTIMIZED_FORMAT = '@';

// Распространённые поиски

const KEYWORD_PREFIX    = 'K=';  // Ключевые слова
const AUTHOR_PREFIX     = 'A=';  // Индивидуальный автор, редактор, составитель
const COLLECTIVE_PREFIX = 'M=';  // Коллектив или мероприятие
const TITLE_PREFIX      = 'T=';  // Заглавие
const INVENTORY_PREFIX  = 'IN='; // Инвентарный номер, штрих-код или радиометка
const INDEX_PREFIX      = 'I=';  // Шифр документа в базе

// Логические операторы для поиска

const LOGIC_OR                = 0; // Только ИЛИ
const LOGIC_OR_AND            = 1; // ИЛИ и И
const LOGIC_OR_AND_NOT        = 2; // ИЛИ, И, НЕТ (по умолчанию)
const LOGIC_OR_AND_NOT_FIELD  = 3; // ИЛИ, И, НЕТ, И (в поле)
const LOGIC_OR_AND_NOT_PHRASE = 4; // ИЛИ, И, НЕТ, И (в поле), И (фраза)

/**
 * Пустая ли данная строка?
 *
 * @param string $text Строка для изучения.
 * @return bool
 */
function isNullOrEmpty($text) {
    return (!isset($text) || $text == false || trim($text) == '');
}

/**
 * Строки совпадают с точностью до регистра символов?
 *
 * @param string $str1 Первая строка.
 * @param string $str2 Вторая строка.
 * @return bool
 */
function sameString($str1, $str2) {
    return strcasecmp($str1, $str2) == 0;
}

/**
 * Замена переводов строки с ИРБИСных на обычные.
 *
 * @param string $text Текст для замены.
 * @return mixed
 */
function irbisToDos($text) {
    return str_replace("\x1F\x1E", "\n", $text);
}

/**
 * Разбивка текста на строки по ИРБИСным разделителям.
 *
 * @param string $text Текст для разбиения.
 * @return array
 */
function irbisToLines($text) {
    return explode("\x1F\x1E", $text);
}

/**
 * Удаление комментариев из строки.
 *
 * @param string $text Текст для удаления комментариев.
 * @return string
 */
function removeComments($text) {
    if (isNullOrEmpty($text)) {
        return $text;
    }

    if (strpos($text, '/*') == false) {
        return $text;
    }

    $result = '';
    $state = '';
    $index = 0;
    $length = strlen($text);

    while ($index < $length) {
        $c = $text[$index];

        switch ($state) {
            case "'":
            case '"':
            case '|':
                if ($c == $state) {
                    $state = '';
                }

                $result .= $c;
                break;

            default:
                if ($c == '/') {
                    if ($index + 1 < $length && $text[$index + 1] == '*') {
                        while ($index < $length) {
                            $c = $text[$index];
                            if ($c == "\r" || $c == "\n") {
                                $result .= $c;
                                break;
                            }

                            $index++;
                        }
                    }
                    else {
                        $result .= $c;
                    }
                }
                else if ($c == "'" || $c == '""' || $c == '|') {
                    $state = $c;
                    $result .= $c;
                }
                else {
                    $result .= $c;
                }
                break;
        }

        $index++;
    }

    return $result;
}

/**
 * Подготовка динамического формата
 * для передачи на сервер.
 *
 * В формате должны отсутствовать комментарии
 * и служебные символы (например, перевод
 * строки или табуляция).
 *
 * @param string $text Текст для обработки.
 * @return string
 */
function prepareFormat ($text) {
    $text = removeComments($text);
    $length = strlen($text);
    if (!$length) {
        return $text;
    }

    $flag = false;
    for ($i = 0; $i < $length; $i++) {
        if ($text[$i] < ' ') {
            $flag = true;
            break;
        }
    }

    if ($flag) {
        return $text;
    }

    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $c = $text[$i];
        if ($c >= ' ') {
            $result .= $c;
        }
    }

    return $result;
}

/**
 * Получение описания по коду ошибки, возвращенному сервером.
 *
 * @param integer $code
 * @return mixed
 */
function describeError($code) {
    $errors = array (
        -100 => 'Заданный MFN вне пределов БД',
        -101 => 'Ошибочный размер полки',
        -102 => 'Ошибочный номер полки',
        -140 => 'MFN вне пределов БД',
        -141 => 'Ошибка чтения',
        -200 => 'Указанное поле отсутствует',
        -201 => 'Предыдущая версия записи отсутствует',
        -202 => 'Заданный термин не найден (термин не существует)',
        -203 => 'Последний термин в списке',
        -204 => 'Первый термин в списке',
        -300 => 'База данных монопольно заблокирована',
        -301 => 'База данных монопольно заблокирована',
        -400 => 'Ошибка при открытии файлов MST или XRF (ошибка файла данных)',
        -401 => 'Ошибка при открытии файлов IFP (ошибка файла индекса)',
        -402 => 'Ошибка при записи',
        -403 => 'Ошибка при актуализации',
        -600 => 'Запись логически удалена',
        -601 => 'Запись физически удалена',
        -602 => 'Запись заблокирована на ввод',
        -603 => 'Запись логически удалена',
        -605 => 'Запись физически удалена',
        -607 => 'Ошибка autoin.gbl',
        -608 => 'Ошибка версии записи',
        -700 => 'Ошибка создания резервной копии',
        -701 => 'Ошибка восстановления из резервной копии',
        -702 => 'Ошибка сортировки',
        -703 => 'Ошибочный термин',
        -704 => 'Ошибка создания словаря',
        -705 => 'Ошибка загрузки словаря',
        -800 => 'Ошибка в параметрах глобальной корректировки',
        -801 => 'ERR_GBL_REP',
        -801 => 'ERR_GBL_MET',
        -1111 => 'Ошибка исполнения сервера (SERVER_EXECUTE_ERROR)',
        -2222 => 'Ошибка в протоколе (WRONG_PROTOCOL)',
        -3333 => 'Незарегистрированный клиент (ошибка входа на сервер) (клиент не в списке)',
        -3334 => 'Клиент не выполнил вход на сервер (клиент не используется)',
        -3335 => 'Неправильный уникальный идентификатор клиента',
        -3336 => 'Нет доступа к командам АРМ',
        -3337 => 'Клиент уже зарегистрирован',
        -3338 => 'Недопустимый клиент',
        -4444 => 'Неверный пароль',
        -5555 => 'Файл не существует',
        -6666 => 'Сервер перегружен. Достигнуто максимальное число потоков обработки',
        -7777 => 'Не удалось запустить/прервать поток администратора (ошибка процесса)',
        -8888 => 'Общая ошибка'
    );

    return $errors[$code];
}

/**
 * Получение первого ненулевого значения.
 *
 * @param $first
 * @param $second
 * @param string $third
 * @return string
 */
function getOne($first, $second, $third='') {
    return $first ? $first : ($second ? $second : $third);
}

/**
 * @return array "Хорошие" коды для readRecord.
 */
function readRecordCodes() {
    return array(-201, -600, -602, -603);
}

/**
 * @return array "Хорошие" коды для readTerms.
 */
function readTermCodes() {
    return array(-202, -203, -204);
}

class IrbisException extends Exception {
    public function __construct($message = "",
                                $code = 0,
                                Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

/**
 * Подполе записи. Состоит из кода и значения.
 */
class SubField {
    /**
     * @var string Код подполя.
     */
    public $code;

    /**
     * @var string Значение подполя.
     */
    public $value;

    public function decode($line) {
        $this->code = $line[0];
        $this->value = substr($line, 1);
    }

    public function __toString() {
        return '^' . $this->code . $this->value;
    }
}

/**
 * Поле записи. Состоит из тега и (опционального) значения.
 * Может содержать произвольное количество подполей.
 */
class RecordField {
    /**
     * @var integer Метка поля.
     */
    public $tag;

    /**
     * @var string Значение поля до первого разделителя.
     */
    public $value;

    /**
     * @var array Массив подполей.
     */
    public $subfields = array();

    /**
     * Добавление подполя с указанными кодом и значением.
     *
     * @param string $code Код подполя.
     * @param string $value Значение подполя.
     * @return $this
     */
    public function add($code, $value) {
        $subfield = new SubField();
        $subfield->code = $code;
        $subfield->value = $value;
        array_push($this->subfields, $subfield);

        return $this;
    }

    /**
     * Декодирование поля из протокольного представления.
     *
     * @param string $line
     */
    public function decode($line) {
        $this->tag = strtok($line, "#");
        $body = strtok('');

        if ($body[0] == '^') {
            $this->value = '';
            $all = explode('^', $body);
        }
        else {
            $this->value = strtok($body, '^');
            $all = explode('^', strtok(''));
        }

        foreach ($all as $one) {
            if (!empty($one)) {
                $sf = new SubField();
                $sf->decode($one);
                array_push($this->subfields, $sf);
            }
        }
    }

    public function __toString() {
        $result = $this->tag . '#' . $this->value;

        foreach ($this->subfields as $sf) {
            $result .= $sf;
        }

        return $result;
    }
}

/**
 * Запись. Состоит из произвольного количества полей.
 */
class MarcRecord {
    /**
     * @var string Имя базы данных, в которой хранится запись.
     */
    public $database = '';

    /**
     * @var integer MFN записи.
     */
    public $mfn = 0;

    /**
     * @var integer Версия записи.
     */
    public $version = 0;

    /**
     * @var integer Статус записи.
     */
    public $status = 0;

    /**
     * @var array Массив полей.
     */
    public $fields = array();

    /**
     * Добавление поля в запись.
     *
     * @param integer $tag Метка поля.
     * @param string $value Значение поля до первого разделителя.
     * @return RecordField Созданное поле.
     */
    public function add($tag, $value='') {
        $field = new RecordField();
        $field->tag = $tag;
        $field->value = $value;
        array_push($this->fields, $field);

        return $field;
    }

    /**
     * Декодирование ответа сервера.
     *
     * @param array $lines Массив строк
     * с клиентским представлением записи.
     */
    public function decode(array $lines) {
        // mfn and status of the record
        $firstLine = explode('#', $lines[0]);
        $this->mfn = intval($firstLine[0]);
        $this->status = intval($firstLine[1]);

        // version of the record
        $secondLine = explode('#', $lines[1]);
        $this->version = intval($secondLine[1]);
        $lines = array_slice($lines, 2);

        // fields
        foreach ($lines as $line) {
            $field = new RecordField();
            $field->decode($line);
            array_push($this->fields, $field);
        }
    }

    /**
     * Получение значения поля (или подполя)
     * с указанной меткой (и указанным кодом).
     *
     * @param integer $tag Метка поля
     * @param string $code Код подполя
     * @return string|null
     */
    public function fm($tag, $code='') {
        foreach ($this->fields as $field) {
            if ($field->tag == $tag) {
                if ($code) {
                    foreach ($field->subfields as $subfield) {
                        if (strcasecmp($subfield->code, $code) == 0) {
                            return $subfield->value;
                        }
                    }
                } else {
                    return $field->value;
                }
            }
        }

        return null;
    }

    /**
     * Получение массива значений поля (или подполя)
     * с указанной меткой (и указанным кодом).
     *
     * @param integer $tag Искомая метка поля.
     * @param string $code Код подполя.
     * @return array
     */
    public function fma($tag, $code='') {
        $result = array();
        foreach ($this->fields as $field) {
            if ($field->tag == $tag) {
                if ($code) {
                    foreach ($field->subfields as $subfield) {
                        if (strcasecmp($subfield->code, $code) == 0) {
                            if ($subfield->value) {
                                array_push($result, $subfield->value);
                            }
                        }
                    }
                } else {
                    if ($field->value) {
                        array_push($result, $field->value);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Получение указанного поля (с учётом повторения).
     *
     * @param integer $tag Метка поля.
     * @param int $occurrence Номер повторения.
     * @return RecordField|null
     */
    public function getField($tag, $occurrence = 0) {
        foreach ($this->fields as $field) {
            if ($field->tag == $tag) {
                if (!$occurrence) {
                    return $field;
                }

                $occurrence--;
            }
        }

        return null;
    }

    /**
     * Получение массива полей с указанной меткой.
     *
     * @param integer $tag Искомая метка поля.
     * @return array
     */
    public function getFields($tag) {
        $result = array();
        foreach ($this->fields as $field) {
            if ($field->tag == $tag) {
                array_push($result, $field);
            }
        }

        return $result;
    }

    /**
     * @return bool Запись удалена
     * (неважно - логически или физически)?
     */
    public function isDeleted() {
        return boolval($this->status & 3);
    }

    public function __toString() {
        $result = $this->mfn . '#' . $this->status . "\x1F\x1E"
            . '0#' . $this->version . "\x1F\x1E";

        foreach ($this->fields as $field) {
            $result .= ($field . "\x1F\x1E");
        }

        return $result;
    }
}

/**
 * Запись в "неразобранном" виде.
 */
class RawRecord {
    /**
     * @var string Имя базы данных.
     */
    public $database = '';

    /**
     * @var string MFN.
     */
    public $mfn = '';

    /**
     * @var string Статус.
     */
    public $status = '';

    /**
     * @var string Версия.
     */
    public $version = '';

    /**
     * @var array Поля записи.
     */
    public $fields = array();

    /**
     * Декодирование ответа сервера.
     *
     * @param array $lines Массив строк
     * с клиентским представлением записи.
     */
    public function decode(array $lines) {
        // mfn and status of the record
        $firstLine = explode('#', $lines[0]);
        $this->mfn = intval($firstLine[0]);
        $this->status = intval($firstLine[1]);

        // version of the record
        $secondLine = explode('#', $lines[1]);
        $this->version = intval($secondLine[1]);
        $this->fields = array_slice($lines, 2);
    }
}

/**
 * Строка найденной записи.
 */
class FoundLine {
    /**
     * @var bool Материализована?
     */
    public $materialized = false;

    /**
     * @var int Порядковый номер.
     */
    public $serialNumber = 0;

    /**
     * @var int MFN.
     */
    public $mfn = 0;

    /**
     * @var null Иконка.
     */
    public $icon = null;

    /**
     * @var bool Выбрана (помечена).
     */
    public $selected = false;

    /**
     * @var string Библиографическое описание.
     */
    public $description = '';

    /**
     * @var string Ключ для сортировки.
     */
    public $sort = '';

    /**
     * Преобразование в массив MFN.
     *
     * @param array $found Найденные записи.
     * @return array Массив MFN.
     */
    public static function toMfn(array $found) {
        $result = array();
        foreach ($found as $item) {
            array_push($result, $item->mfn);
        }

        return $result;
    }
}

/**
 * Пара строк в меню.
 */
class MenuEntry {
    public $code, $comment;

    public function __toString() {
        return $this->code . ' - ' . $this->comment;
    }
}

/**
 * Файл меню. Состоит из пар строк (см. MenuEntry).
 */
class MenuFile {
    /**
     * @var array Массив пар строк.
     */
    public $entries = array();

    /**
     * Добавление элемента.
     *
     * @param string $code Код элемента.
     * @param string $comment Комментарий.
     * @return $this
     */
    public function add($code, $comment) {
        $entry = new MenuEntry();
        $entry->code = $code;
        $entry->comment = $comment;
        array_push($this->entries, $entry);

        return $this;
    }

    /**
     * Отыскивает запись, соответствующую данному коду.
     *
     * @param string $code
     * @return mixed|null
     */
    public function getEntry($code) {
        foreach ($this->entries as $entry) {
            if (strcasecmp($entry->code, $code) == 0) {
                return $entry;
            }
        }

        $code = trim($code);
        foreach ($this->entries as $entry) {
            if (strcasecmp($entry->code, $code) == 0) {
                return $entry;
            }
        }

        $code = self::trimCode($code);
        foreach ($this->entries as $entry) {
            if (strcasecmp($entry->code, $code) == 0) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Выдает значение, соответствующее коду.
     *
     * @param $code
     * @param string $defaultValue
     * @return string
     */
    public function getValue($code, $defaultValue='') {
        $entry = $this->getEntry($code);
        if (!$entry) {
            return $defaultValue;
        }

        return $entry->comment;
    }

    /**
     * Разбор серверного представления MNU-файла.
     *
     * @param array $lines Массив строк.
     */
    public function parse(array $lines) {
        $length = count($lines);
        for ($i=0; $i < $length; $i += 2) {
            $code = $lines[$i];
            if (!$code || substr($code, 5) == '*****') {
                break;
            }

            $comment = $lines[$i + 1];
            $entry = new MenuEntry();
            $entry->code = $code;
            $entry->comment = $comment;
            array_push($this->entries, $entry);
        }
    }

    /**
     * Отрезание лишних символов в коде.
     *
     * @param string $code Код.
     * @return string Очищенный код.
     */
    public static function trimCode($code) {
        $result = trim($code, '-=:');

        return $result;
    }

    public function __toString() {
        $result = '';

        foreach ($this->entries as $entry) {
            $result .= ($entry . PHP_EOL);
        }

        return $result;
    }
}

/**
 * Строка INI-файла. Состоит из ключа
 * и (опционального) значения.
 */
class IniLine {
    /**
     * @var string Ключ.
     */
    public $key;

    /**
     * @var string Значение.
     */
    public $value;

    public function __toString() {
        return $this->key . ' = ' . $this->value;
    }
}

/**
 * Секция INI-файла. Состоит из строк
 * (см. IniLine).
 */
class IniSection {
    /**
     * @var string Имя секции.
     */
    public $name = '';

    /**
     * @var array Строки 'ключ=значение'.
     */
    public $lines = array();

    /**
     * Поиск строки с указанным ключом.
     *
     * @param string $key Имя ключа.
     * @return IniLine|null
     */
    public function find($key) {
        foreach ($this->lines as $line) {
            if (strcasecmp($line->key, $key) == 0) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Получение значения для указанного ключа.
     *
     * @param string $key Имя ключа.
     * @param string $defaultValue Значение по умолчанию.
     * @return string Найденное значение или значение
     * по умолчанию.
     */
    public function getValue($key, $defaultValue = '') {
        $found = $this->find($key);
        return $found ? $found->value : $defaultValue;
    }

    /**
     * Удаление элемента с указанным ключом.
     *
     * @param string $key Имя ключа.
     * @return IniSection
     */
    public function remove($key) {
        // TODO implement

        return $this;
    }

    /**
     * Установка значения.
     *
     * @param string $key
     * @param string $value
     */
    public function setValue($key, $value) {
        $item = $this->find($key);
        if ($item) {
            $item->value = $value;
        } else {
            $item = new IniLine();
            $item->key = $key;
            $item->value = $value;
            array_push($this->lines, $item);
        }
    }

    public function __toString() {
        $result = '[' . $this->name . ']' . PHP_EOL;

        foreach ($this->lines as $line) {
            $result .= ($line . PHP_EOL);
        }

        return $result;
    }
}

/**
 * INI-файл. Состоит из секций (см. IniSection).
 */
class IniFile {
    /**
     * @var array Секции INI-файла.
     */
    public $sections = array();

    /**
     * Поиск секции с указанным именем.
     *
     * @param string $name Имя секции.
     * @return mixed|null
     */
    public function findSection($name) {
        foreach ($this->sections as $section) {
            if (strcasecmp($section->name, $name) == 0) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Поиск секции с указанным именем или создание
     * в случае её отсутствия.
     *
     * @param string $name Имя секции.
     * @return IniSection
     */
    public function getOrCreateSection($name) {
        $result = $this->findSection($name);
        if (!$result) {
            $result = new IniSection();
            $result->name = $name;
            array_push($this->sections, $result);
        }

        return $result;
    }

    /**
     * Получение значения (из одной из секций).
     *
     * @param string $sectionName Имя секции.
     * @param string $key Ключ искомого элемента.
     * @param string $defaultValue Значение по умолчанию.
     * @return string Значение найденного элемента
     * или значение по умолчанию.
     */
    public function getValue($sectionName, $key, $defaultValue = '') {
        $section = $this->findSection($sectionName);
        if ($section) {
            return $section->getValue($key, $defaultValue);
        }

        return $defaultValue;
    }

    /**
     * Разбор текстового представления INI-файла.
     *
     * @param array $lines Строки INI-файла.
     */
    public function parse(array $lines) {
        $section = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (isNullOrEmpty($trimmed)) {
                continue;
            }

            if ($trimmed[0] == '[') {
                $name = substr($trimmed, 1, strlen($trimmed) - 2);
                $section = new IniSection();
                $section->name = $name;
                array_push($this->sections, $section);
            } else if ($section) {
                $parts = explode('=', $trimmed, 2);
                $key = $parts[0];
                $value = $parts[1];
                $item = new IniLine();
                $item->key = $key;
                $item->value = $value;
                array_push($section->lines, $item);
            }
        }
    }

    /**
     * Установка значения элемента (в одной из секций).
     *
     * @param string $sectionName Имя секции.
     * @param string $key Ключ элемента.
     * @param string $value Значение элемента.
     * @return $this
     */
    public function setValue($sectionName, $key, $value) {
        $section = $this->getOrCreateSection($sectionName);
        $section->setValue($key, $value);

        return $this;
    }

    public function __toString() {
        $result = '';
        $first = true;

        foreach ($this->sections as $section) {
            if (!$first) {
                $result .= PHP_EOL;
            }

            $result .= $section;

            $first = false;
        }

        return $result;
    }
}

/**
 * Информация о базе данных ИРБИС.
 */
class DatabaseInfo {
    /**
     * @var string Имя базы данных.
     */
    public $name = '';

    /**
     * @var string Описание базы данных.
     */
    public $description = '';

    /**
     * @var int Максимальный MFN.
     */
    public $maxMfn = 0;

    /**
     * @var array Логически удалённые записи.
     */
    public $logicallyDeletedRecords = array();

    /**
     * @var array Физически удалённые записи.
     */
    public $physicallyDeletedRecords = array();

    /**
     * @var array Неактуализированные записи.
     */
    public $nonActualizedRecords = array();

    /**
     * @var array Заблокированные записи.
     */
    public $lockedRecords = array();

    /**
     * @var bool Признак блокировки базы данных в целом.
     */
    public $databaseLocked = false;

    /**
     * @var bool База только для чтения.
     */
    public $readOnly = false;

    static function parseLine($line) {
        $result = array();
        $items = explode("\x1E", $line);
        foreach ($items as $item) {
            array_push($result, intval($item));
        }

        return $result;
    }

    /**
     * Разбор ответа сервера (см. getDatabaseInfo).
     *
     * @param array $lines Ответ сервера.
     * @return DatabaseInfo
     */
    public static function parseResponse(array $lines) {
        $result = new DatabaseInfo();
        $result->logicallyDeletedRecords = self::parseLine($lines[0]);
        $result->physicallyDeletedRecords = self::parseLine($lines[1]);
        $result->nonActualizedRecords = self::parseLine($lines[2]);
        $result->lockedRecords = self::parseLine($lines[3]);
        $result->maxMfn = intval($lines[4]);
        $result->databaseLocked = intval($lines[5]) != 0;


        return $result;
    }

    /**
     * Получение списка баз данных из MNU-файла.
     *
     * @param MenuFile $menu Меню.
     * @return array
     */
    public static function parseMenu(MenuFile $menu) {
        $result = array();
        foreach ($menu->entries as $entry) {
            $name = $entry->code;
            $description = $entry->comment;
            $readOnly = false;
            if ($name[0] == '-') {
                $name = substr($name, 1);
                $readOnly = true;
            }

            $db = new DatabaseInfo();
            $db->name = $name;
            $db->description = $description;
            $db->readOnly = $readOnly;
            array_push($result, $db);
        }

        return $result;
    }

    public function __toString() {
        return $this->name;
    }
}

/**
 * Информация о запущенном на ИРБИС-сервере процессе.
 */
class ProcessInfo {
    /**
     * @var string Просто порядковый номер в списке.
     */
    public $number = '';

    /**
     * @var string С каким клиентом взаимодействует.
     */
    public $ipAddress = '';

    /**
     * @var string Логин оператора.
     */
    public $name = '';

    /**
     * @var string Идентификатор клиента.
     */
    public $clientId = '';

    /**
     * @var string Тип АРМ.
     */
    public $workstation = '';

    /**
     * @var string Время запуска.
     */
    public $started = '';

    /**
     * @var string Последняя выполненная
     * (или выполняемая) команда.
     */
    public $lastCommand = '';

    /**
     * @var string Порядковый номер последней команды.
     */
    public $commandNumber = '';

    /**
     * @var string Индентификатор процесса.
     */
    public $processId = '';

    /**
     * @var string Состояние.
     */
    public $state = '';

    public static function parse(array $lines) {
        $result = array();
        $processCount = intval($lines[0]);
        $linesPerProcess = intval($lines[1]);
        if (!$processCount || !$linesPerProcess) {
            return $result;
        }

        $lines = array_slice($lines, 2);
        for($i = 0; $i < $processCount; $i++) {
            $process = new ProcessInfo();
            $process->number        = $lines[0];
            $process->ipAddress     = $lines[1];
            $process->name          = $lines[2];
            $process->clientId      = $lines[3];
            $process->workstation   = $lines[4];
            $process->started       = $lines[5];
            $process->lastCommand   = $lines[6];
            $process->commandNumber = $lines[7];
            $process->processId     = $lines[8];
            $process->state         = $lines[9];

            array_push($result, $process);
            $lines = array_slice($lines, $linesPerProcess);
        }

        return $result;
    }

    public function __toString() {
        return "{$this->number} {$this->ipAddress} {$this->name}";
    }
}

/**
 * Информация о версии ИРБИС-сервера.
 */
class VersionInfo {
    /**
     * @var string На какое юридическое лицо приобретен сервер.
     */
    public $organization = '';

    /**
     * @var string Собственно версия сервера. Например, 64.2008.1
     */
    public $version = '';

    /**
     * @var int Максимальное количество подключений.
     */
    public $maxClients = 0;

    /**
     * @var int Текущее количество подключений.
     */
    public $connectedClients = 0;

    /**
     * Разбор ответа сервера.
     *
     * @param array $lines Строки с ответом сервера.
     */
    public function parse(array $lines) {
        if (count($lines) == 3) {
            $this->version = $lines[0];
            $this->connectedClients = intval($lines[1]);
            $this->maxClients = intval($lines[2]);
        } else {
            $this->organization = $lines[0];
            $this->version = $lines[1];
            $this->connectedClients = intval($lines[2]);
            $this->maxClients = intval($lines[3]);
        }
    }

    public function __toString() {
        return $this->version;
    }
}

/**
 * Информация о клиенте, подключенном к серверу ИРБИС
 * (не обязательно о текущем).
 */
class ClientInfo {
    /**
     * @var string Порядковый номер.
     */
    public $number = '';

    /**
     * @var string Адрес клиента.
     */
    public $ipAddress = '';

    /**
     * @var string Порт клиента.
     */
    public $port = '';

    /**
     * @var string Логин.
     */
    public $name = '';

    /**
     * @var string Идентификатор клиентской программы
     * (просто уникальное число).
     */
    public $id = '';

    /**
     * @var string Клиентский АРМ.
     */
    public $workstation = '';

    /**
     * @var string Момент подключения к серверу.
     */
    public $registered = '';

    /**
     * @var string Последнее подтверждение,
     * посланное серверу.
     */
    public $acknowledged = '';

    /**
     * @var string Последняя команда, посланная серверу.
     */
    public $lastCommand = '';

    /**
     * @var string Номер последней команды.
     */
    public $commandNumber = '';

    /**
     * Разбор ответа сервера.
     *
     * @param array $lines Строки ответа.
     */
    public function parse(array $lines) {
        $this->number        = $lines[0];
        $this->ipAddress     = $lines[1];
        $this->port          = $lines[2];
        $this->name          = $lines[3];
        $this->id            = $lines[4];
        $this->workstation   = $lines[5];
        $this->registered    = $lines[6];
        $this->acknowledged  = $lines[7];
        $this->lastCommand   = $lines[8];
        $this->commandNumber = $lines[9];
    }

    public function __toString() {
        return $this->ipAddress;
    }
}

/**
 * Информация о зарегистрированном пользователе системы
 * (по данным client_m.mnu).
 */
class UserInfo {
    /**
     * @var string Номер по порядку в списке.
     */
    public $number = '';

    /**
     * @var string Логин.
     */
    public $name = '';

    /**
     * @var string Пароль.
     */
    public $password = '';

    /**
     * @var string Доступность АРМ Каталогизатор.
     */
    public $cataloger = '';

    /**
     * @var string АРМ Читатель.
     */
    public $reader = '';

    /**
     * @var string АРМ Книговыдача.
     */
    public $circulation = '';

    /**
     * @var string АРМ Комплектатор.
     */
    public $acquisitions = '';

    /**
     * @var string АРМ Книгообеспеченность.
     */
    public $provision = '';

    /**
     * @var string АРМ Администратор.
     */
    public $administrator = '';

    public static function formatPair($prefix, $value, $default) {
        if (sameString($value, $default)) {
            return '';
        }

        return $prefix . '=' . $value . ';';
    }

    /**
     * Формирование строкового представления пользователя.
     *
     * @return string
     */
    public function encode() {
        return $this->name . "\r\n"
            . $this->password . "\r\n"
            . self::formatPair('C', $this->cataloger,     'irbisc.ini')
            . self::formatPair('R', $this->reader,        'irbisr.ini')
            . self::formatPair('B', $this->circulation,   'irbisb.ini')
            . self::formatPair('M', $this->acquisitions,  'irbism.ini')
            . self::formatPair('K', $this->provision,     'irbisk.ini')
            . self::formatPair('A', $this->administrator, 'irbisa.ini');
    }

    /**
     * Разбор ответа сервера.
     *
     * @param array $lines Строки ответа сервера.
     * @return array
     */
    public static function parse(array $lines) {
        $result = array();
        $userCount = intval($lines[0]);
        $linesPerUser = intval($lines[1]);
        if (!$userCount || !$linesPerUser) {
            return $result;
        }

        $lines = array_slice($lines, 2);
        for($i = 0; $i < $userCount; $i++) {
            if (!$lines) {
                break;
            }

            $user = new UserInfo();
            $user->number        = $lines[0];
            $user->name          = $lines[1];
            $user->password      = $lines[2];
            $user->cataloger     = $lines[3];
            $user->reader        = $lines[4];
            $user->circulation   = $lines[5];
            $user->acquisitions  = $lines[6];
            $user->provision     = $lines[7];
            $user->administrator = $lines[8];
            array_push($result, $user);

            $lines = array_slice($lines, $linesPerUser + 1);
        }

        return $result;
    }

    public function __toString() {
        return $this->name;
    }
}

/**
 * Данные для команды TableCommand
 */
class TableDefinition {
    public $database;
    public $table;
    public $headers = array();
    public $mode;
    public $searchQuery;
    public $minMfn;
    public $maxMfn;
    public $sequentialQuery;
    public $mfnList;

    public function __toString() {
        return $this->table;
    }
}

/**
 * Статистика работы ИРБИС-сервера.
 */
class ServerStat {
    /**
     * @var array Подключенные клиенты.
     */
    public $runningClients = array();

    /**
     * @var int Число клиентов, подключенных в текущий момент.
     */
    public $clientCount = 0;

    /**
     * @var int Общее количество команд,
     * исполненных сервером с момента запуска.
     */
    public $totalCommandCount = 0;

    public function parse(array $lines) {
        $this->totalCommandCount = intval($lines[0]);
        $this->clientCount = intval($lines[1]);
        $linesPerClient = intval($lines[2]);
        if (!$linesPerClient) {
            return;
        }

        $lines = array_slice($lines, 3);

        for($i=0; $i < $this->clientCount; $i++) {
            $client = new ClientInfo();
            $client->parse($lines);
            array_push($this->runningClients, $client);
            $lines = array_slice($lines, $linesPerClient + 1);
        }
    }

    public function __toString() {
        // TODO implement
        return '';
    }
}

/**
 * Параметры для запроса постингов с сервера.
 */
class PostingParameters {
    /**
     * @var string База данных.
     */
    public $database = '';

    /**
     * @var int Номер первого постинга.
     */
    public $firstPosting = 1;

    /**
     * @var string Формат.
     */
    public $format = '';

    /**
     * @var int Требуемое количество постингов.
     */
    public $numberOfPostings = 0;

    /**
     * @var string Терм.
     */
    public $term = '';

    /**
     * @var array Список термов.
     */
    public $listOfTerms = array();
}

/**
 * Параметры для запроса термов с сервера.
 */
class TermParameters {
    /**
     * @var string Имя базы данных.
     */
    public $database = '';

    /**
     * @var int Количество считываемых термов.
     */
    public $numberOfTerms = 0;

    /**
     * @var bool Возвращать в обратном порядке.
     */
    public $reverseOrder = false;

    /**
     * @var string Начальный терм.
     */
    public $startTerm = '';

    /**
     * @var string Формат.
     */
    public $format = '';
}

/**
 * Информация о термине поискового словаря.
 */
class TermInfo {
    /**
     * @var int Количество ссылок.
     */
    public $count = 0;

    /**
     * @var string Поисковый термин.
     */
    public $text = '';

    public static function parse(array $lines) {
        $result = array();
        foreach ($lines as $line) {
            if (!isNullOrEmpty($line)) {
                $parts = explode('#', $line, 2);
                $term = new TermInfo();
                $term->count = intval($parts[0]);
                $term->text = $parts[1];
                array_push($result, $term);
            }
        }

        return $result;
    }

    public function __toString() {
        return $this->text
            ? $this->count . '#' . $this->text
            : $this->count;
    }
}

/**
 * Постинг термина в поисковом индексе.
 */
class TermPosting {
    /**
     * @var int MFN записи с искомым термином.
     */
    public $mfn = 0;

    /**
     * @var int Метка поля с искомым термином.
     */
    public $tag = 0;

    /**
     * @var int Повторение поля.
     */
    public $occurrence = 0;

    /**
     * @var int Количество повторений.
     */
    public $count = 0;

    /**
     * @var string Результат форматирования.
     */
    public $text = '';

    /**
     * Разбор ответа сервера.
     *
     * @param array $lines Строки ответа.
     * @return array Массив постингов.
     */
    public static function parse(array $lines) {
        $result = array();
        foreach ($lines as $line) {
            $parts = explode('#', $line, 5);
            if (count($parts) < 4) {
                break;
            }

            $item = new TermPosting();
            $item->mfn        = intval($parts[0]);
            $item->tag        = intval($parts[1]);
            $item->occurrence = intval($parts[2]);
            $item->count      = intval($parts[3]);
            $item->text       = $parts[4];
            array_push($result, $item);
        }

        return $result;
    }

    public function __toString() {
        return $this->mfn . '#' . $this->tag . '#'
            . $this->occurrence . '#' . $this->count
            . '#' . $this->text;
    }
}

/**
 * Параметры для поиска записей.
 */
class SearchParameters {
    /**
     * @var string Имя базы данных.
     */
    public $database = '';

    /**
     * @var int Индекс первой требуемой записи.
     */
    public $firstRecord = 1;

    /**
     * @var string Формат для расформатирования записей.
     */
    public $format = '';

    /**
     * @var int Максимальный MFN.
     */
    public $maxMfn = 0;

    /**
     * @var int Минимальный MFN.
     */
    public $minMfn = 0;

    /**
     * @var int Общее число требуемых записей.
     */
    public $numberOfRecords = 0;

    /**
     * @var string Выражение для поиска по словарю.
     */
    public $expression = '';

    /**
     * @var string Выражение для последовательного поиска.
     */
    public $sequential = '';

    /**
     * @var string Выражение для локальной фильтрации.
     */
    public $filter = '';

    /**
     * @var bool Признак кодировки UTF-8.
     */
    public $isUtf = false;

    /**
     * @var bool Признак вложенного вызова.
     */
    public $nested = false;
}

/**
 * Сценарий поиска.
 */
class SearchScenario {
    /**
     * @var string Название поискового атрибута
     * (автор, инвентарный номер).
     */
    public $name = '';

    /**
     * @var string Префикс соответствующих терминов
     * в словаре (может быть пустым).
     */
    public $prefix = '';

    /**
     * @var int Тип словаря для соответствующего поиска.
     */
    public $dictionaryType = 0;

    /**
     * @var string Имя файла справочника.
     */
    public $menuName = '';

    /**
     * @var string Имя формата (без расширения).
     */
    public $oldFormat = '';

    /**
     * @var string Способ корректировки по словарю.
     */
    public $correction = '';

    /**
     * @var string Исходное положение переключателя "Усечение".
     */
    public $truncation = '';

    /**
     * @var string Текст подсказки/предупреждения.
     */
    public $hint = '';

    /**
     * @var string Параметр пока не задействован.
     */
    public $modByDicAuto = '';

    /**
     * @var string Применимые логические операторы.
     */
    public $logic = '';

    /**
     * @var string Правила автоматического расширения поиска
     * на основе авторитетного файла или тезауруса.
     */
    public $advance = '';

    /**
     * @var string Имя формата показа документов.
     */
    public $format = '';

    static function get(IniSection $section, $name, $index) {
        $fullName = 'Item' . $name . $index;
        return $section->getValue($fullName);
    }

    /**
     * Разбор INI-файла.
     *
     * @param IniFile $iniFile
     * @return array
     */
    public static function parse(IniFile $iniFile) {
        $result = array();
        $section = $iniFile->findSection('SEARCH');
        if ($section) {
            $count = intval($section->getValue('ItemNumb'));
            for($i=0; $i < $count; $i++) {
                $scenario = new SearchScenario();
                $scenario->name = self::get($section, "Name", $i);
                $scenario->prefix = self::get($section, "Pref", $i);
                $scenario->dictionaryType = intval(self::get($section, "DictionType", $i));
                $scenario->menuName = self::get($section, "Menu", $i);
                $scenario->oldFormat = '';
                $scenario->correction = self::get($section, "ModByDic", $i);
                $scenario->truncation = self::get($section, "Tranc", $i);
                $scenario->hint = self::get($section, "Hint", $i);
                $scenario->modByDicAuto = self::get($section, "ModByDicAuto", $i);
                $scenario->logic = self::get($section, "Logic", $i);
                $scenario->advance = self::get($section, "Adv", $i);
                $scenario->format = self::get($section, "Pft", $i);
            }
        }

        return $result;
    }
}

/**
 * Клиентский запрос.
 */
class ClientQuery {
    private $accumulator = '';

    public function __construct(IrbisConnection $connection, $command) {
        $this->addAnsi($command)->newLine();
        $this->addAnsi($connection->arm)->newLine();
        $this->addAnsi($command)->newLine();
        $this->addAnsi($connection->clientId)->newLine();
        $this->addAnsi($connection->queryId)->newLine();
        $this->addAnsi($connection->password)->newLine();
        $this->addAnsi($connection->username)->newLine();
        $this->newLine();
        $this->newLine();
        $this->newLine();
    }

    public function add($value) {
        $this->addAnsi(strval($value));

        return $this;
    }

    public function addAnsi($value) {
        $converted = mb_convert_encoding($value, 'Windows-1251');
        $this->accumulator .= $converted;

        return $this;
    }

    public function addUtf($value) {
        $this->accumulator .= $value;

        return $this;
    }

    public function newLine() {
        $this->accumulator .= chr(10);

        return $this;
    }

    public function __toString() {
        return strlen($this->accumulator) . chr(10) . $this->accumulator;
    }
}

/**
 * Ответ сервера.
 */
class ServerResponse {
    public $command = '';
    public $clientId = 0;
    public $queryId = 0;
    public $returnCode = 0;

    private $answer;
    private $offset;
    private $answerLength;

    public function __construct($socket) {
        $this->answer = '';
        while ($buf = socket_read($socket, 2048)) {
            $this->answer .= $buf;
        }
        $this->offset = 0;
        $this->answerLength = strlen($this->answer);

        $this->command = $this->readAnsi();
        $this->clientId = $this->readInteger();
        $this->queryId = $this->readInteger();
        for ($i=0; $i < 7; $i++) {
            $this->readAnsi();
        }
    }

    /**
     * Проверка кода возврата.
     *
     * @param array $goodCodes Разрешенные коды возврата.
     * @throws Exception
     */
    public function checkReturnCode(array $goodCodes=array()) {
        if ($this->getReturnCode() < 0) {
            if (!in_array($this->returnCode, $goodCodes)) {
                throw new IrbisException(describeError($this->returnCode),$this->returnCode);
            }
        }
    }

    public function getLine() {
        $result = '';
        while ($this->offset < $this->answerLength) {
            $symbol = $this->answer[$this->offset];
            $this->offset++;

            if ($symbol == chr(13)) {
                if ($this->answer[$this->offset] == chr(10)) {
                    $this->offset++;
                }
                break;
            }

            $result .= $symbol;
        }

        return $result;
    }

    public function getReturnCode() {
        $this->returnCode = $this->readInteger();
        return $this->returnCode;
    }

    public function readAnsi() {
        $result = $this->getLine();
        $result = mb_convert_encoding($result, 'UTF-8', 'Windows-1251');

        return $result;
    }

    public function readInteger() {
        $line = $this->getLine();

        return intval($line);
    }

    public function readRemainingAnsiLines() {
        $result = array();

        while($this->offset < $this->answerLength) {
            $line = $this->readAnsi();
            array_push($result, $line);
        }

        return $result;
    }

    public function readRemainingAnsiText() {
        $result = substr($this->answer, $this->offset);
        $result = mb_convert_encoding($result, mb_internal_encoding(), 'Windows-1251');

        return $result;
    }

    public function readRemainingUtfLines() {
        $result = array();

        while($this->offset < $this->answerLength) {
            $line = $this->readUtf();
            array_push($result, $line);
        }

        return $result;
    }

    public function readRemainingUtfText() {
        $result = substr($this->answer, $this->offset);

        return $result;
    }

    public function readUtf() {
        return $this->getLine();
    }
}

/**
 * Подключение к ИРБИС-серверу.
 */
class IrbisConnection {
    public $host = '127.0.0.1', $port = 6666;
    public $username = '', $password = '';
    public $database = 'IBIS', $arm = 'C';
    public $clientId = 0;
    public $queryId = 0;

    private $connected = false;

    //================================================================

    /**
     * Актуализация записи с указанным MFN.
     *
     * @param string $database Имя базы данных.
     * @param integer $mfn MFN, подлежащий актуализации.
     * @return bool
     * @throws Exception
     */
    public function actualizeRecord($database, $mfn) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'F');
        $query->addAnsi($database)->newLine();
        $query->add($mfn)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();

        return true;
    }

    /**
     * Подключение к серверу ИРБИС64.
     *
     * @return bool
     * @throws Exception
     */
    function connect() {
        if ($this->connected) {
            return true;
        }

    AGAIN:
        $this->clientId = rand(100000, 900000);
        $this->queryId = 1;
        $query = new ClientQuery($this, 'A');
        $query->addAnsi($this->username)->newLine();
        $query->addAnsi($this->password);

        $response = $this->execute($query);
        $response->getReturnCode();
        if ($response->returnCode == -3337) {
            goto AGAIN;
        }

        if ($response->returnCode < 0) {
            return false;
        }

        $this->connected = true;

        return true;
    }

    /**
     * Создание базы данных.
     *
     * @param string $database Имя создаваемой базы.
     * @param string $description Описание в свободной форме.
     * @param int $readerAccess Читатель будет иметь доступ?
     * @return bool
     * @throws Exception
     */
    function createDatabase($database, $description, $readerAccess=1) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'T');
        $query->addAnsi($database)->newLine();
        $query->addAnsi($description)->newLine();
        $query->add($readerAccess)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();

        return true;
    }

    /**
     * Создание словаря в указанной базе данных.
     *
     * @param string $database Имя базы данных.
     * @return bool
     * @throws Exception
     */
    public function createDictionary($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'Z');
        $query->addAnsi($database)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();

        return true;
    }

    /**
     * Удаление указанной базы данных.
     *
     * @param string $database Имя удаляемой базы данных.
     * @return bool
     * @throws Exception
     */
    public function deleteDatabase($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'W');
        $query->addAnsi($database)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();

        return true;
    }

    /**
     * Удаление записи по её MFN.
     *
     * @param integer $mfn MFN удаляемой записи.
     * @throws Exception
     */
    public function deleteRecord($mfn) {
        $record = $this->readRecord($mfn);
        $record->status |= 1;
        $this->writeRecord($record);
    }

    /**
     * Отключение от сервера.
     *
     * @return bool
     */
    public function disconnect() {
        if (!$this->connected) {
            return true;
        }

        $query = new ClientQuery($this, 'B');
        $query->addAnsi($this->username);
        $this->execute($query);
        $this->connected = false;

        return true;
    }

    /**
     * Отправка клиентского запроса на сервер
     * и получение ответа от него.
     *
     * @param ClientQuery $query Клиентский запрос.
     * @return bool|ServerResponse Ответ сервера.
     */
    public function execute(ClientQuery $query) {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }

        if (!socket_connect($socket, $this->host, $this->port)) {
            socket_close($socket);
            return false;
        }

        $packet = strval($query);
        socket_write($socket, $packet, strlen($packet));
        $response = new ServerResponse($socket);
        $this->queryId++;

        return $response;
    }

    /**
     * Форматирование записи с указанным MFN.
     *
     * @param string $format Текст формата
     * @param integer $mfn MFN записи
     * @return bool|string
     * @throws Exception
     */
    public function formatRecord($format, $mfn) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'G');
        $query->addAnsi($this->database)->newLine();
        $prepared = prepareFormat($format);
        $query->addAnsi($prepared)->newLine();
        $query->add(1)->newLine();
        $query->add($mfn)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();
        $result = $response->readRemainingUtfText();

        return $result;
    }

    /**
     * Получение информации о базе данных.
     *
     * @param string $database Имя базы данных.
     * @return bool|DatabaseInfo
     * @throws Exception
     */
    public function getDatabaseInfo($database = '') {
        if (!$this->connected) {
            return false;
        }

        if (isNullOrEmpty($database)) {
            $database = $this->database;
        }

        $query = new ClientQuery($this, '0');
        $query->addAnsi($database);
        $response = $this->execute($query);
        $response->checkReturnCode();
        $lines = $response->readRemainingAnsiLines();
        $result = DatabaseInfo::parseResponse($lines);

        return $result;
    }

    /**
     * Получение максимального MFN для указанной базы данных.
     *
     * @param string $database Имя базы данных.
     * @return bool|integer
     * @throws Exception
     */
    public function getMaxMfn($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'O');
        $query->addAnsi($database);
        $response = $this->execute($query);
        $response->checkReturnCode();

        return $response->returnCode;
    }

    /**
     * Получение статистики с сервера.
     *
     * @return bool|ServerStat
     * @throws Exception
     */
    public function getServerStat() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '+1');
        $response = $this->execute($query);
        $response->checkReturnCode();
        $result = new ServerStat();
        $result->parse($response->readRemainingAnsiLines());

        return $result;
    }

    /**
     * Получение версии сервера.
     *
     * @return bool|VersionInfo
     * @throws Exception
     */
    public function getServerVersion() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '1');
        $response = $this->execute($query);
        $response->checkReturnCode();
        $result = new VersionInfo();
        $result->parse($response->readRemainingAnsiLines());

        return $result;
    }

    /**
     * Получение списка пользователей с сервера.
     *
     * @return array|bool
     * @throws Exception
     */
    public function getUserList() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '+9');
        $response = $this->execute($query);
        $response->checkReturnCode();
        $result = UserInfo::parse($response->readRemainingAnsiLines());

        return $result;
    }

    /**
     * Получение списка баз данных с сервера.
     *
     * @param string $specification Спецификация файла со списком баз.
     * @return array|bool
     */
    public function listDatabases($specification = '1..dbnam2.mnu') {
        if (!$this->connected) {
            return false;
        }

        $menu = $this->readMenuFile($specification);
        $result = DatabaseInfo::parseMenu($menu);

        return $result;
    }

    /**
     * Получение списка файлов.
     *
     * @param string $specification Спецификация.
     * @return array|bool
     */
    public function listFiles($specification) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '!');
        $query->addAnsi($specification)->newLine();
        $response = $this->execute($query);

        $lines = $response->readRemainingAnsiLines();
        $result = array();
        foreach ($lines as $line) {
            $files = irbisToLines($line);
            foreach ($files as $file) {
                if (!isNullOrEmpty($file)) {
                    array_push($result, $file);
                }
            }
        }

        return $result;
    }

    /**
     * Получение списка серверных процессов.
     *
     * @return array|bool
     * @throws Exception
     */
    public function listProcesses() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '+3');
        $response = $this->execute($query);
        $response->checkReturnCode();
        $lines = $response->readRemainingAnsiLines();
        $result = ProcessInfo::parse($lines);

        return $result;
    }

    /**
     * Пустая операция (используется для периодического
     * подтверждения подключения клиента).
     *
     * @return bool
     */
    public function noOp() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'N');
        $this->execute($query);

        return true;
    }

    /**
     * Разбор строки подключения.
     *
     * @param string $connectionString Строка подключения.
     * @throws IrbisException
     */
    public function parseConnectionString($connectionString) {
        $items = explode(';', $connectionString);
        foreach ($items as $item) {
            $parts = explode('=', $item, 2);
            if (count($parts) != 2) {
                throw new IrbisException();
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            switch ($name) {
                case 'host':
                case 'server':
                case 'address':
                    $this->host = $value;
                    break;

                case 'port':
                    $this->port = intval($value);
                    break;

                case 'user':
                case 'username':
                case 'name':
                case 'login':
                    $this->username = $value;
                    break;

                case 'pwd':
                case 'password':
                    $this->password = $value;
                    break;

                case 'db':
                case 'database':
                case 'catalog':
                    $this->database = $value;
                    break;

                case 'arm':
                case 'workstation':
                    $this->arm = $value;
                    break;

                case 'debug':
                    // TODO implement
                    break;

                default:
                    throw new IrbisException();
            }
        }
    }

    /**
     * Расформатирование таблицы.
     *
     * @param TableDefinition $definition Определение таблицы.
     * @return bool|string
     */
    public function printTable (TableDefinition $definition) {
        if (!$this->connected) {
            return false;
        }

        $database = getOne($definition->database, $this->database);

        $query = new ClientQuery($this, '7');
        $query->addAnsi($database)->newLine();
        $query->addAnsi($definition->table)->newLine();
        $query->addAnsi('')->newLine(); // вместо заголовков
        $query->addAnsi($definition->mode)->newLine();
        $query->addAnsi($definition->searchQuery)->newLine();
        $query->add($definition->minMfn)->newLine();
        $query->add($definition->maxMfn)->newLine();
        $query->addUtf($definition->sequentialQuery)->newLine();
        $query->addAnsi(''); // вместо перечня MFN
        $response = $this->execute($query);
        $result = $response->readRemainingUtfText();

        return $result;
    }

    /**
     * Получение INI-файла с сервера.
     *
     * @param string $specification Спецификация файла.
     * @return IniFile|null
     */
    public function readIniFile($specification) {
        $text = $this->readTextFile($specification);
        if (isNullOrEmpty($text)) {
            return null;
        }

        $lines = explode("\n", $text);
        $result = new IniFile();
        $result->parse($lines);

        return $result;
    }

    /**
     * Чтение MNU-файла с сервера.
     *
     * @param string $specification Спецификация файла.
     * @return bool|MenuFile
     */
    public function readMenuFile($specification) {
        $text = $this->readTextFile($specification);
        if (!$text) {
            return false;
        }

        $lines = explode("\n", $text);
        $result = new MenuFile();
        $result->parse($lines);

        return $result;
    }

    /**
     * Считывание постингов из поискового индекса.
     *
     * @param PostingParameters $parameters Параметры постингов.
     * @return array|bool Массив постингов.
     * @throws Exception
     */
    public function readPostings(PostingParameters $parameters) {
        if (!$this->connected) {
            return false;
        }

        $database = getOne($parameters->database, $this->database);

        $query = new ClientQuery($this, 'I');
        $query->addAnsi($database)->newLine();
        $query->add($parameters->numberOfPostings)->newLine();
        $query->add($parameters->firstPosting)->newLine();
        $query->addAnsi($parameters->format)->newLine();
        if (!$parameters->listOfTerms) {
            $query->addUtf($parameters->term)->newLine();
        } else {
            foreach ($parameters->listOfTerms as $term) {
                $query->addUtf($term)->newLine();
            }
        }

        $response = $this->execute($query);
        $response->checkReturnCode(readTermCodes());
        $lines = $response->readRemainingUtfLines();
        $result = TermPosting::parse($lines);

        return $result;
    }

    /**
     * Чтение указанной записи в "сыром" виде.
     *
     * @param string $mfn MFN записи
     * @return bool|RawRecord
     * @throws Exception
     */
    public function readRawRecord($mfn) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'C');
        $query->addAnsi($this->database)->newLine();
        $query->add($mfn)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode(readRecordCodes());
        $result = new RawRecord();
        $result->decode($response->readRemainingUtfLines());
        $result->database = $this->database;

        return $result;
    }

    /**
     * Чтение указанной записи.
     *
     * @param integer $mfn MFN записи
     * @return bool|MarcRecord
     * @throws Exception
     */
    public function readRecord($mfn) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'C');
        $query->addAnsi($this->database)->newLine();
        $query->add($mfn)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode(readRecordCodes());
        $result = new MarcRecord();
        $result->decode($response->readRemainingUtfLines());
        $result->database = $this->database;

        return $result;
    }

    /**
     * Загрузка сценариев поиска с сервера.
     *
     * @param string $specification Спецификация.
     * @return array|bool
     */
    public function readSearchScenario($specification) {
        if (!$this->connected) {
            return false;
        }

        $iniFile = $this->readIniFile($specification);
        if (!$iniFile) {
            return false;
        }

        $result = SearchScenario::parse($iniFile);

        return $result;
    }

    /**
     * Простое получение терминов поискового словаря.
     *
     * @param string $startTerm Начальный термин.
     * @param int $numberOfTerms Необходимое количество терминов.
     * @return array|bool
     * @throws Exception
     */
    public function readTerms($startTerm, $numberOfTerms=100) {
        $parameters = new TermParameters();
        $parameters->startTerm = $startTerm;
        $parameters->numberOfTerms = $numberOfTerms;

        return $this->readTermsEx($parameters);
    }

    /**
     * Получение термов поискового словаря.
     *
     * @param TermParameters $parameters Параметры термов.
     * @return array|bool
     * @throws Exception
     */
    public function readTermsEx(TermParameters $parameters) {
        if (!$this->connected) {
            return false;
        }

        $command = $parameters->reverseOrder ? 'P' : 'H';
        $database = $parameters->database;
        if (isNullOrEmpty($database)) {
            $database = $this->database;
        }

        $query = new ClientQuery($this, $command);
        $query->addAnsi($database)->newLine();
        $query->addUtf($parameters->startTerm)->newLine();
        $query->add($parameters->numberOfTerms)->newLine();
        $query->addAnsi($parameters->format)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode(readTermCodes());
        $lines = $response->readRemainingUtfLines();
        $result = TermInfo::parse($lines);

        return $result;
    }

    /**
     * Получение текстового файла с сервера.
     *
     * @param string $specification Спецификация файла.
     * @return bool|string
     */
    public function readTextFile($specification) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'L');
        $query->addAnsi($specification)->newLine();
        $response = $this->execute($query);
        $result = $response->readAnsi();
        $result = irbisToDos($result);

        return $result;
    }

    /**
     * Пересоздание словаря.
     *
     * @param string $database База данных.
     * @return bool
     */
    public function reloadDictionary($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'Y');
        $query->addAnsi($database)->newLine();
        $this->execute($query);

        return true;
    }

    /**
     * Пересоздание мастер-файла.
     *
     * @param string $database База данных.
     * @return bool
     */
    public function reloadMasterFile($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'X');
        $query->addAnsi($database)->newLine();
        $this->execute($query);

        return true;
    }

    /**
     * Перезапуск сервера (без утери подключенных клиентов).
     *
     * @return bool
     */
    public function restartServer() {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '+8');
        $this->execute($query);

        return true;
    }

    /**
     * Простой поиск записей.
     *
     * @param string $expression Выражение для поиска по словарю.
     * @return array|bool
     * @throws Exception
     */
    public function search($expression) {
        $parameters = new SearchParameters();
        $parameters->expression = $expression;

        return $this->searchEx($parameters);
    }

    /**
     * Поиск записей.
     *
     * @param SearchParameters $parameters Параметры поиска.
     * @return array|bool
     * @throws Exception
     */
    public function searchEx(SearchParameters $parameters) {
        if (!$this->connected) {
            return false;
        }

        $database = $parameters->database;
        if (isNullOrEmpty($database)) {
            $database = $this->database;
        }

        $query = new ClientQuery($this, 'K');
        $query->addAnsi($database)->newLine();
        $query->addUtf($parameters->expression)->newLine();
        $query->add($parameters->numberOfRecords)->newLine();
        $query->add($parameters->firstRecord)->newLine();
        $prepared = prepareFormat($parameters->format);
        $query->addAnsi($prepared)->newLine();
        $query->addAnsi($parameters->minMfn)->newLine();
        $query->addAnsi($parameters->maxMfn)->newLine();
        $query->addAnsi($parameters->sequential)->newLine();
        $response = $this->execute($query);
        $response->checkReturnCode();
        // TODO сделать через FoundItem
        $result = $response->readRemainingUtfLines();

        return $result;
    }

    /**
     * Выдача строки подключения для текущего соединения.
     *
     * @return string
     */
    public function toConnectionString() {
        return 'host='     . $this->host
            . ';port='     . $this->port
            . ';username=' . $this->username
            . ';password=' . $this->password
            . ';database=' . $this->database
            . ';arm='      . $this->arm . ';';
    }

    /**
     * Опустошение указанной базы данных.
     *
     * @param string $database База данных.
     * @return bool
     */
    public function truncateDatabase($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'S');
        $query->addAnsi($database)->newLine();
        $this->execute($query);

        return true;
    }

    /**
     * Разблокирование указанной базы данных.
     *
     * @param string $database База данных.
     * @return bool
     */
    public function unlockDatabase($database) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, 'U');
        $query->addAnsi($database)->newLine();
        $this->execute($query);

        return true;
    }

    /**
     * Разблокирование записей.
     *
     * @param string $database База данных.
     * @param array $mfnList Массив MFN.
     * @return bool
     */
    public function unlockRecords($database, array $mfnList) {
        if (!$this->connected) {
            return false;
        }

        $database = getOne($database, $this->database);

        $query = new ClientQuery($this, 'Q');
        $query->addAnsi($database)->newLine();
        foreach ($mfnList as $mfn) {
            $query->add($mfn)->newLine();
        }

        $this->execute($query);

        return true;
    }

    /**
     * Обновление строк серверного INI-файла
     * для текущего пользователя.
     *
     * @param array $lines Изменённые строки.
     * @return bool
     */
    public function updateIniFile(array $lines) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '8');
        foreach ($lines as $line) {
            $query->addAnsi($line)->newLine();
        }

        $this->execute($query);

        return true;
    }

    /**
     * Обновление списка пользователей на сервере.
     *
     * @param array $users Список пользователей.
     * @return bool
     */
    public function updateUserList(array $users) {
        if (!$this->connected) {
            return false;
        }

        $query = new ClientQuery($this, '+7');
        foreach ($users as $user) {
            $query->addAnsi($user->encode())->newLine();
        }
        $this->execute($query);

        return true;
    }

    /**
     * Сохранение записи на сервере.
     *
     * @param MarcRecord $record Запись для сохранения (новая или ранее считанная).
     * @param int $lockFlag Оставить запись заблокированной?
     * @param int $actualize Актуализировать словарь?
     * @return bool
     */
    public function writeRecord(MarcRecord $record, $lockFlag=0, $actualize=1) {
        if (!$this->connected) {
            return false;
        }

        $database = $record->database;
        if (!$database) {
            $database = $this->database;
        }

        $query = new ClientQuery($this, 'D');
        $query->addAnsi($database)->newLine();
        $query->add($lockFlag)->newLine();
        $query->add($actualize)->newLine();
        // TODO implement properly
        $query->addUtf(strval($record));

        return true;
    }
}
