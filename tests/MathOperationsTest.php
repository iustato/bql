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
            'c' => 2
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

    public function testComparisonOperators(): void
    {
        $this->interpreter->evaluate("results.greater = a > b");
        $this->assertTrue($this->results['greater']);

        $this->interpreter->evaluate("results.lesser = a < b");
        $this->assertFalse($this->results['lesser']);

        $this->interpreter->evaluate("results.equal = a == (b * c)");
        $this->assertTrue($this->results['equal']); // 10 == 5*2
    }
}