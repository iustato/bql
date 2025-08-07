<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;

class NumVarHandler extends SimpleVarHandler
{
    protected $var;
    
    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        $this->var = is_numeric($var) ? (float)$var : 0;
        $this->type = 'number';
    }

    public static function supports($variable): bool
    {
        return is_numeric($variable);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '=':
                return $varB;
            case '+':
                $value = $this->var + (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '-':
                $value = $this->var - (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '*':
                $value = $this->var * (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '/':
                $bValue = (float)$varB->get();
                if ($bValue == 0) {
                    throw new \Exception("Division by zero");
                }
                $value = $this->var / $bValue;
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '+=':
                $this->var += (float)$varB->get();
                return $this;
            case '-=':
                $this->var -= (float)$varB->get();
                return $this;
            case '==':
                $value = $this->var == (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '!=':
                $value = $this->var != (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '>':
                $value = $this->var > (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '<':
                $value = $this->var < (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '>=':
                $value = $this->var >= (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '<=':
                $value = $this->var <= (float)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function toString(): StringVarHandler
    {
        $value = (string)$this->var;
        return new StringVarHandler('temp', $value, null, $this->storage);
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '++':
                $this->var = $this->var + 1;
                return $this;
            case '--':
                $this->var = $this->var - 1;
                return $this;

            default:
                throw new \Exception("incorrect unary operator ".$operator." for ".__CLASS__);
        }
    }

    public function toNum(): NumVarHandler
    {
        return $this;
    }

    public function convertToMe(AbstractVariableHandler $var): NumVarHandler
    {
        if ($var instanceof NumVarHandler) {
            return $var;
        }
        
        $value = (float)$var->get();
        return new NumVarHandler('temp', $value, null, $this->storage);
    }

    public function has(string $key): string
    {
        return '';
    }
}