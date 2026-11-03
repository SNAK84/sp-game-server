<?php

namespace SPGame\Core;

class Time
{
    static float $StartTime = 0.0;
    static ?\DateTimeZone $TimeZone = null;

    public static function Start(): void
    {
        self::$StartTime = microtime(true);
    }

    public static function initTimeZone(?string $tz = null): void
    {
        if ($tz === null) {
            $tz = Environment::get('TIMEZONE', 'UTC');
        }

        try {
            self::$TimeZone = new \DateTimeZone($tz);
        } catch (\Exception $e) {
            self::$TimeZone = new \DateTimeZone('UTC');
        }

        date_default_timezone_set(self::$TimeZone->getName());
    }

    /**
     * Возвращает время работы с момента Start
     *
     * @param bool $formatted Если true — вернёт строку, иначе float секунд
     * @param bool $form Если возвращается строка — использовать "д ч м с" или ":"
     */
    public static function WorkTime(bool $formatted = false, bool $form = true, bool $ms = false): string|float
    {
        $elapsed = microtime(true) - self::$StartTime;
        return $formatted ? self::SecondToTime($elapsed, $form, $ms) : $elapsed;
    }

    /**
     * Преобразует секунды в строку формата времени
     *
     * @param float $sec Количество секунд
     * @param bool $form Если true — формат с д, ч, м, с; если false — разделитель ':'
     * @return string
     */
    public static function SecondToTime(float $sec, bool $form = true, bool $ms = false): string
    {
        $units = [
            'd' => 86400,
            'h' => 3600,
            'm' => 60,
            's' => 1,
        ];

        $values = [];
        foreach ($units as $k => $v) {
            $values[$k] = (int) floor($sec / $v);
            $sec = fmod($sec, $v);
        }

        // остаток — миллисекунды
        if ($ms) $values['ms'] = (int) floor(($sec - floor($sec)) * 1000);

        $format = ($form)
            ? ['%dд ', '%02dч ', '%02dм ', '%02dс', ' %03dмс']
            : ['%d ', '%02d:', '%02d:', '%02d', '.%03d'];

        $parts = [];
        if ($values['d'] > 0) $parts[] = sprintf($format[0], $values['d']);
        if ($values['h'] > 0 || $values['d'] > 0) $parts[] = sprintf($format[1], $values['h']);
        if ($values['m'] > 0 || $values['h'] > 0 || $values['d'] > 0) $parts[] = sprintf($format[2], $values['m']);
        $parts[] = sprintf($format[3], $values['s']);

        if ($ms) $parts[] = sprintf($format[4], $values['ms']);


        return implode('', $parts);
    }

    public static function FormatDateTime(?float $timestamp = null, ?string $format = null): string
    {
        // Получаем формат даты из .env, по умолчанию 'Y-m-d H:i:s'
        if (!$format)
            $format = Environment::get('DATE_FORMAT', 'H:i:s d.m.y');

        if ($timestamp === null) {
            $timestamp = microtime(true);
        }

        if (!self::$TimeZone) self::initTimeZone();

        $seconds = (int) floor($timestamp);
        $milliseconds = (int) round(($timestamp - $seconds) * 1000);

        //$marker = '<<<##>>>';
        $dt = \DateTime::createFromFormat('U.u', sprintf('%.6F', $timestamp));
        $dt->setTimezone(self::$TimeZone);
        $formatted = $dt->format($format);

        return $formatted;

    }
}
