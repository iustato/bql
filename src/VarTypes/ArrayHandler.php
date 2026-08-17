<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;

class ArrayHandler extends AbstractVariableHandler
{
    /**
     * Ссылка на массив хост-проекта.
     *
     * Тип намеренно 'mixed', а не 'array': типизированное свойство запирает тип у
     * самой переменной хост-проекта, пока обработчик жив, и последующее
     * присваивание скаляра ('r = 2' после 'r = [1,2]') падало бы с TypeError.
     * Конструктор всё равно принимает только массив.
     */
    private mixed $array;

    public function __construct($name, array &$array, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct((string)$name, $array, $parent, $storage);
        $this->array = &$array;
        $this->type = 'array';
    }

    public static function supports($variable): bool
    {
        return is_array($variable);
    }

    public function &get(string $key = '')
    {
        // Именно сравнение с '', а не empty(): индекс '0' в PHP «пустой», и с
        // empty() обращение вида 'items.0' молча возвращало весь массив.
        if ($key === '') {
            return $this->array;
        }

        if (!array_key_exists($key, $this->array)) {
            $null = null;
            return $null;
        }

        return $this->array[$key];
    }

    public function set(string $key, &$value, bool $setCurrent = false): void
    {
        if ($this->parent == null || $setCurrent) {
            $newValue = $value instanceof AbstractVariableHandler ? $value->get() : $value;

            // Пустой ключ — замена значения целиком (та же конвенция, что в get()).
            // Так приходит присваивание всей переменной: 'cfg = [{"a":1}]'. Значение
            // может оказаться и скаляром — тогда обработчик переменной сразу после
            // этого пересоздаст VariableStorage::refreshHandler().
            if ($key === '') {
                $this->array = $newValue;
                return;
            }

            $this->array[$key] = $newValue;
        } else {
            $this->parent->set($this->name, $value, true);
        }
    }

    public function has(string $key): string
    {
        if (array_key_exists($key, $this->array))
            return 'array';
        else
            return '';
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch (strtolower($operator))
        {
            case '=':
                return $varB;
            case 'in':
                // Для оператора 'in' - проверяем, содержится ли значение в массиве
                if ($varB === null) {
                    throw new \InvalidArgumentException("Right-hand side of 'in' cannot be null");
                }
                $value = in_array($varB->get(), $this->array);
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            default:
                throw new \Exception("incorrect unary operator ".$operator." for ".__CLASS__);
        }
    }
    public function toString(): StringVarHandler
    {
        $value = json_encode($this->array);
        return new StringVarHandler('temp', $value, null, $this->storage);
    }

    public function toNum(): NumVarHandler
    {
        $value = count($this->array);
        return new NumVarHandler('temp', $value, null, $this->storage);
    }

    public function convertToMe(AbstractVariableHandler $var): ArrayHandler
    {
        if ($var instanceof ArrayHandler) {
            return $var;
        }
        
        // Попытка конвертировать в массив
        $value = [$var->get()];
        return new ArrayHandler('temp', $value, null, $this->storage);
    }
}