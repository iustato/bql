<?php

namespace iustato\Bql\VarTypes;

abstract class AbstractVariableHandler
{
    protected string $name;
    protected ?AbstractVariableHandler $parent;  // link to parent object
    protected string $type;
    /**
     * Проверяет, поддерживает ли обработчик данный тип переменной.
     */
    abstract public static function supports($variable): bool;

    /**
     * Получение значения по ключу.
     */
    abstract public function &get(string $key = '');

    /**
     * Сохранение значения по ключу.
     */
    abstract public function set(string $key, &$value, bool $setCurrent = false): void;

    /**
     * Проверка существования ключа.
     */
    abstract public function has(string $key): string;

    public function getType()
    {
        return $this->type;
    }
}