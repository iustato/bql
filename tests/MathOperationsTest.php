<?php
// tests/MathOperationsTest.php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class MathOperationsTest extends TestCase
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
            'c' => 2,
            'price' => 2.5, // float из хост-проекта усыновляется как число (bcmath)
        ]);
    }

    public function testBasicArithmetic(): void
    {
        $this->interpreter->evaluate("results.sum = a + b");
        $this->assertEquals(15, $this->results['sum']);

        $this->interpreter->evaluate("results.diff = a - b");
        $this->assertEquals(5, $this->results['diff']);

        $this->interpreter->evaluate("results.mult = a * b");
        $this->assertEquals(50, $this->results['mult']);

        $this->interpreter->evaluate("results.div = a / b");
        $this->assertEquals(2, $this->results['div']);
    }

    public function testComplexExpressions(): void
    {
        $this->interpreter->evaluate("results.complex = (a + b) * c - a / b");
        $this->assertEquals(28, $this->results['complex']); // (10+5)*2 - 10/5 = 30 - 2 = 28

        $this->interpreter->evaluate("results.complex2 = a*5/1000");
        $this->assertEquals(0.05, $this->results['complex2']);
    }

    public function testOperatorPrecedence(): void
    {
        // Арифметика связывается сильнее сравнения: (a + b*c) сначала, потом ==.
        $this->interpreter->evaluate("results.p1 = a + b * c");
        $this->assertEquals(20, $this->results['p1']); // 10 + (5*2)

        // Без скобок: (1 + 2) == 3, а не 1 + (2 == 3).
        $this->interpreter->evaluate("results.p2 = 1 + 2 == 3");
        $this->assertTrue($this->results['p2']);

        // Сравнение связывается сильнее логического И.
        $this->interpreter->evaluate("results.p3 = a > b && b > c");
        $this->assertTrue($this->results['p3']);

        // То же для decimal (bcmath): 0.1 + 0.2 == 0.3 без ошибки точности.
        $this->interpreter->evaluate("results.p4 = 0.1 + 0.2 == 0.3");
        $this->assertTrue($this->results['p4']);
    }

    public function testComparisonOperators(): void
    {
        $this->interpreter->evaluate("results.greater = a > b");
        $this->assertTrue($this->results['greater']);

        $this->interpreter->evaluate("results.lesser = a < b");
        $this->assertFalse($this->results['lesser']);

        $this->interpreter->evaluate("results.equal = a == (b * c)");
        $this->assertTrue($this->results['equal']); // 10 == 5*2
    }

    /**
     * Числа «с точкой»: в интерпретаторе нет float, дробные значения хранятся
     * строкой и считаются через bcmath — без ошибок двоичной точности.
     */

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

    /** Смешение int и дробного повышает результат до строки-decimal. */
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

    /**
     * Унарный минус — знак числа.
     *
     * Токенайзер отдаёт обычный '-'; унарную форму ('u-') распознаёт
     * toReversePolishNotation() по позиции в потоке токенов.
     */
    public function testUnaryMinus(): void
    {
        $this->interpreter->evaluate("results.lit = -5");
        $this->assertSame(-5, $this->results['lit']);

        $this->interpreter->evaluate("results.dec = -5.25");
        $this->assertSame('-5.25', $this->results['dec']);

        $this->interpreter->evaluate("results.var = -a");
        $this->assertSame(-10, $this->results['var']);

        // Знак связывается сильнее умножения: (-2) * 3.
        $this->interpreter->evaluate("results.mul = -2 * 3");
        $this->assertSame(-6, $this->results['mul']);

        // Знак справа от бинарного оператора.
        $this->interpreter->evaluate("results.sub = 2 - -3");
        $this->assertSame(5, $this->results['sub']);

        $this->interpreter->evaluate("results.mixed = a * -b");
        $this->assertSame(-50, $this->results['mixed']);

        // Знак перед скобкой.
        $this->interpreter->evaluate("results.paren = -(a + b)");
        $this->assertSame(-15, $this->results['paren']);

        // Унарный плюс — тождество.
        $this->interpreter->evaluate("results.plus = +7");
        $this->assertSame(7, $this->results['plus']);

        // Дробный знак идёт через bcmath: -0.1 + 0.3 = 0.2 ровно.
        $this->interpreter->evaluate("results.bc = -0.1 + 0.3");
        $this->assertSame('0.2', $this->results['bc']);
    }

    /** Бинарный минус не превращается в знак там, где слева уже есть значение. */
    public function testUnaryMinusDoesNotBreakBinaryMinus(): void
    {
        $this->interpreter->evaluate("results.bin = a - b");
        $this->assertSame(5, $this->results['bin']);

        // Пробел только слева — всё равно вычитание.
        $this->interpreter->evaluate("results.tight = a -b");
        $this->assertSame(5, $this->results['tight']);

        // После постфиксного '++' значение готово, значит '-' бинарный.
        $this->interpreter->setVariables(['n' => 10]);
        $this->interpreter->evaluate("results.post = n++ - b");
        $this->assertSame(6, $this->results['post']); // n стал 11, 11 - 5

        // '--x' — это декремент, а не двойной знак.
        $this->interpreter->setVariables(['x' => 7]);
        $this->interpreter->evaluate("results.pre = --x");
        $this->assertSame(6, $this->results['pre']);
    }

    /** Знак не мутирует переменную, в отличие от '++'/'--'. */
    public function testUnaryMinusDoesNotModifyVariable(): void
    {
        $this->interpreter->evaluate("results.neg = -price; results.same = price");

        $this->assertSame('-2.5', $this->results['neg']);
        $this->assertSame('2.5', $this->results['same']);
    }

    /** Унарный инкремент десятичного числа. */
    public function testDecimalIncrement(): void
    {
        $this->interpreter->evaluate("price++");
        $modified = $this->interpreter->getModifiedVariables();

        $this->assertSame('3.5', $modified['price']);
    }

    /**
     * Сравнение длинного числового поля (напр. 13-значный taxNumber) со
     * строковым литералом работает независимо от того, как поле хранится в
     * хосте — как int или как string.
     */
    public function testLongNumberFieldComparison(): void
    {
        $this->interpreter->setVariables([
            'taxInt' => 1234567890123,     // хост: int (13 цифр влезают в int)
            'taxStr' => '1234567890123',   // хост: строка
        ]);

        // Поле-int против строкового литерала: приводится к числу (bccomp).
        $this->interpreter->evaluate("results.a = (taxInt == '1234567890123')");
        $this->assertTrue($this->results['a']);

        // Поле-строка против строкового литерала: точное равенство строк.
        $this->interpreter->evaluate("results.b = (taxStr == '1234567890123')");
        $this->assertTrue($this->results['b']);

        // И против числового литерала — тоже совпадает.
        $this->interpreter->evaluate("results.c = (taxInt == 1234567890123)");
        $this->assertTrue($this->results['c']);

        $this->interpreter->evaluate("results.d = (taxStr == 1234567890123)");
        $this->assertTrue($this->results['d']);

        // Несовпадение корректно даёт false.
        $this->interpreter->evaluate("results.e = (taxInt == '9999999999999')");
        $this->assertFalse($this->results['e']);
    }
}