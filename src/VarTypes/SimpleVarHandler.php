<?php

namespace iustato\Bql\VarTypes;

class SimpleVarHandler extends AbstractVariableHandler
{
    private $var;

    public function __construct($name, &$var, $parent = null, $type = '')
    {
        $this->name = (string)$name;
        $this->var = $var !== null ? trim($var, "'") : null;
        $this->parent = $parent;
        $this->type = $type;
    }

    public static function supports($variable): bool
    {
        return !is_array($variable) && !is_object($variable);
    }

    public function &get(string $key = '')
    {
        return $this->var;
    }

    public function set(string $key, &$value, bool $setCurrent = false): void
    {
        if ($this->parent == null || $setCurrent) {
            $this->var = &$value;
        } else {
            $this->parent->set($this->name, $value, true);
        }
    }

    public function has(string $key): string
    {
        return '';
    }
}