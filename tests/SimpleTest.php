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
            'phoneNumber' => '0123456789',
            'zipCode' => '01234',
            'accountId' => '0098765'
        ];

        $this->results = [
            'isAllowedCountry' => false,
            'isUniversalAnswer' => false,
            'isEmail' => false,
            'isNormalAge' => false,
            'isNormalName' => false,
            'isUndefinedKey' => false,
            'testrez' => false,
            'testconcat' => '',
            'phoneMatch' => false,
            'zipMatch' => false,
            'phoneNotEqual' => false,
            'zipNotEqual' => false
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

    /**
     * Тест сравнения строк с ведущими нулями - важно убедиться,
     * что они сравниваются как строки, а не как числа
     */
    public function testStringComparisonWithLeadingZeros(): void
    {
        // Проверяем точное совпадение строки с ведущими нулями
        $this->interpreter->evaluate("results.phoneMatch = (data.phoneNumber.toString() == '0123456789')");
        $this->assertTrue($this->results['phoneMatch'], 'Phone number with leading zero should match exactly as string');

        $this->interpreter->evaluate("results.zipMatch = (data.zipCode == '01234')");
        $this->assertTrue($this->results['zipMatch'], 'Zip code with leading zero should match exactly as string');

    }

    /**
     * Тест конкатенации строк с ведущими нулями
     */
    public function testConcatenationWithLeadingZeros(): void
    {
        $this->interpreter->evaluate("results.testconcat = 'ID:' . data.accountId . '-END'");
        $this->assertEquals('ID:0098765-END', $this->results['testconcat'], 'Concatenation should preserve leading zeros');

        // Проверяем, что ведущие нули сохраняются при конкатенации
        $this->interpreter->evaluate("results.zipConcat = data.zipCode . '-' . data.phoneNumber");
        $this->assertEquals('01234-0123456789', $this->results['zipConcat'], 'Leading zeros should be preserved in concatenation');
    }
}