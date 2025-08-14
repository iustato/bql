<?php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class SimpleTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private array $data;
    private array $results;

    protected function setUp(): void
    {
        $this->data = [
            'country' => 'MDA',
            'name' => 'Ebun Hatab Abli Babah',
            'email' => 'ebunbabah@gmail.com',
            'age' => 42, // the answer
        ];

        $this->results = [
            'isAllowedCountry' => false,
            'isUniversalAnswer' => false,
            'isEmail' => false,
            'isNormalAge' => false,
            'isNormalName' => false,
            'isUndefinedKey' => false,
            'testrez' => false,
            'testconcat' => ''
        ];

        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'data' => $this->data,
            'results' => &$this->results,
        ]);
    }

    public function testIsAllowedCountry(): void
    {
        $this->interpreter->evaluate(
            "results.isAllowedCountry = (data.country in ['USA', 'MDA', 'CAD'])"
        );

        $this->assertTrue($this->results['isAllowedCountry']);
    }

    public function testIsUniversalAnswer(): void
    {
        $this->interpreter->evaluate(
            "results.isUniversalAnswer = (data.age == 42)"
        );

        $this->assertTrue($this->results['isUniversalAnswer']);
    }

    public function testIsNormalAge(): void
    {
        $this->interpreter->evaluate(
            "results.isNormalAge = (data.age > 5 && data.age < 120)"
        );

        $this->assertTrue($this->results['isNormalAge']);
    }

    public function testIsUndefinedKey(): void
    {
        $this->interpreter->evaluate(
            "results.isUndefinedKey = (data.notExists ?? true)"
        );

        $this->assertTrue($this->results['isUndefinedKey']);
    }

    public function testIsEmail(): void
    {
        $this->interpreter->evaluate(
            "results.isEmail = data.email like '%@%'"
        );

        $this->assertTrue($this->results['isEmail']);
    }

    public function testIsNormalName(): void
    {
        $this->interpreter->evaluate("results.isNormalName = (data.name != 'John Doe')");

        $this->assertTrue($this->results['isNormalName']);
    }

    public function testUsedVariables(): void
    {
        $this->interpreter->evaluate("results.testrez = data.age.toNum() . 'hzhz'");

        $usedVars = $this->interpreter->getUsedVariables();
        $this->assertNotEmpty($usedVars);
    }
}
