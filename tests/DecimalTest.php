<?php
// tests/DecimalTest.php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * Тесты десятичного типа (числа «с точкой»).
 *
 * В интерпретаторе нет float: дробные числа хранятся как строки и считаются
 * через bcmath. Значение результата — строка, без ошибок двоичной точности.
 */
class DecimalTest extends TestCase
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
            'price' => 2.5, // float из хост-проекта усыновляется как decimal
        ]);
    }

    /** Значение дробного числа хранится строкой, а не float. */
    public function testDecimalIsStoredAsString(): void
    {
        $this->interpreter->evaluate("results.x = 1.5 + 2.25");

        $this->assertSame('3.75', $this->results['x']);
        $this->assertIsString($this->results['x']);
    }

    /** Классическая проверка точности: 0.1 + 0.2 === 0.3 (не 0.30000000004). */
    public function testNoFloatingPointError(): void
    {
        $this->interpreter->evaluate("results.sum = 0.1 + 0.2");

        $this->assertSame('0.3', $this->results['sum']);
    }

    /** Деление целых, не делящихся нацело, даёт decimal (без float). */
    public function testIntegerDivisionProducesDecimal(): void
    {
        $this->interpreter->evaluate("results.d = 50 / 1000");
        $this->assertSame('0.05', $this->results['d']);

        // Делится нацело — остаётся целым.
        $this->interpreter->evaluate("results.even = a / b");
        $this->assertSame(2, $this->results['even']);
    }

    /** Смешение int и decimal повышает результат до decimal. */
    public function testMixedIntAndDecimal(): void
    {
        $this->interpreter->evaluate("results.m = a + 0.5");
        $this->assertSame('10.5', $this->results['m']);

        // Целый результат — настоящий int, даже если операнд был дробным:
        // 2.5 * 4 = 10 (не '10').
        $this->interpreter->evaluate("results.mul = price * 4");
        $this->assertSame(10, $this->results['mul']);
    }

    /** Сравнения выполняются через bccomp. */
    public function testDecimalComparisons(): void
    {
        $this->interpreter->evaluate("results.gt = (0.3 > 0.1)");
        $this->assertTrue($this->results['gt']);

        $this->interpreter->evaluate("results.eq = ((0.1 + 0.2) == 0.3)");
        $this->assertTrue($this->results['eq']);

        $this->interpreter->evaluate("results.le = (2.50 <= price)");
        $this->assertTrue($this->results['le']);
    }

    /**
     * Арифметика не переполняется во float: результат, вылезающий за диапазон
     * PHP int, отдаётся строкой с полной точностью (bcmath).
     */
    public function testNoIntegerOverflow(): void
    {
        $this->interpreter->evaluate("results.ovf = 9223372036854775807 + 1");
        $this->assertSame('9223372036854775808', $this->results['ovf']);

        $this->interpreter->evaluate("results.mul = 9999999999 * 9999999999");
        $this->assertSame('99999999980000000001', $this->results['mul']);
    }

    /** Унарный инкремент десятичного числа. */
    public function testDecimalIncrement(): void
    {
        $this->interpreter->evaluate("price++");
        $modified = $this->interpreter->getModifiedVariables();

        $this->assertSame('3.5', $modified['price']);
    }
}
