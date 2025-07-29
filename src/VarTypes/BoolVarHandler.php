<?php

namespace iustato\Bql\VarTypes;

class BoolVarHandler extends SimpleVarHandler
{
    protected $var;

    public function __construct($name, &$var, $parent = null, $type = '')
    {
        $this->name = (string)$name;
        if ($var == true)
        {
            $this->var = true;
        }
        else
        {
            $this->var = false;
        }

       //  = $var !== null ? $var : false;
        $this->parent = $parent;
        $this->type = $type;
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
                $value = $this->var && $this->convertToMe($varB);
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case '||':
                $value = $this->var || $this->convertToMe($varB);
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case '==':
                $value = $this->var == $this->convertToMe($varB);
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case '!=':
                $value = $this->var != $this->convertToMe($varB);
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case '!':
                $value = !$this->var;
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function convertToMe (AbstractVariableHandler $var)
    {
        //BoolVarHandler

        if ($var instanceof BoolVarHandler)
        {
            return $var;
        }

        if ($var instanceof StringVarHandler)
        {
            $var_value = $var->get();
            if (!empty($var_value))
            {
                return new BoolVarHandler(true);
            }
            else
            {
                return new BoolVarHandler(false);
            }
        }

        if ($var instanceof NumVarHandler)
        {
            $var_value = $var->get();

            if (intval($var_value) > 0)
            {
                return new BoolVarHandler(true);
            }
            else
            {
                return new BoolVarHandler(false);
            }
        }

        throw new \Exception("can not convert ".get_class($var)." to ".__CLASS__);

    }

    public function &get(string $key = '')
    {
       // if ($this->var == true)
            return $this->var;
    }

    public function toString(): StringVarHandler
    {
        if ($this->var == true)
        {
            $value = 'true';
            return new StringVarHandler('', $value);
        }
        else
        {
            $value = 'false';
            return new StringVarHandler('', $value);
        }
    }

    public function toNum(): NumVarHandler
    {
        if ($this->var == true)
        {
            $value = 1;
            return new NumVarHandler('', $value);
        }
        else
        {
            $value = 0;
            return new NumVarHandler('', $value);
        }
    }

    public function has(string $key): string
    {
        // TODO: Implement has() method.
    }
}