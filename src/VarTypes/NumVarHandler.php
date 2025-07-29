<?php

namespace iustato\Bql\VarTypes;

class NumVarHandler extends SimpleVarHandler
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
        return is_numeric($variable);
    }
    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        /*
        if (empty($varB))
        {
            throw new \Exception("empty varB for operation:".$operator." in ".__CLASS__);
        }*/

        if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);


        switch ($operator)
        {
            case '=':
                return $varB;
            case '+':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var + $varB->var;
                return new NumVarHandler('unknown'.rand(0,9999),$value);
            case '-':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var -$varB->var;
                return new NumVarHandler('unknown'.rand(0,9999),$value);
            case '*':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var * $varB->var;
                return new NumVarHandler('unknown'.rand(0,9999),$value);
            case '/':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var  /$varB->var;
                return new NumVarHandler('unknown'.rand(0,9999),$value);
            case '++':
                $this->var = $this->var + 1;
                return $this;
                break;
            case '--':
                $this->var = $this->var - 1;
                return $this;
            case '+=':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $this->var = $this->var + $varB->var;
                return $this;
            case '-=':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $this->var = $this->var - $varB->var;
                return $this;
            case '>':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var > $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999),$value);
            case '>=':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var >= $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999),$value);
            case '<':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var < $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999),$value);
            case '<=':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var <= $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999),$value);
            case '==':
                if (!($varB instanceof NumVarHandler)) $varB = $this->convertToMe($varB);
                $value = $this->var == $varB->var;
                return new BoolVarHandler('unknown'.rand(0,9999),$value);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new \InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                $value = in_array($this->var, $varB->get());
                return new BoolVarHandler('unknown'.rand(0,9999),$value);

            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function toString()
    {
        return (string)$this->var;
    }

    public function toNum()
    {
        return $this;
    }

    public function convertToMe(AbstractVariableHandler $var): NumVarHandler
    {
        if ($var instanceof BoolVarHandler)
        {
            return $var->toNum();
        }

        throw new \Exception("can not convert ".get_class($var)." to ".__CLASS__);
    }

    public function has(string $key): string
    {
        // TODO: Implement has() method.
    }
}