<?php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class SimpleTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private array $data;
    private array $results;
    private object $nestedObject;

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
            'zipNotEqual' => false,
            'nestedResult' => ''
        ];

        // Создаем вложенный объект для тестирования отслеживания использованных переменных
        $this->nestedObject = (object)[
            'user' => (object)[
                'profile' => (object)[
                    'settings' => (object)[
                        'language' => 'ru',
                        'theme' => 'dark',
                        'notifications' => true
                    ],
                    'info' => (object)[
                        'firstName' => 'Иван',
                        'lastName' => 'Петров'
                    ]
                ]
            ],
            'config' => (object)[
                'database' => (object)[
                    'host' => 'localhost',
                    'port' => 3306
                ]
            ]
        ];

        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'data' => $this->data,
            'results' => &$this->results,
            'nested' => $this->nestedObject
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
        $this->assertEquals('42hzhz',$usedVars['results.testrez']);
    }

    public function testUsedVariablesWithNestedObjects(): void
    {
        // Очищаем предыдущие использованные переменные
        //$this->interpreter->variableStorage->getUsedVariables();

        // Выполняем выражение с вложенными свойствами объекта
        $this->interpreter->evaluate("results.nestedResult = nested.user.profile.settings.language");

        $usedVars = $this->interpreter->getUsedVariables();

        // Проверяем, что вложенная переменная была отмечена как использованная
        $this->assertArrayHasKey('nested.user.profile.settings.language', $usedVars,
            'Nested variable nested.user.profile.settings.language should be tracked in used variables');

        ;
        // Проверяем, что значение корректно сохранилось
        $this->assertEquals('ru', $usedVars['nested.user.profile.settings.language'],'Used variable should contain the correct value');

        // Проверяем, что результат выражения корректный
        $this->assertEquals('ru', $this->results['nestedResult'], 'Expression result should be correct');
    }

    public function testMultipleNestedVariablesTracking(): void
    {
        $expression = "results.nestedResult = nested.user.profile.info.firstName . ' ' . nested.user.profile.info.lastName";

        $this->interpreter->evaluate($expression);

        $usedVars = $this->interpreter->getUsedVariables();

        // Проверяем, что обе вложенные переменные отслеживаются
        $this->assertArrayHasKey('nested.user.profile.info.firstName', $usedVars,
            'First nested variable should be tracked');
        $this->assertArrayHasKey('nested.user.profile.info.lastName', $usedVars,
            'Second nested variable should be tracked');

        // Проверяем значения
        $this->assertEquals('Иван', $usedVars['nested.user.profile.info.firstName']);
        $this->assertEquals('Петров', $usedVars['nested.user.profile.info.lastName']);

        // Проверяем результат конкатенации
        $this->assertEquals('Иван Петров', $this->results['nestedResult']);
    }

    /**
     * Тест отслеживания переменных при использовании в условных выражениях
     */
    public function testNestedVariablesInConditionalExpressions(): void
    {
        $expression = "results.testrez = (nested.config.database.port == 3306)";

        $this->interpreter->evaluate($expression);

        $usedVars = $this->interpreter->getUsedVariables();

        // Проверяем, что переменная отслеживается даже в условном выражении
        $this->assertArrayHasKey('nested.config.database.port', $usedVars,
            'Nested variable in conditional expression should be tracked');

        $this->assertEquals(3306, $usedVars['nested.config.database.port']);
        $this->assertTrue($this->results['testrez']);
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