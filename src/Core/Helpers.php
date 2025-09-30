<?php

namespace SPGame\Core;

/**
 * Класс с вспомогательными функциями
 */
class Helpers
{

    /**
     * Генерация PIN-кода с длиной из настроек Environment
     */
    public static function generatePin(): int
    {
        $length = Environment::getInt('PIN_LENGTH', 6);

        // Ограничиваем длину от 4 до 8
        if ($length < 4) $length = 4;
        if ($length > 8) $length = 8;

        $pin = str_pad((string)random_int(0, (int)pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);

        return $pin;
    }

    /**
     * Форматирует число с разделителями тысяч и фиксированным количеством знаков после запятой.
     *
     * @param float|int $number Число для форматирования
     * @param int|null $decimals Количество знаков после запятой (по умолчанию 0)
     * @return string Отформатированная строка числа
     */
    public static function prettyNumber(float|int $number, ?int $decimals = 0): string
    {
        return number_format($number, $decimals, ',', '.');
    }

    /**
     * Преобразует число в короткую читаемую форму (сокращение K, M, B и т.д.)
     *
     * @param float|int $number Число для форматирования
     * @param int|null $decimal Количество знаков после запятой (автоопределяется, если null)
     * @return string Число в сокращённой форме, например 1.2K, 3M
     */
    public static function formatNumberShort(float|int $number, ?int $decimal = null): string
    {
        $negate = $number < 0 ? -1 : 1;
        $number = abs($number);

        $units = ["", "K", "M", "B", "T", "Q", "Q+", "S", "S+", "O", "N"];
        $key = 0;

        while ($number >= 1000 && $key < count($units) - 1) {
            $number /= 1000;
            $key++;
        }

        if (!is_numeric($decimal)) {
            $decimal = ($number < 100 && $key > 0 && $number != (int)$number) ? 1 : 0;
        }

        return self::prettyNumber($negate * $number, $decimal) . $units[$key];
    }
}
