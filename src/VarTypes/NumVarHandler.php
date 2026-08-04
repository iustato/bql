<?php

namespace iustato\Bql\VarTypes;

use InvalidArgumentException;
use iustato\Bql\VariableStorage;

/**
 * Обработчик ЦЕЛЫХ чисел (int).
 *
 * Понятие float в интерпретаторе отсутствует полностью: любое число с дробной
 * частью обрабатывается через {@see DecimalVarHandler} (строка + bcmath).
 * Здесь живут только целые. Если во время операции второй операнд оказывается
 * десятичным, вычисление «поднимается» до decimal. Целочисленное деление,
 * не делящееся нацело, также даёт decimal — чтобы не появлялся float.
 */
class NumVarHandler extends SimpleVarHandler
{
    protected $var;

    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        $this->var = $this->coerceValue($var);
        if (!isset($this->type) || $this->type === '') {
            $this->type = 'number';
        }
    }

    /**
     * Приведение входного значения к внутреннему представлению (int).
     * Переопределяется в {@see DecimalVarHandler}.
     */
    protected function coerceValue($var)
    {
        return is_numeric($var) ? (int)$var : 0;
    }

    public static function supports($variable): bool
    {
        // Только целые. Числа с плавающей точкой перехватывает DecimalVarHandler.
        return is_int($variable);
    }

    /** Представление целого числа как decimal (для смешанных операций). */
    public function toDecimal(): DecimalVarHandler
    {
        $value = (string)$this->var;
        return new DecimalVarHandler('temp', $value, null, $this->storage);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        // Если второй операнд десятичный — повышаем точность и считаем через bcmath.
        if ($operator !== '=' && $varB !== null && $varB->getType() === 'decimal') {
            return $this->toDecimal()->operatorCall($operator, $varB);
        }

        switch ($operator)
        {
            case '=':
                return $varB;
            case '+':
                $value = $this->var + (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '-':
                $value = $this->var - (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '*':
                $value = $this->var * (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                return new NumVarHandler($anonymousName, $value, null, $this->storage);
            case '/':
                $bValue = (int)$varB->get();
                if ($bValue === 0) {
                    throw new \Exception("Division by zero");
                }
                // Делится нацело — остаёмся целым; иначе результат десятичный.
                if ($this->var % $bValue === 0) {
                    $value = intdiv($this->var, $bValue);
                    $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
                    return new NumVarHandler($anonymousName, $value, null, $this->storage);
                }
                return $this->toDecimal()->operatorCall('/', $varB);
            case '+=':
                $this->var += (int)$varB->get();
                return $this;
            case '-=':
                $this->var -= (int)$varB->get();
                return $this;
            case '==':
                $value = $this->var == (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '!=':
                $value = $this->var != (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '>':
                $value = $this->var > (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '<':
                $value = $this->var < (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '>=':
                $value = $this->var >= (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '<=':
                $value = $this->var <= (int)$varB->get();
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                $value = in_array($this->var, $varB->get());
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
                return new BoolVarHandler($anonymousName, $value, null, $this->storage);
            case '.':
                $strHandler = $this->toString();
                return $strHandler->operatorCall($operator, $varB);
            case '&&':
            case 'and':
                // Для числовых значений: 0 считается false, остальные - true
                $thisValue = ($this->var != 0);
                $otherValue = ($varB->get() != 0);
                $value = $thisValue && $otherValue;
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

    public function toString(): StringVarHandler
    {
        $value = (string)$this->var;
        return new StringVarHandler('temp', $value, null, $this->storage);
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

        $value = (int)$var->get();
        return new NumVarHandler('temp', $value, null, $this->storage);
    }

    public function has(string $key): string
    {
        return '';
    }
}
