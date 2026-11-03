<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

/**
 * Только имена: каноническая латиница и её единое русское представление.
 * Версия seed/словарей является частью контракта уже сохранённых имён.
 */
final class CelestialNameGenerator
{
    private const DEFAULT_SEED = 'sp-game-celestial-v1';
    private const SYSTEM_CATALOGUE_SIZE = 32768; // 32 × 32 × 32.
    private const SYSTEM_PREFIXES = [
        'Al', 'Ar', 'As', 'Av', 'El', 'Er', 'Es', 'En',
        'Il', 'Ir', 'Or', 'Ul', 'Va', 'Ve', 'Za', 'Ze',
        'Ka', 'Ke', 'Da', 'De', 'Ta', 'Te', 'Na', 'Ne',
        'Sa', 'Se', 'Ma', 'Me', 'La', 'Le', 'Ra', 'Re',
    ];
    private const SYSTEM_ROOTS = [
        'ra', 're', 'ri', 'ro', 'la', 'le', 'li', 'lo',
        'na', 'ne', 'ni', 'no', 'ta', 'te', 'ti', 'to',
        'sa', 'se', 'si', 'so', 'va', 've', 'vi', 'vo',
        'da', 'de', 'di', 'do', 'ka', 'ke', 'ki', 'ko',
        'ma', 'me', 'mi', 'mo', 'za', 'ze', 'zi', 'zo',
        'ba', 'be', 'bi', 'bo', 'pa', 'pe', 'pi', 'po',
    ];
    private const SYSTEM_ENDINGS = [
        'ris', 'rion', 'ran', 'ron', 'ra', 'ria', 'ren', 'ros',
        'lon', 'laris', 'lis', 'lion', 'la', 'lia', 'las', 'len',
        'nor', 'nar', 'nis', 'nia', 'ne', 'nus', 'net', 'ner',
        'tar', 'tis', 'tor', 'ter', 'tan', 'tir', 'tus', 'tel',
        'mir', 'mon', 'mer', 'mia', 'mos', 'mis', 'mar', 'mel',
        'sar', 'ser', 'sir', 'sion', 'san', 'son', 'sel', 'sor',
        'dar', 'der', 'dir', 'dion', 'dan', 'don', 'del', 'dor',
        'var', 'ver', 'vir', 'vion', 'van', 'von', 'vel', 'vor',
    ];
    private const PLANET_FAMILIES = [
        [
            ['A', 'E', 'I', 'O', 'Au', 'Ve', 'Va', 'Se', 'Sa', 'Ne', 'Na', 'Le', 'Li', 'Ta', 'Te', 'Mi', 'Ma'],
            ['lar', 'ler', 'lir', 'nar', 'ner', 'mir', 'mel', 'sel', 'ser', 'vel', 'ver', 'tal', 'tel', 'ren', 'rel', 'del', 'den', 'mar', 'mer', 'sil', 'val', 'lun', 'lin', 'nor', 'nel'],
            ['ia', 'a', 'is', 'ina', 'ira', 'ara', 'ella', 'eria', 'ora', 'eia', 'iel'],
        ],
        [
            ['Al', 'Ar', 'El', 'Er', 'Il', 'Or', 'Ul', 'Ka', 'Ke', 'Va', 'Ve', 'Ze', 'Da', 'De', 'Ta', 'Na', 'Sa'],
            ['ta', 'te', 'ra', 're', 'ri', 'la', 'le', 'li', 'na', 'ne', 'ni', 'de', 'di', 'sa', 'se', 'si', 'va', 've', 'ma', 'me'],
            ['ris', 'rion', 'ria', 'ra', 'ron', 'lia', 'lis', 'nar', 'nia', 'nos', 'nion', 'ter', 'tis', 'tor', 'mia', 'mir', 'mon', 'nea'],
        ],
        [
            ['Za', 'Ze', 'Zu', 'Ka', 'Ke', 'Ki', 'Vo', 'Ve', 'Vi', 'Or', 'Ul', 'Ar', 'E', 'O'],
            ['ran', 'ren', 'rin', 'kar', 'ker', 'kir', 'ven', 'vel', 'zer', 'zar', 'kor', 'vor', 'nal', 'nel', 'tal', 'tor', 'sar', 'ser', 'dir'],
            ['a', 'is', 'ia', 'on', 'or', 'ar', 'um', 'en', 'el', 'ir'],
        ],
    ];
    private const MOON_FAMILIES = [
        [
            ['Ni', 'Li', 'E', 'Se', 'Mi', 'Au', 'Ne', 'Io', 'Lu', 'Na', 'Si', 'I', 'Le', 'A', 'Ve', 'Me'],
            ['r', 'l', 'n', 'm', 's', 'v', 't', 'd', 'rel', 'rin', 'lin', 'nel', 'mir', 'sel', 'ver', 'ner'],
            ['a', 'ia', 'ina', 'ella', 'ena', 'ora', 'ila', 'ira', 'elle', 'ara'],
        ],
        [
            ['A', 'E', 'I', 'O', 'La', 'Le', 'Na', 'Ne', 'Sa', 'Se', 'Ta', 'Ti'],
            ['li', 'ni', 'ri', 'mi', 'le', 'ne', 're', 'me', 'lo', 'no', 'ro'],
            ['ra', 'na', 'la', 'ne', 're', 'sa', 'ma', 'lis', 'nis', 'ris', 'riel', 'nel'],
        ],
    ];
    private const LINKING_SYLLABLES = ['la', 'le', 'li', 'na', 'ne', 'ni', 'ra', 're', 'ri', 'sa', 'se', 'ta', 'te', 'va', 've'];
    /** Фильтр применяется также на границах слогов. Словарь расширяется без изменения сохранённых имён. */
    private const UNWANTED = [
        'fuck', 'shit', 'cunt', 'dick', 'penis', 'porno', 'sex', 'bitch', 'asshole',
        'nigger', 'hitler', 'nazi', 'anus', 'pizd', 'piz', 'khui', 'hui', 'huy',
        'blyad', 'bliad', 'suk', 'ebat', 'eblo', 'yeb', 'zhop', 'govn', 'pidor', 'urod',
        'sra', 'sosi', 'sosat', 'peder', 'satan',
    ];

    /**
     * Уникальность не зависит от порядка создания и не требует индекса имён.
     * Нечётный множитель задаёт биекцию по модулю 2^15. Первые два символа
     * кодируют префикс, следующие два — корень, остаток — уникальное окончание.
     * Следующие блоки получают отдельный каталоговый суффикс. Поэтому все
     * положительные uint32 system различимы при одинаковых galaxy и seed.
     */
    public static function systemName(int $galaxy, int $system, string $seed = self::DEFAULT_SEED): string
    {
        self::requireIdentifier($system, 'system');
        $digest = hash('sha256', $seed . "\0system\0" . $galaxy, true);
        $multiplier = (self::number($digest, 0) % self::SYSTEM_CATALOGUE_SIZE) | 1;
        $offset = self::number($digest, 2) % self::SYSTEM_CATALOGUE_SIZE;
        $value = ((($system - 1) % self::SYSTEM_CATALOGUE_SIZE) * $multiplier + $offset)
            % self::SYSTEM_CATALOGUE_SIZE;
        $prefix = self::SYSTEM_PREFIXES[intdiv($value, 1024)];
        $root = self::segment(self::SYSTEM_ROOTS, intdiv($value, 32) % 32, $prefix);
        $ending = self::segment(self::SYSTEM_ENDINGS, $value % 32, $prefix . $root);
        $name = $prefix . $root . $ending;
        $block = intdiv($system - 1, self::SYSTEM_CATALOGUE_SIZE);
        return $block === 0 ? $name : $name . '-' . strtoupper(base_convert((string)($block + 1), 10, 36));
    }

    public static function planetName(int $galaxy, int $system, int $orbit, array $usedNames = [], string $seed = self::DEFAULT_SEED): string
    {
        self::requireIdentifier($orbit, 'orbit');
        return self::fantasyName('planet', [$galaxy, $system, $orbit], $usedNames, $seed);
    }

    public static function moonName(int $galaxy, int $system, int $orbit, int $satellite = 1, array $usedNames = [], string $seed = self::DEFAULT_SEED): string
    {
        self::requireIdentifier($orbit, 'orbit');
        self::requireIdentifier($satellite, 'satellite');
        return self::fantasyName('moon', [$galaxy, $system, $orbit, $satellite], $usedNames, $seed);
    }

    public static function localize(string $canonical, string $lang = 'ru'): string
    {
        if (!str_starts_with(strtolower($lang), 'ru') || !preg_match('/^[a-z0-9 -]+$/iD', $canonical)) {
            return $canonical;
        }
        // Каталоговый индекс остаётся ASCII: C/K или J/Y не должны сливаться
        // при транслитерации разных блоков одного и того же фонетического слова.
        $parts = explode('-', $canonical, 2);
        $word = $parts[0];
        // ia — единое окончание «ия». Алфавит генератора не содержит y/c/q/x,
        // поэтому не возникает альтернативных записей того же русского имени.
        $result = strtr(strtolower($word), [
            'iel' => 'иэль', 'ia' => 'ия', 'a' => 'а', 'b' => 'б', 'c' => 'к', 'd' => 'д',
            'e' => 'е', 'f' => 'ф', 'g' => 'г', 'h' => 'х', 'i' => 'и',
            'j' => 'й', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н',
            'o' => 'о', 'p' => 'п', 'q' => 'к', 'r' => 'р', 's' => 'с',
            't' => 'т', 'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс',
            'y' => 'й', 'z' => 'з',
        ]);
        // Самостоятельное начальное E читается «Э», внутри слова — «е».
        if (str_starts_with(strtolower($word), 'e')) {
            $result = 'э' . substr($result, strlen('е'));
        }
        if (preg_match('/^al(?=[bdfgkmnprstvz])/i', $word)) {
            $result = 'аль' . substr($result, strlen('ал'));
        }
        return mb_strtoupper(mb_substr($result, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($result, 1, null, 'UTF-8')
            . (isset($parts[1]) ? '-' . $parts[1] : '');
    }

    /** Ручные и исторические имена никогда не транслитерируются автоматически. */
    public static function planetDisplayName(array $row, string $lang = 'ru'): string
    {
        $name = (string)($row['name'] ?? '');
        return !empty($row['name_generated']) ? self::localize($name, $lang) : $name;
    }

    public static function starName(string $systemName, string $lang = 'ru', ?int $component = null): string
    {
        if ($component !== null && ($component < 1 || $component > 26)) {
            throw new \InvalidArgumentException('Номер компонента звезды должен быть от 1 до 26');
        }
        return self::localize($systemName, $lang) . ($component === null ? '' : ' ' . chr(64 + $component));
    }

    public static function gasGiantName(string $systemName, int $orbit, string $lang = 'ru'): string
    {
        return self::starName($systemName, $lang) . ' ' . self::roman($orbit);
    }

    public static function asteroidBeltName(string $systemName, int $ordinal, string $lang = 'ru'): string
    {
        if (!str_starts_with(strtolower($lang), 'ru')) {
            return self::localize($systemName, $lang) . ' Belt ' . self::roman($ordinal);
        }
        return 'Пояс ' . self::genitive(self::localize($systemName, $lang)) . ' ' . self::roman($ordinal);
    }

    private static function fantasyName(string $kind, array $identity, array $usedNames, string $seed): string
    {
        $used = [];
        foreach ($usedNames as $name) {
            $used[self::comparisonKey((string)$name)] = true;
        }
        $families = $kind === 'moon' ? self::MOON_FAMILIES : self::PLANET_FAMILIES;
        $context = $seed . "\0" . $kind . "\0" . implode(':', $identity) . "\0";
        for ($attempt = 0; $attempt < 96; $attempt++) {
            $digest = hash('sha256', $context . $attempt, true);
            $family = $families[ord($digest[0]) % count($families)];
            $name = $family[0][self::number($digest, 1) % count($family[0])]
                . (ord($digest[7]) % 4 === 0 ? '' : self::LINKING_SYLLABLES[ord($digest[8]) % count(self::LINKING_SYLLABLES)])
                . $family[1][self::number($digest, 3) % count($family[1])]
                . $family[2][self::number($digest, 5) % count($family[2])];
            if (self::pleasant($name) && strlen(self::localize($name, 'ru')) <= 32
                && !isset($used[self::comparisonKey($name)])) {
                return $name;
            }
        }
        // Ограниченное число фонетических попыток; каталоговая ветка гарантирует
        // свободное имя даже при искусственно заполненном словаре в одной системе.
        $prefix = $kind === 'moon' ? 'Luna-' : 'Lira-';
        $number = $identity[array_key_last($identity)];
        for ($counter = 0; $counter <= count($used); $counter++) {
            $name = $prefix . $number . '-' . $counter;
            if (!isset($used[self::comparisonKey($name)])) {
                return $name;
            }
        }
        throw new \LogicException('Не удалось подобрать свободное имя');
    }

    /** Выбирает ordinal-й благозвучный сегмент, не создавая массив всех слов. */
    private static function segment(array $segments, int $ordinal, string $prefix): string
    {
        foreach ($segments as $segment) {
            if (self::pleasant($prefix . $segment) && $ordinal-- === 0) {
                return $segment;
            }
        }
        throw new \LogicException('Недостаточная ёмкость фонетического каталога');
    }

    private static function pleasant(string $name): bool
    {
        $lower = strtolower($name);
        if (preg_match('/[^aeiou]{3}|[aeiou]{3}|([a-z]{2,4})\1/i', $lower)) {
            return false;
        }
        // Три одинаковых согласных в коротком имени дают монотонные «Орворир».
        if (preg_match('/([bdfgklmnprstvz])(?:.*\1){2}/', $lower)) {
            return false;
        }
        foreach (self::UNWANTED as $word) {
            if (str_contains($lower, $word)) {
                return false;
            }
        }
        return true;
    }

    private static function comparisonKey(string $name): string
    {
        return mb_strtolower(trim(self::localize($name, 'ru')), 'UTF-8');
    }

    private static function number(string $digest, int $offset): int
    {
        return (ord($digest[$offset]) << 8) | ord($digest[$offset + 1]);
    }

    private static function requireIdentifier(int $value, string $field): void
    {
        if ($value < 1 || $value > 4294967295) {
            throw new \InvalidArgumentException("{$field} должен быть положительным uint32");
        }
    }

    private static function roman(int $number): string
    {
        if ($number < 1 || $number > 3999) {
            throw new \InvalidArgumentException('Римский номер должен быть от 1 до 3999');
        }
        $result = '';
        foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $value => $digits) {
            while ($number >= $value) {
                $result .= $digits;
                $number -= $value;
            }
        }
        return $result;
    }

    /** Простое склонение фантастических имён; каталоговый суффикс не склоняется. */
    private static function genitive(string $name): string
    {
        $parts = explode('-', $name, 2);
        $word = $parts[0];
        $ending = mb_substr($word, -1, 1, 'UTF-8');
        $stem = mb_substr($word, 0, -1, 'UTF-8');
        $word = match ($ending) {
            'а' => $stem . (preg_match('/[гкхжчшщ]$/u', $stem) ? 'и' : 'ы'),
            'я' => $stem . 'и',
            'й', 'ь' => $stem . 'я',
            'е', 'и', 'о', 'у', 'ы', 'э', 'ю' => $word,
            default => $word . 'а',
        };
        return $word . (isset($parts[1]) ? '-' . $parts[1] : '');
    }
}
