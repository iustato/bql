<?php

namespace iustato\Bql;

use iustato\Bql\VarTypes\AbstractVariableHandler;

class VariableHandlerFactory
{
    /** @var array Список доступных обработчиков */
    private static array $availableHandlers = [
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
    public static function createHandler(&$variable, $name, $parent = null): ?AbstractVariableHandler
    {
        if ($variable instanceof  AbstractVariableHandler)
            return  $variable;

        foreach (VariableHandlerFactory::$availableHandlers as $handlerClass) {
            if ($handlerClass::supports($variable)) {
                return new $handlerClass($name, $variable, $parent);
            }
        }

        return null;
        //throw new InvalidArgumentException("Unsupported variable type: " . gettype($variable));
    }

    public static function createHandlerByTokenValue(Token $token, $name, $variable, $parent = null): ?AbstractVariableHandler
    {
        switch ($token->getType()) {
            case 'number':
                return new VarTypes\NumVarHandler($name,$variable);
            case 'string':
                return new VarTypes\StringVarHandler($name, $variable);
            case 'array':
                $elements = array_map('trim', explode(',', trim($token->getValue(), '[]')));

                $value = array_map(function ($el) {
                    if (preg_match("/^'([^']*)'$/", $el, $match)) {
                        return $match[1];
                    }
                    return is_numeric($el) ? (int)$el : $el;
                }, $elements);

                return new VarTypes\ArrayHandler($name, $value, $parent);

                break;
            case 'identifier':
            default:
                return self::createHandler($variable, $parent);
                break;
        }
    }
}