<?php
// tests/NegatedOperatorsTest.php
namespace TestProj\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * Отрицающие словесные операторы 'not in' / 'not like'.
 *
 * До их появления 'not' был обычным идентификатором: он молча вытеснял левый
 * операнд, и 'x not in [...]' считалось как 'null in [...]' — то есть всегда
 * false. Поэтому здесь проверяется не только сам результат, но и то, что
 * потерянный оператор между значениями теперь падает с ошибкой.
 */
class NegatedOperatorsTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private array $results;

    protected function setUp(): void
    {
        $this->results = [];
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'results' => &$this->results,
            'country' => 'MDA',
            'age' => 42,
            'email' => 'test@example.com',
            'data' => ['country' => 'MDA'],
        ]);
    }

    public function testNotInWithStrings(): void
    {
        $this->interpreter->evaluate("results.absent = country not in ['USA', 'CAD']");
        $this->assertTrue($this->results['absent']);

        $this->interpreter->evaluate("results.present = country not in ['USA', 'MDA']");
        $this->assertFalse($this->results['present']);
    }

    /** 'not in' — точное отрицание 'in' на тех же данных. */
    public function testNotInIsExactNegationOfIn(): void
    {
        $this->interpreter->evaluate(
            "results.in = country in ['USA', 'MDA']; results.notIn = country not in ['USA', 'MDA']"
        );

        $this->assertNotSame($this->results['in'], $this->results['notIn']);
    }

    public function testNotInWithNumbers(): void
    {
        $this->interpreter->evaluate("results.odd = age not in [1, 2, 3]");
        $this->assertTrue($this->results['odd']);

        $this->interpreter->evaluate("results.listed = age not in [41, 42, 43]");
        $this->assertFalse($this->results['listed']);
    }

    /** Вложенная переменная слева разбирается так же, как обычная. */
    public function testNotInWithSausage(): void
    {
        $this->interpreter->evaluate("results.rez = data.country not in ['USA', 'CAD']");
        $this->assertTrue($this->results['rez']);
    }

    public function testNotLike(): void
    {
        $this->interpreter->evaluate("results.noXyz = email not like 'xyz%'");
        $this->assertTrue($this->results['noXyz']);

        $this->interpreter->evaluate("results.hasAt = email not like '%@%'");
        $this->assertFalse($this->results['hasAt']);
    }

    /** Регистр не важен — как и у 'in', 'and', 'or'. */
    public function testCaseInsensitive(): void
    {
        $this->interpreter->evaluate("results.upper = country NOT IN ['USA']");
        $this->assertTrue($this->results['upper']);

        $this->interpreter->evaluate("results.mixed = country Not In ['USA']");
        $this->assertTrue($this->results['mixed']);
    }

    /** Приоритет тот же, что у 'in': арифметика слева считается первой. */
    public function testPrecedenceMatchesIn(): void
    {
        $this->interpreter->evaluate("results.rez = 1 + 1 not in [2]");
        $this->assertFalse($this->results['rez']);
    }

    public function testComposesWithLogicalOperators(): void
    {
        $this->interpreter->evaluate("results.rez = country not in ['USA'] && age > 18");
        $this->assertTrue($this->results['rez']);
    }

    /**
     * 'not' распознаётся по позиции, поэтому имя переменной не занято.
     *
     * В позиции операнда это обычный идентификатор, в позиции оператора —
     * приставка. Та же логика, что различает '-a' и 'a - b'.
     */
    public function testNotIsStillUsableAsVariableName(): void
    {
        $notVariable = 'MDA';
        $this->interpreter->setVariables(['not' => &$notVariable]);

        $this->interpreter->evaluate("results.operand = not . '!'");
        $this->assertSame('MDA!', $this->results['operand']);

        // Слева операнд 'not', справа оператор 'not in' — оба слова на месте.
        $this->interpreter->evaluate("results.both = not not in ['USA']");
        $this->assertTrue($this->results['both']);
    }

    /**
     * Лишний идентификатор перед оператором — ошибка, а не тихий false.
     *
     * Раньше такое выражение вычислялось с потерянным левым операндом, поэтому
     * опечатка в правиле выглядела как честно посчитанный результат.
     */
    public function testStrayIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unexpected 'zzz'");

        $this->interpreter->evaluate("age zzz > 1");
    }

    /** 'not' с оператором, у которого нет отрицающей формы, тоже не проглатывается. */
    public function testNotBeforeUnsupportedOperatorThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unexpected 'not'");

        $this->interpreter->evaluate("age not > 1");
    }
}
