<?php

namespace iustato\Bql;

/**
 * Разрезание текста по разделителю верхнего уровня с учётом кавычек и вложенности.
 *
 * Нужен там, где конструкция разбирается уже после токенизации: аргументы функции
 * по ',', плечи switch по ';' и по ':'. Наивный explode() тут не работает —
 * разделитель может оказаться внутри строки ('a;b'), внутри вложенного массива
 * ([1,2]) или внутри JSON-объекта ({"a":1}).
 */
class LiteralScanner
{
    /** Парные скобки, влияющие на глубину вложенности. */
    private const PAIRS = ['(' => ')', '[' => ']', '{' => '}'];

    /**
     * Режет строку по разделителю, встречающемуся на нулевой глубине вложенности.
     *
     * Кавычки обоих видов: одинарные — строки BQL, двойные — строки внутри JSON.
     *
     * @param string $subject   исходная строка
     * @param string $delimiter односимвольный разделитель
     * @param int    $limit     максимум частей (0 — без ограничения); всё, что не
     *                          поместилось, остаётся в последней части целиком
     * @return string[] части в порядке следования, без обрезки пробелов
     */
    public static function splitTopLevel(string $subject, string $delimiter, int $limit = 0): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = '';
        $escaped = false;

        $length = strlen($subject);

        for ($i = 0; $i < $length; $i++) {
            $char = $subject[$i];

            // Внутри строки ищем только её конец: разделители и скобки не структурны.
            if ($quote !== '') {
                $current .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if (isset(self::PAIRS[$char])) {
                $depth++;
            } elseif (in_array($char, self::PAIRS, true)) {
                $depth--;
            }

            $limitReached = $limit > 0 && count($parts) >= $limit - 1;

            if ($char === $delimiter && $depth === 0 && !$limitReached) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Разбирает текст на литеральные куски и подстановки '{{ выражение }}'.
     *
     * Закрывающая пара ищется с учётом кавычек и вложенных фигурных скобок, поэтому
     * внутри подстановки может стоять целый switch: '{{ switch { case a: 1 } }}'.
     * Чтобы получить литеральные '{{', используй подстановку со строкой: "{{ '{{' }}".
     *
     * @return array<int, array{type: string, value: string}> куски в порядке следования,
     *         type — 'text' (как есть) или 'expr' (выражение BQL без обрамляющих скобок)
     */
    public static function splitPlaceholders(string $text): array
    {
        $segments = [];
        $offset = 0;

        while (($start = strpos($text, '{{', $offset)) !== false) {
            $end = self::findPlaceholderEnd($text, $start + 2);

            if ($end === null) {
                throw new \InvalidArgumentException(
                    "Unterminated placeholder: missing '}}' in '$text'"
                );
            }

            if ($start > $offset) {
                $segments[] = ['type' => 'text', 'value' => substr($text, $offset, $start - $offset)];
            }

            $segments[] = ['type' => 'expr', 'value' => trim(substr($text, $start + 2, $end - $start - 2))];
            $offset = $end + 2;
        }

        if ($offset < strlen($text)) {
            $segments[] = ['type' => 'text', 'value' => substr($text, $offset)];
        }

        return $segments;
    }

    /**
     * Ищет позицию закрывающих '}}' для подстановки, начатой на $from.
     *
     * @return int|null позиция первого символа '}}' либо null, если пара не найдена
     */
    private static function findPlaceholderEnd(string $text, int $from): ?int
    {
        $depth = 0;
        $quote = '';
        $escaped = false;
        $length = strlen($text);

        for ($i = $from; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                // '}}' на нулевой глубине закрывает подстановку; иначе это конец
                // вложенного блока внутри выражения.
                if ($depth === 0 && $i + 1 < $length && $text[$i + 1] === '}') {
                    return $i;
                }
                $depth--;
            }
        }

        return null;
    }
}
