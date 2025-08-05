<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;

class BoolVarHandler extends SimpleVarHandler
{
    protected $var;

    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        
        if ($var == true)
        {
            $this->var = true;
        }
        else
        {
            $this->var = false;
        }
        
        $this->type = 'boolean';
    }

    public static function supports($variable): bool
    {
        return is_bool($variable);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '=':
                return $varB;
            case '&&':
                $value = $this->var && $this->convertToMe($varB)->var;
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '||':
                $value = $this->var || $this->convertToMe($varB)->var;
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '==':
                $value = $this->var == $this->convertToMe($varB)->var;
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '!=':
                $value = $this->var != $this->convertToMe($varB)->var;
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
            case '!':
                $value = !$this->var;
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
                break;
            default:
                throw new \Exception("incorrect unary operator ".$operator." for ".__CLASS__);
        }
    }
    public function convertToMe(AbstractVariableHandler $var): BoolVarHandler
    {
        if ($var instanceof BoolVarHandler)
        {
            return $var;
        }

        if ($var instanceof StringVarHandler)
        {
            $var_value = $var->get();
            $value = !empty($var_value);
            return new BoolVarHandler('temp', $value, null, $this->storage);
        }

        if ($var instanceof NumVarHandler)
        {
            $var_value = $var->get();
            $value = intval($var_value) > 0;
            return new BoolVarHandler('temp', $value, null, $this->storage);
        }

        throw new \Exception("can not convert ".get_class($var)." to ".__CLASS__);
    }

    public function &get(string $key = '')
    {
        return $this->var;
    }

    public function toString(): StringVarHandler
    {
        if ($this->var == true)
        {
            $value = 'true';
            return new StringVarHandler('temp', $value, null, $this->storage);
        }
        else
        {
            $value = 'false';
            return new StringVarHandler('temp', $value, null, $this->storage);
        }
    }

    public function toNum(): NumVarHandler
    {
        if ($this->var == true)
        {
            $value = 1;
            return new NumVarHandler('temp', $value, null, $this->storage);
        }
        else
        {
            $value = 0;
            return new NumVarHandler('temp', $value, null, $this->storage);
        }
    }

    public function has(string $key): string
    {
        return '';
    }
}