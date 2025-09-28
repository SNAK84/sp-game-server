<?php

/**
 * helpers.php
 * Вспомогательные функции
 */

/**
 * Форматирует число с разделителями тысяч и фиксированным количеством знаков после запятой.
 *
 * @param float|int $number Число для форматирования
 * @param int|null $decimals Количество знаков после запятой (по умолчанию 0)
 * @return string Отформатированная строка числа
 */
function pretty_number(float|int $number, ?int $decimals = 0): string
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
function format_number_short(float|int $number, ?int $decimal = null): string
{
    $negate = $number < 0 ? -1 : 1;
    $number = abs($number);

    $units = ["", "K", "M", "B", "T", "Q", "Q+", "S", "S+", "O", "N"];
    $key = 0;

    while ($number >= 1000 && $key < count($units) - 1) {
        $number /= 1000;
        $key++;
    }

    // Автоопределение знаков после запятой
    if (!is_numeric($decimal)) {
        $decimal = ($number < 100 && $key > 0 && $number != (int)$number) ? 1 : 0;
    }

    return pretty_number($negate * $number, $decimal) . $units[$key];
}


