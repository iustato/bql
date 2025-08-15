<?php

namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class AccessViaMethodsTest extends TestCase
{
    private ExpressionInterpreter $interpreter;

    protected function setUp(): void
    {
        $this->interpreter = new ExpressionInterpreter();
    }

    public function test_access_via_methods(): void
    {
        $class = new AccessViaMethods();

        $initial = rand(1, 100); // Заменил fake() на rand() для совместимости
        $class->setCounter($initial);

        $this->interpreter->setVariables(['class' => $class]);

        $this->interpreter->evaluate("class.counter++");
        $this->assertEquals($initial + 1, $class->getCounter());
    }

    public function test_access_via_methods_with_decrement(): void
    {
        $class = new AccessViaMethods();

        $initial = rand(50, 100);
        $class->setCounter($initial);

        $this->interpreter->setVariables(['class' => $class]);

        $this->interpreter->evaluate("class.counter--");
        $this->assertEquals($initial - 1, $class->getCounter());
    }

    public function test_access_via_methods_with_assignment(): void
    {
        $class = new AccessViaMethods();
        $newValue = rand(200, 300);

        $this->interpreter->setVariables(['class' => $class]);

        $this->interpreter->evaluate("class.counter = $newValue");
        $this->assertEquals($newValue, $class->getCounter());
    }

    public function test_access_via_methods_multiple_operations(): void
    {
        $class = new AccessViaMethods();
        $initial = 10;
        $class->setCounter($initial);

        $result = [];
        $this->interpreter->setVariables([
            'class' => $class,
            'result' => &$result
        ]);

        // Тестируем несколько операций подряд
        $this->interpreter->evaluate("
            class.counter++; 
            result.afterIncrement = class.counter; 
            class.counter += 5; 
            result.afterAdd = class.counter
        ");

        $this->assertEquals($initial + 1, $result['afterIncrement']);
        $this->assertEquals($initial + 1 + 5, $result['afterAdd']);
        $this->assertEquals($initial + 6, $class->getCounter());
    }

    public function test_access_via_methods_debug(): void
    {
        $class = new AccessViaMethods();

        $initial = 50; // Фиксированное значение для отладки
        $class->setCounter($initial);

        echo "\nИсходное значение: " . $class->getCounter() . "\n";

        $this->interpreter->setVariables(['class' => $class]);

        // Включаем отладку
        error_reporting(E_ALL);
        ini_set('log_errors', 1);
        ini_set('error_log', '/tmp/bql_debug.log');
        
        echo "Выполняем class.counter++\n";
        $this->interpreter->evaluate("class.counter++");
        
        echo "Значение после инкремента: " . $class->getCounter() . "\n";
        
        $usedVars = $this->interpreter->getUsedVariables();
        echo "Использованные переменные:\n";
        print_r($usedVars);
        
        $this->assertEquals($initial + 1, $class->getCounter());
    }
}

class AccessViaMethods
{
    private int $counter = 0;

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function setCounter(int $counter): void
    {
        $this->counter = $counter;
    }
}