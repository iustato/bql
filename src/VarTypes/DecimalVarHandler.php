<?php

namespace iustato\Bql\VarTypes;

use InvalidArgumentException;
use iustato\Bql\VariableStorage;

/**
 * Обработчик десятичных чисел (чисел «с точкой»).
 *
 * В интерпретаторе НЕТ понятия float: любое число с дробной частью хранится
 * как нормализованная строка, а все арифметические операции и сравнения
 * выполняются через расширение bcmath. Это исключает ошибки округления
 * двоичной плавающей точки (например, 0.1 + 0.2 == 0.3).
 *
 * Наследуется от {@see NumVarHandler}, чтобы удовлетворять сигнатурам
 * toNum()/convertToMe() (которые обязаны возвращать NumVarHandler), но
 * полностью переопределяет всю арифметику под bcmath.
 */
class DecimalVarHandler extends NumVarHandler
{
    /** Точность (число знаков после точки) для промежуточных bcmath-операций. */
    public static int $scale = 20;

    protected $var;

    public function __construct($name, &$var, $parent = null, ?VariableStorage $storage = null)
    {
        // Родительский (Num) конструктор через coerceValue() положит в $this->var
        // нормализованную строку и выставит type = 'number'; поправляем тип.
        parent::__construct($name, $var, $parent, $storage);
        $this->type = 'decimal';
    }

    /**
     * Значение decimal-переменной — всегда нормализованная строка.
     */
    protected function coerceValue($var): string
    {
        return self::normalize(self::toDecimalString($var));
    }

    public static function supports($variable): bool
    {
        // Числа с плавающей точкой из хост-проекта «усыновляем» как decimal —
        // так float исчезает из модели значений интерпретатора.
        return is_float($variable);
    }

    /**
     * Нужно ли обрабатывать значение как decimal/bignum (а не как int).
     *
     * true для: float, чисел «с точкой», экспоненциальной записи и целых,
     * которые НЕ помещаются в диапазон PHP int (например, 20-значный номер
     * чека Receipt). Такие значения нельзя приводить к int — будет переполнение
     * и превращение во float с потерей точности.
     */
    public static function needsDecimal($value): bool
    {
        if (is_float($value)) {
            return true;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return false;
        }
        $s = trim((string)$value);
        if ($s === '' || !is_numeric($s)) {
            return false;
        }
        if (strpos($s, '.') !== false || stripos($s, 'e') !== false) {
            return true;
        }
        // Целое число-строка: decimal нужен, только если оно выходит за int.
        return bccomp($s, (string)PHP_INT_MAX, 0) > 0
            || bccomp($s, (string)PHP_INT_MIN, 0) < 0;
    }

    /**
     * Приводит произвольное значение к строке-десятичному числу без экспоненты.
     */
    protected static function toDecimalString($value): string
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
            // Разворачиваем экспоненциальную запись в обычную десятичную.
            if (stripos($s, 'e') !== false) {
                $s = number_format($value, self::$scale, '.', '');
            }
            return $s;
        }
        $s = trim((string)$value);
        if (!is_numeric($s)) {
            return '0';
        }
        // Строка в экспоненциальной записи → разворачиваем в обычную десятичную.
        if (stripos($s, 'e') !== false) {
            return number_format((float)$s, self::$scale, '.', '');
        }
        return $s;
    }

    /**
     * Каноническая форма: без ведущих/хвостовых нулей и без «-0».
     */
    protected static function normalize(string $s): string
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

    /** Возвращает второй операнд как строку-десятичное число. */
    private function operandString(?AbstractVariableHandler $varB): string
    {
        if ($varB === null) {
            return '0';
        }
        return self::toDecimalString($varB->get());
    }

    private function makeDecimal(string $value): DecimalVarHandler
    {
        $value = self::normalize($value);
        $anonymousName = $this->registerAnonymous(new DecimalVarHandler('temp', $value, null, $this->storage));
        return new DecimalVarHandler($anonymousName, $value, null, $this->storage);
    }

    private function makeBool(bool $value): BoolVarHandler
    {
        $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $value, null, $this->storage));
        return new BoolVarHandler($anonymousName, $value, null, $this->storage);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        $scale = self::$scale;

        switch ($operator) {
            case '=':
                return $varB;
            case '+':
                return $this->makeDecimal(bcadd($this->var, $this->operandString($varB), $scale));
            case '-':
                return $this->makeDecimal(bcsub($this->var, $this->operandString($varB), $scale));
            case '*':
                return $this->makeDecimal(bcmul($this->var, $this->operandString($varB), $scale));
            case '/':
                $b = $this->operandString($varB);
                if (bccomp($b, '0', $scale) === 0) {
                    throw new \Exception("Division by zero");
                }
                return $this->makeDecimal(bcdiv($this->var, $b, $scale));
            case '+=':
                $this->var = self::normalize(bcadd($this->var, $this->operandString($varB), $scale));
                return $this;
            case '-=':
                $this->var = self::normalize(bcsub($this->var, $this->operandString($varB), $scale));
                return $this;
            case '==':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) === 0);
            case '!=':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) !== 0);
            case '>':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) > 0);
            case '<':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) < 0);
            case '>=':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) >= 0);
            case '<=':
                return $this->makeBool(bccomp($this->var, $this->operandString($varB), $scale) <= 0);
            case 'in':
                if (!is_array($varB->get())) {
                    throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
                }
                return $this->makeBool(in_array($this->var, $varB->get()));
            case '.':
                return $this->toString()->operatorCall($operator, $varB);
            case '&&':
            case 'and':
                $thisValue = bccomp($this->var, '0', $scale) !== 0;
                $otherValue = ($varB->get() != 0);
                return $this->makeBool($thisValue && $otherValue);

            default:
                throw new \Exception("incorrect operator " . $operator . " for " . __CLASS__);
        }
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        switch ($operator) {
            case '++':
                $this->var = self::normalize(bcadd($this->var, '1', self::$scale));
                return $this;
            case '--':
                $this->var = self::normalize(bcsub($this->var, '1', self::$scale));
                return $this;

            default:
                throw new \Exception("incorrect unary operator " . $operator . " for " . __CLASS__);
        }
    }

    public function toString(): StringVarHandler
    {
        $value = $this->var;
        return new StringVarHandler('temp', $value, null, $this->storage);
    }

    public function toNum(): NumVarHandler
    {
        return $this;
    }

    /** Представление int-числа как decimal (используется при смешении типов). */
    public function toDecimal(): DecimalVarHandler
    {
        return $this;
    }

    public function convertToMe(AbstractVariableHandler $var): DecimalVarHandler
    {
        if ($var instanceof DecimalVarHandler) {
            return $var;
        }
        $value = self::toDecimalString($var->get());
        return new DecimalVarHandler('temp', $value, null, $this->storage);
    }

    public function has(string $key): string
    {
        return '';
    }
}
