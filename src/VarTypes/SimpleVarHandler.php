<?php

namespace iustato\Bql\VarTypes;

class SimpleVarHandler extends AbstractVariableHandler
{
    protected $var;

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
            if ($value instanceof AbstractVariableHandler) {
                // получаем ссылку из нашей переменной
                $var_ref = &$this->get();
                // записываем значение ПО ссылке
                $var_ref = $value->get($key);
            } else {
                // получаем ссылку из нашей переменной
                $var_ref = &$this->get();

                // записываем значение ПО ссылке
                $var_ref = $value;
            }
        } else {
            $this->parent->set($this->name, $value, true);
        }
    }

    public function has(string $key): string
    {
        return '';
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '=':
                return $varB;
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