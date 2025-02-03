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
}