<?php
// tests/StringOperationsTest.php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class StringOperationsTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private array $results;

    protected function setUp(): void
    {
        $this->results = [];
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'results' => &$this->results,
            'text1' => 'Hello',
            'text2' => 'World',
            'email' => 'test@example.com',
            'name' => 'John Doe'
        ]);
    }

    public function testStringConcatenation(): void
    {
        $this->interpreter->evaluate("results.concat = text1 . ' ' . text2");
        $this->assertEquals('Hello World', $this->results['concat']);
    }

    public function testLikeOperator(): void
    {
        $this->interpreter->evaluate("results.hasAt = email like '%@%'");
        $this->assertTrue($this->results['hasAt']);

        $this->interpreter->evaluate("results.hasJohn = name like 'John%'");
        $this->assertTrue($this->results['hasJohn']);

        $this->interpreter->evaluate("results.noMatch = email like 'xyz%'");
        $this->assertFalse($this->results['noMatch']);
    }

    public function testStringComparison(): void
    {
        $this->interpreter->evaluate("results.equal = text1 == 'Hello'");
        $this->assertTrue($this->results['equal']);

        $this->interpreter->evaluate("results.notEqual = text1 != 'Goodbye'");
        $this->assertTrue($this->results['notEqual']);
    }
}