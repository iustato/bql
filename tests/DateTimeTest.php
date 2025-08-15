<?php
// tests/DateTimeTest.php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

class DateTimeTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private array $results;

    protected function setUp(): void
    {
        $this->results = [];
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'results' => &$this->results,
            'dateTime' => '2025-05-17 15:30:00'
        ]);
    }

    public function testDateTimeComponents(): void
    {
        $this->interpreter->evaluate("results.hour = dateTime.Hour");
        $this->assertEquals(15, $this->results['hour']);

        $this->interpreter->evaluate("results.year = dateTime.Year");
        $this->assertEquals(2025, $this->results['year']);

        $this->interpreter->evaluate("results.month = dateTime.Month");
        $this->assertEquals(5, $this->results['month']);
    }

    public function testDateTimeArithmetic(): void
    {
        $this->interpreter->evaluate("results.newDateTime = dateTime + '15 minute'");
        $this->assertEquals('2025-05-17 15:45:00', $this->results['newDateTime']);
    }

    public function testTimeRangeCheck(): void
    {
        $this->interpreter->evaluate("results.businessHours = (dateTime.Hour >= 9 && dateTime.Hour < 17)");
        $this->assertTrue($this->results['businessHours']);
    }
}