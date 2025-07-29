<?php

namespace iustato\Bql\VarTypes;

class ArrayHandler extends AbstractVariableHandler
{
    //  link to array
    private array $array;

    public function __construct(string $name, array &$array, $parent = null)
    {
        $this->name = $name;
        $this->array = &$array;
        $this->parent = $parent;
        $this->type = 'array';
    }

    public static function supports($variable): bool
    {
        return is_array($variable);
    }

    public function &get(string $key = '')
    {
        if (empty($key)) {
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
            $this->array[$key] = &$value;
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
                if (!($varB instanceof ArrayHandler))
                {
                    throw new \InvalidArgumentException("Right-hand side must be an array");
                }

                $this->array = $varB->array;

                return $varB;

            /*
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                $value
                return in_array($this->array, $varB->get());*/
            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function toString()
    {
        // TODO: Implement toString() method.
    }

    public function toNum()
    {
        // TODO: Implement toNum() method.
    }

    public function convertToMe(AbstractVariableHandler $var)
    {
        // TODO: Implement convertToMe() method.
    }
}