<?php

namespace iustato\Bql\VarTypes;

use ReflectionClass;

class ObjectHandler extends AbstractVariableHandler
{
    private ?object $object;
    //private string $addressing = '';

    public function __construct(string $name, object $object, $parent = null)
    {
        $this->name = $name;
        $this->object = $object;
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
        if (!empty($this->addressing)) {
            return true;
        }

        $reflection = new ReflectionClass($this->object);
        if ($reflection->hasProperty($key)) {
            $property = $reflection->getProperty($key);
            if ($property->isPublic()) {
                //$this->addressing = 'property';
                return 'property';
            }
        }
        /*
        if (property_exists($this->object, $key)) {
            $this->addressing = 'property';
            return true;
        }*/

        if (method_exists($this->object, 'get' . ucfirst($key))) {
            //$this->addressing = 'getter';
            return 'getter';
        }

        if (method_exists($this->object, '__get')) {
            //$this->addressing = 'magic';
            return 'magic';
        }

        return '';
    }
}