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
        VarTypes\DecimalVarHandler::class,
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
                // Дробные и слишком большие для int целые (например, 20-значный
                // номер) — decimal/bignum (строка + bcmath), остальное — int.
                if (VarTypes\DecimalVarHandler::needsDecimal($variable)) {
                    return new VarTypes\DecimalVarHandler($safeName, $variable, $parent, $storage);
                }
                return new VarTypes\NumVarHandler($safeName, $variable, $parent, $storage);
            case 'string':
                return new VarTypes\StringVarHandler($safeName, $variable, $parent, $storage);
            case 'array':
                $elements = array_map('trim', explode(',', trim($token->getValue(), '[]')));

                $value = array_map(function ($el) {
                    if (preg_match("/^'([^']*)'$/", $el, $match)) {
                        return $match[1];
                    }
                    if (is_numeric($el)) {
                        // Дробные и большие целые оставляем строкой (decimal/bignum),
                        // обычные целые — int.
                        return VarTypes\DecimalVarHandler::needsDecimal($el) ? $el : (int)$el;
                    }
                    return $el;
                }, $elements);

                return new VarTypes\ArrayHandler($safeName, $value, $parent, $storage);

            case 'sausage':
                // Для токенов типа 'sausage' создаем SausageVarHandler
                return VarTypes\SausageVarHandler::createForNestedVariable($safeName, $storage);

            case 'identifier':
            default:
                return self::createHandler($variable, $safeName, $parent, $storage);
        }
    }
}