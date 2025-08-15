<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;
use ReflectionClass;

class ObjectHandler extends AbstractVariableHandler
{
    private ?object $object;
    private array $addressingCache = []; // Кэш для результатов has() по ключам

    public function __construct(string $name, &$object, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $object, $parent, $storage);
        $this->name = $name;
        $this->object = &$object; // Используем ссылку
        $this->parent = $parent;
        $this->type = 'object';
    }

    public static function supports($variable): bool
    {
        return is_object($variable);
    }

    public function &get(string $key = '')
    {
        if (empty($key)) {
            return $this->object;
        }

        $null = null;

        $addressing = $this->has($key);

        if (empty($addressing)) {
            return $null;
        }

        switch ($addressing) {
            case 'property':
                return $this->object->{$key};
            case 'magic':
                $var = $this->object->{$key};
                return $var;
            case 'getter':
                $method = 'get' . ucfirst($key);
                $var = $this->object->{$method}();
                return $var;
        }

        return $null;
    }

    public function set(string $key, &$value, bool $setCurrent = false): void
    {
        if ($this->parent == null || $setCurrent) {
            $addressing = $this->has($key);
            switch ($addressing) {
                case 'property':
                case 'magic':
                    $this->object->{$key} = $value;
                    break;
                case 'getter':
                    $method = 'set' . ucfirst($key);
                    if (method_exists($this->object, $method)) {
                        $this->object->{$method}($value);
                    }
                    break;
            }
        } else {
            $this->parent->set($this->name, $value, true);
        }
    }

    public function has(string $key): string
    {
        // Проверяем кэш для этого ключа
        if (isset($this->addressingCache[$key])) {
            return $this->addressingCache[$key];
        }

        $addressing = '';
        $reflection = new ReflectionClass($this->object);
        
        // Сначала проверяем через ReflectionClass (для объявленных в классе свойств)
        if ($reflection->hasProperty($key)) {
            $property = $reflection->getProperty($key);
            if ($property->isPublic()) {
                $addressing = 'property';
            } else {
                // Если свойство не публичное, но есть магический метод __get/__set
                if (method_exists($this->object, '__get') && method_exists($this->object, '__set')) {
                    $addressing = 'magic';
                } else {
                    // Если свойство не публичное и нет магических методов, возвращаем пустую строку
                    $addressing = '';
                }
            }
        }
        // Для stdClass объектов (созданных через (object)[]) проверяем динамические свойства
        // stdClass не имеет private/protected свойств, все динамические свойства публичные
        elseif ($reflection->getName() === 'stdClass' && property_exists($this->object, $key)) {
            $addressing = 'property';
        }
        // Проверяем методы-геттеры/сеттеры
        elseif (method_exists($this->object, 'get' . ucfirst($key))) {
            $addressing = 'getter';
        }
        // Проверяем магические методы как последний вариант
        elseif (method_exists($this->object, '__get') && method_exists($this->object, '__set')) {
            $addressing = 'magic';
        }

        // Кэшируем результат для этого ключа
        $this->addressingCache[$key] = $addressing;
        
        return $addressing;
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '=':
                return $varB;
            case '??':
                if (is_null($this->object))
                {
                    return $varB;
                }
                else
                {
                    return $this->object;
                }
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
    
    public function toString(): ?StringVarHandler
    {
        return new StringVarHandler('temp', $this->get(), null, $this->storage);
    }

    public function toNum(): ?NumVarHandler
    {
        return new NumVarHandler('temp', $this->get(), null, $this->storage);
    }

    public function convertToMe(AbstractVariableHandler $var)
    {
        throw new \Exception("can not convert ".get_class($var)." to ".__CLASS__);
    }
}