<?php

namespace iustato\Bql;

use iustato\Bql\VarTypes\AbstractVariableHandler;

class VariableHandlerFactory
{
    /** @var array Список доступных обработчиков */
    private static array $availableHandlers = [
        VarTypes\DateTimeVarHandler::class,
        VarTypes\DateTimeIntervalVarHandler::class,
        VarTypes\ObjectHandler::class,
        VarTypes\BoolVarHandler::class,
        VarTypes\NumVarHandler::class,
        VarTypes\StringVarHandler::class,
        VarTypes\ArrayHandler::class,
        VarTypes\SimpleVarHandler::class
    ];

    /**
     * Создаёт обработчик для переменной.
     */
    public static function createHandler(
        &$variable,
        $name,
        $parent = null,
        ?VariableStorage $storage = null
    ): ?AbstractVariableHandler {
        if ($variable instanceof AbstractVariableHandler) {
            return $variable;
        }

        foreach (self::$availableHandlers as $handlerClass) {
            if ($handlerClass::supports($variable)) {
                return new $handlerClass($name ?: 'anonymous', $variable, $parent, $storage);
            }
        }
        return null;
    }

    public static function createHandlerByTokenValue(Token $token, $name, $variable, $parent = null, ?VariableStorage $storage = null): ?AbstractVariableHandler
    {
        $safeName = $name ?: 'anonymous';
        
        switch ($token->getType()) {
            case 'number':
                // Единый числовой обработчик: сам решит, целое это (int) или
                // дробное/большое (строка + bcmath).
                return new VarTypes\NumVarHandler($safeName, $variable, $parent, $storage);
            case 'string':
                return new VarTypes\StringVarHandler($safeName, $variable, $parent, $storage);
            case 'array':
                // Токен типа 'array' несёт либо текст литерала, либо уже готовый
                // массив — результат операции, завёрнутый обратно в Token.
                $literal = $token->getValue();
                $value = is_array($literal) ? $literal : self::parseArrayLiteral($literal);

                return new VarTypes\ArrayHandler($safeName, $value, $parent, $storage);

            case 'sausage':
                // Для токенов типа 'sausage' создаем SausageVarHandler
                return VarTypes\SausageVarHandler::createForNestedVariable($safeName, $storage);

            case 'identifier':
            default:
                return self::createHandler($variable, $safeName, $parent, $storage);
        }
    }

    /**
     * Разбирает литерал массива. Поддерживаются три формы:
     *
     *   [{"a":1,"b":2}]     ассоциативный массив — внешние '[]' здесь только обёртка,
     *                       нужная, чтобы '{' никогда не стоял в позиции значения
     *                       (иначе он конфликтовал бы с блоками '{...}')
     *   [[1,2],[3,4]]       вложенные массивы, разбираются строгим JSON целиком
     *   ['a', 'b']          историческая форма с одинарными кавычками
     *
     * JSON-формы имеют приоритет; к разбору по запятой откатываемся только если
     * это не JSON и фигурных скобок в литерале нет.
     */
    public static function parseArrayLiteral(string $literal): array
    {
        $trimmed = trim($literal);

        // Ассоциативная форма: внутри внешних скобок ровно один JSON-объект.
        if (preg_match('/^\[\s*(\{.*\})\s*\]$/s', $trimmed, $match)) {
            $decoded = json_decode($match[1], true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($decoded)) {
                return self::normalizeDecodedNumbers($decoded);
            }
        }

        // Строгий JSON-массив: плоский список, вложенные массивы, список объектов.
        $decoded = json_decode($trimmed, true, 512, JSON_BIGINT_AS_STRING);
        if (is_array($decoded)) {
            return self::normalizeDecodedNumbers($decoded);
        }

        // Фигурная скобка означала JSON — значит он битый, и молчать нельзя:
        // молчаливый откат превратил бы опечатку в массив из мусорных строк.
        if (str_contains($trimmed, '{')) {
            throw new \InvalidArgumentException(
                "Invalid JSON in array literal '$trimmed': " . json_last_error_msg()
            );
        }

        return self::parseLegacyArrayLiteral($trimmed);
    }

    /**
     * Приводит числа из json_decode() к внутреннему представлению NumVarHandler.
     *
     * Без этого дробные значения приехали бы PHP-float'ами и обошли bcmath, на
     * котором держится вся арифметика. Строки не трогаем: "01234" — это строка с
     * ведущими нулями, а не число. Большие целые уже пришли строками благодаря
     * JSON_BIGINT_AS_STRING — это и есть представление bignum в NumVarHandler.
     */
    private static function normalizeDecodedNumbers(array $decoded): array
    {
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $decoded[$key] = self::normalizeDecodedNumbers($value);
            } elseif (is_int($value) || is_float($value)) {
                $decoded[$key] = VarTypes\NumVarHandler::present(
                    VarTypes\NumVarHandler::toNumericString($value)
                );
            }
        }

        return $decoded;
    }

    /** Историческая форма: плоский список через запятую, строки в одинарных кавычках. */
    private static function parseLegacyArrayLiteral(string $literal): array
    {
        $elements = array_map('trim', explode(',', trim($literal, '[]')));

        return array_map(function ($el) {
            if (preg_match("/^'([^']*)'$/", $el, $match)) {
                return $match[1];
            }
            if (is_numeric($el)) {
                // Обычные целые — int, дробные и большие — строка (bignum),
                // как и во внутреннем представлении NumVarHandler.
                return VarTypes\NumVarHandler::present(VarTypes\NumVarHandler::toNumericString($el));
            }
            return $el;
        }, $elements);
    }
}