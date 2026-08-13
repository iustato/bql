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
}
