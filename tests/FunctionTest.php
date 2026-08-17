<?php

namespace Tests\iustato\Bql;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;
use iustato\Bql\VarTypes\AbstractVariableHandler;

class FunctionTest extends TestCase
{
    private ExpressionInterpreter $interpreter;

    private array $results;
    protected function setUp(): void
    {
        $this->results = [];
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'results' => &$this->results,
            'a' => 10,
            'b' => 5,
            'c' => 2
        ]);
    }

    /**
     * Тесты для функции iif
     */
    public function testIifWithTrueCondition(): void
    {
        $this->interpreter->evaluate("results.rezYes = iif(true, 'yes', 'no')");
        
        $this->assertEquals('yes', $this->results['rezYes']);

        $this->interpreter->evaluate("results.rezNo = iif(false, 'yes', 'no')");

        $this->assertEquals('no', $this->results['rezNo']);
    }

    public function testIifWithExpressions(): void
    {
        $this->interpreter->evaluate("results.rez3 = iif(a > b, 'a is greater', 'b is greater or equal')");
        $this->assertEquals('a is greater', $this->results['rez3']);
    }

    /**
     * Тесты для функции max
     */
    public function testMaxWithTwoNumbers(): void
    {
        $this->interpreter->evaluate("results.max2 = max(5, 3)");
        $this->assertEquals(5, $this->results['max2']);
    }

    public function testMaxWithMultipleNumbers(): void
    {
        $this->interpreter->evaluate("results.maxM = max(1, 9, 3 * 100 + 1, 157, 7, 2)");
        $this->assertEquals(301, $this->results['maxM']);
    }

    /**
     * Тесты для min/max на числах с дробной частью.
     *
     * Сравнение идёт через bccomp — тем же способом, что и операторы '<'/'>',
     * поэтому функция и оператор не могут разойтись в ответе.
     */
    public function testMinMaxWithDecimals(): void
    {
        $this->interpreter->evaluate("results.min = min(10.22, 5.77, 3.15)");
        $this->assertSame('3.15', $this->results['min']);

        $this->interpreter->evaluate("results.max = max(10.22, 5.77, 3.15)");
        $this->assertSame('10.22', $this->results['max']);

        // Дробное против целого — сравниваются как числа, а не как строки.
        $this->interpreter->evaluate("results.mix = min(9.9, 10.11)");
        $this->assertSame('9.9', $this->results['mix']);
    }

    /**
     * Различие дальше 17-й значащей цифры: нативный min() свёл бы оба аргумента
     * к одному float, счёл их равными и вернул первый по счёту.
     */
    public function testMinMaxBeyondFloatPrecision(): void
    {
        $this->interpreter->evaluate("results.min = min(0.30000000000000001, 0.3)");
        $this->assertSame('0.3', $this->results['min']);

        $this->interpreter->evaluate("results.max = max(0.3, 0.30000000000000001)");
        $this->assertSame('0.30000000000000001', $this->results['max']);

        // Целые за пределами int: точность тоже не теряется.
        $this->interpreter->evaluate("results.big = min(18446744073709551617, 18446744073709551616)");
        $this->assertSame('18446744073709551616', $this->results['big']);
    }

    /** Результат нормализуется: '10.0' и 10 — одно и то же число. */
    public function testMinNormalizesResult(): void
    {
        $this->interpreter->evaluate("results.min = min(10.0, 10)");
        $this->assertSame(10, $this->results['min']);
    }

    /** Отрицательные аргументы. */
    public function testMinMaxWithNegativeNumbers(): void
    {
        $this->interpreter->evaluate("results.min = min(10.22, 5.77, -3.15)");
        $this->assertSame('-3.15', $this->results['min']);

        $this->interpreter->evaluate("results.max = max(-10, -3)");
        $this->assertSame(-3, $this->results['max']);
    }

    /** Аргументы-переменные, в том числе числовая строка из хост-проекта. */
    public function testMinWithVariables(): void
    {
        $this->interpreter->setVariables(['price' => 2.5, 'code' => '7.75']);

        $this->interpreter->evaluate("results.p = min(price, 3)");
        $this->assertSame('2.5', $this->results['p']);

        $this->interpreter->evaluate("results.c = max(code, 8)");
        $this->assertSame(8, $this->results['c']);
    }

    /** Нечисловые аргументы остаются на нативном сравнении. */
    public function testMinWithStrings(): void
    {
        $this->interpreter->evaluate("results.min = min('abc', 'abd')");
        $this->assertSame('abc', $this->results['min']);
    }

}