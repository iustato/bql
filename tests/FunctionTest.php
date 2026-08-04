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

}