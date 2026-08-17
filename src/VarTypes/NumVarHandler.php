<?php

namespace iustato\Bql\VarTypes;

use InvalidArgumentException;
use iustato\Bql\VariableStorage;

/**
 * Единый обработчик ЧИСЕЛ.
 *
 * В интерпретаторе НЕТ понятия float. Число — это целое либо десятичное; и то,
 * и другое суть одно и то же значение произвольной точности, поэтому обработчик
 * один. Внутреннее представление ($this->var):
 *   - настоящий PHP int, если значение целое и помещается в диапазон int
 *     (удобно хосту: `results['sum'] === 15`);
 *   - нормализованная строка — если есть дробная часть ИЛИ целое не влезает в
 *     int (например, 20-значный номер чека). Так исключаются и float, и
 *     переполнение int с потерей точности.
 *
 * Вся арифметика и сравнения выполняются через **bcmath** (`bcadd/bcsub/bcmul/
 * bcdiv/bccomp`), поэтому переполнения не бывает в принципе, а 0.1 + 0.2 == 0.3.
 */
class NumVarHandler extends SimpleVarHandler
{
    /** Точность (число знаков после точки) для промежуточных bcmath-операций. */
    public static int $scale = 20;

    protected $var;

    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        $this->var = self::present(self::toNumericString($var));
        $this->type = 'number';
    }

    public static function supports($variable): bool
    {
        // int и float из хост-проекта — числа. Float тут же превращается в
        // строку/int (present), так что в модели значений float не остаётся.
        return is_int($variable) || is_float($variable);
    }

    /**
     * Приводит произвольное значение к нормализованной десятичной строке
     * (без экспоненты и без хвостовых нулей). Пригодно как операнд bcmath.
     */
    public static function toNumericString($value): string
    {
        if ($value instanceof AbstractVariableHandler) {
            $value = $value->get();
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return '0';
            }
            $s = (string)$value;
            if (stripos($s, 'e') !== false) {
                $s = number_format($value, self::$scale, '.', '');
            }
            return self::normalize($s);
        }
        $s = trim((string)$value);
        if (!is_numeric($s)) {
            return '0';
        }
        // Экспоненциальная запись строкой → разворачиваем в обычную десятичную.
        if (stripos($s, 'e') !== false) {
            return self::normalize(number_format((float)$s, self::$scale, '.', ''));
        }
        return self::normalize($s);
    }

    /**
     * Каноническая форма десятичной строки: без ведущих/хвостовых нулей и «-0».
     */
    public static function normalize(string $s): string
    {
        if ($s === '') {
            return '0';
        }

        $neg = false;
        if ($s[0] === '+') {
            $s = substr($s, 1);
        } elseif ($s[0] === '-') {
            $neg = true;
            $s = substr($s, 1);
        }

        if (strpos($s, '.') === false) {
            $s = ltrim($s, '0');
            $s = $s === '' ? '0' : $s;
            return ($neg && $s !== '0' ? '-' : '') . $s;
        }

        [$int, $frac] = explode('.', $s, 2);
        $int = ltrim($int, '0');
        $frac = rtrim($frac, '0');
        if ($int === '') {
            $int = '0';
        }
        $out = $frac === '' ? $int : $int . '.' . $frac;

        return ($neg && $out !== '0' ? '-' : '') . $out;
    }

    /**
     * Выбирает внешнее представление нормализованной строки: настоящий int,
     * если значение целое и в диапазоне PHP int, иначе — строку.
     *
     * @return int|string
     */
    public static function present(string $s)
    {
        if (strpos($s, '.') === false
            && bccomp($s, (string)PHP_INT_MAX, 0) <= 0
            && bccomp($s, (string)PHP_INT_MIN, 0) >= 0) {
            return (int)$s;
        }
        return $s;
    }

    /** Строковое (bcmath-совместимое) представление текущего значения. */
    private function selfString(): string
    {
        return (string)$this->var;
    }

    /** Строковое представление операнда. */
    private function operandString(?AbstractVariableHandler $varB): string
    {
        return $varB === null ? '0' : self::toNumericString($varB->get());
    }

    private function makeNumber(string $result): NumVarHandler
    {
        $value = self::present(self::normalize($result));
        $anonymousName = $this->registerAnonymous(new NumVarHandler('temp', $value, null, $this->storage));
        return new NumVarHandler($anonymousName, $value, null, $this->storage);
    }

    private function makeBool(bool $value): BoolVarHandler
    {
        $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
        return new BoolVarHandler($anonymousName, $value, null, $this->storage);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        $scale = self::$scale;
        $a = $this->selfString();

        switch ($operator)
        {
            case '=':
                return $varB;
            case '+':
                return $this->makeNumber(bcadd($a, $this->operandString($varB), $scale));
            case '-':
                return $this->makeNumber(bcsub($a, $this->operandString($varB), $scale));
            case '*':
                return $this->makeNumber(bcmul($a, $this->operandString($varB), $scale));
            case '/':
                $b = $this->operandString($varB);
                if (bccomp($b, '0', $scale) === 0) {
                    throw new \Exception("Division by zero");
                }
                return $this->makeNumber(bcdiv($a, $b, $scale));
            case '+=':
                $this->var = self::present(self::normalize(bcadd($a, $this->operandString($varB), $scale)));
                return $this;
            case '-=':
                $this->var = self::present(self::normalize(bcsub($a, $this->operandString($varB), $scale)));
                return $this;
            case '==':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) === 0);
            case '!=':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) !== 0);
            case '>':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) > 0);
            case '<':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) < 0);
            case '>=':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) >= 0);
            case '<=':
                return $this->makeBool(bccomp($a, $this->operandString($varB), $scale) <= 0);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                return $this->makeBool(in_array($this->var, $varB->get()));
            case '.':
                return $this->toString()->operatorCall($operator, $varB);
            case '&&':
            case 'and':
                // 0 — false, остальное — true.
                $thisValue = bccomp($a, '0', $scale) !== 0;
                $otherValue = ($varB->get() != 0);
                return $this->makeBool($thisValue && $otherValue);

            default:
                throw new \Exception("incorrect operator ".$operator." for ".__CLASS__);
        }
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        switch ($operator)
        {
            case '++':
                $this->var = self::present(self::normalize(bcadd($this->selfString(), '1', self::$scale)));
                return $this;
            case '--':
                $this->var = self::present(self::normalize(bcsub($this->selfString(), '1', self::$scale)));
                return $this;
            case 'u-':
                // Знак числа: переменную не меняем, отдаём новое значение.
                return $this->makeNumber(bcsub('0', $this->selfString(), self::$scale));
            case 'u+':
                return $this->makeNumber($this->selfString());

            default:
                throw new \Exception("incorrect unary operator ".$operator." for ".__CLASS__);
        }
    }

    public function toString(): StringVarHandler
    {
        $value = $this->selfString();
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

        $value = self::present(self::toNumericString($var->get()));
        return new NumVarHandler('temp', $value, null, $this->storage);
    }

    public function has(string $key): string
    {
        return '';
    }
}
