<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;
use InvalidArgumentException;

class StringVarHandler extends SimpleVarHandler
{
    protected $var;
    
    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        $this->var = &$var;
        $this->type = 'string';
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

        switch ($operator)
        {
            case '=':
                return $varB;
            case '.':
                $value = $this->var . $varB->get();
                $anonymousName = $this->registerAnonymous(new StringVarHandler('temp', $value, null, $this->storage));
                return new StringVarHandler($anonymousName, $value, null, $this->storage);
            case '==':
                $value = $this->var == $varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '!=':
                $value = $this->var != $varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case 'like':
                $pattern = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($varB->get(), '/')) . '$/i';
                $value = preg_match($pattern, $this->get()) === 1;
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                $value = in_array($this->var, $varB->get());
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '+':
            case '+=':
            case '-':
            case '-=':
            case '*':
            case '/':
            case '>':
            case '>=':
            case '<':
            case '<=':
                // Приводим строку к числу и выполняем математическую операцию
                $numHandler = $this->toNum();
                return $numHandler->operatorCall($operator, $varB);

            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '++':
            case '--':
                // Приводим строку к числу и выполняем унарную операцию
                $numHandler = $this->toNum();
                return $numHandler->operatorUnaryCall($operator);

            default:
                throw new \Exception("incorrect unary operator ".$operator." for ".__CLASS__);
        }
    }
    public function toString(): ?StringVarHandler
    {
        return $this;
    }

    public function toNum(): NumVarHandler
    {
        // Строка с точкой — десятичное число, иначе целое (без float).
        if (is_numeric($this->var) && strpos((string)$this->var, '.') !== false) {
            return new DecimalVarHandler('temp', $this->var, null, $this->storage);
        }
        $value = (int)$this->var;
        return new NumVarHandler('temp', $value, null, $this->storage);
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