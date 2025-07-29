<?php

namespace iustato\Bql\VarTypes;

use InvalidArgumentException;

class StringVarHandler extends SimpleVarHandler
{
    protected $var;
    public function __construct($name, &$var, $parent = null, $type = '')
    {
        $this->name = (string)$name;
        // $var !== null ? trim($var, "'") : null;
        $this->var = &$var;
        $this->parent = $parent;
        $this->type = $type;
    }
    public static function supports($variable): bool
    {
        return !is_object($variable) && !is_array($variable) && is_string($variable);
    }
    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        if (empty($varB))
        {
            throw new \Exception("empty varB for operation:".$operator." in ".__CLASS__);
        }

        /*
        if (!($varB instanceof StringVarHandler))
        {
            $varBstring = $varB->toString();
            $varB = new StringVarHandler($varB->name, $varBstring);
        }*/

        switch ($operator)
        {
            case '=':
                //$value = &$varB->get();
                //$this->set($this->name,$varB);
                return $varB;   //  this
            case '+':
                $value = $this->var.$varB->var;
                return new StringVarHandler('unknown'.rand(0,9999), $value);
            case '==':
                $value = $this->var == $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case '!=':
                $value = $this->var != $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case 'like':
                $pattern = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($varB->get(), '/')) . '$/i';
                $value = preg_match($pattern, $this->get()) === 1;
                return new BoolVarHandler('unknown'.rand(0,9999), $value);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                $value = in_array($this->var, $varB->get());
                return new BoolVarHandler('unknown'. rand(0,9999), $value);
            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function toString()
    {
        return $this->var;
    }

    public function toNum()
    {
        return new NumVarHandler($this->var);
    }

    public function convertToMe(AbstractVariableHandler $var)
    {
        // TODO: Implement convertToMe() method.
    }

    public function has(string $key): string
    {
        return '';
    }
}