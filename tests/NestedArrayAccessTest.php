<?php
// tests/NestedArrayAccessTest.php
namespace TestProj\Tests;

use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * Доступ к элементам массива любой вложенности через точку: payment.sender.name.
 *
 * Проверяется и чтение, и запись по ссылке — как для строковых ключей, так и для
 * числовых индексов вложенных списков.
 */
class NestedArrayAccessTest extends TestCase
{
    private const PAYMENT_JSON = '[{"sender":{"name":"Иван","bank":{"bic":"044525225","title":"Сбер"}},"items":[[1,2],[3,4]],"amount":1500}]';

    private ExpressionInterpreter $interpreter;
    private array $payment;
    private mixed $r;

    protected function setUp(): void
    {
        $this->payment = [];
        $this->r = null;
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'payment' => &$this->payment,
            'r' => &$this->r,
        ]);
        $this->interpreter->evaluate('payment = ' . self::PAYMENT_JSON);
    }

    public function testReadAtEveryDepth(): void
    {
        $this->interpreter->evaluate('r = payment.amount');
        $this->assertSame(1500, $this->r);

        $this->interpreter->evaluate('r = payment.sender.name');
        $this->assertSame('Иван', $this->r);

        $this->interpreter->evaluate('r = payment.sender.bank.bic');
        $this->assertSame('044525225', $this->r);

        $this->interpreter->evaluate('r = payment.sender.bank.title');
        $this->assertSame('Сбер', $this->r);
    }

    public function testReadNumericIndexes(): void
    {
        // Индекс '0' в PHP «пустой», поэтому раньше такое обращение молча
        // возвращало весь массив вместо элемента.
        $this->interpreter->evaluate('r = payment.items.0');
        $this->assertSame([1, 2], $this->r);

        $this->interpreter->evaluate('r = payment.items.0.1');
        $this->assertSame(2, $this->r);

        $this->interpreter->evaluate('r = payment.items.1.0');
        $this->assertSame(3, $this->r);
    }

    public function testMissingKeyIsNull(): void
    {
        $this->interpreter->evaluate('r = payment.sender.missing');
        $this->assertNull($this->r);

        $this->interpreter->evaluate('r = payment.sender.bank.missing.deeper');
        $this->assertNull($this->r);
    }

    public function testNestedValuesInExpressions(): void
    {
        $this->interpreter->evaluate('r = payment.amount + 500');
        $this->assertEquals(2000, $this->r);

        $this->interpreter->evaluate("r = payment.sender.name . ' / ' . payment.sender.bank.title");
        $this->assertSame('Иван / Сбер', $this->r);

        $this->interpreter->evaluate('r = payment.amount > 1000 && payment.sender.bank.bic == 044525225');
        $this->assertTrue($this->r);
    }

    public function testWriteAtEveryDepth(): void
    {
        $this->interpreter->evaluate("payment.sender.name = 'Пётр'");
        $this->assertSame('Пётр', $this->payment['sender']['name']);

        $this->interpreter->evaluate("payment.sender.bank.bic = '000000000'");
        $this->assertSame('000000000', $this->payment['sender']['bank']['bic']);

        // Остальные ветки структуры не должны пострадать.
        $this->assertSame('Сбер', $this->payment['sender']['bank']['title']);
        $this->assertSame(1500, $this->payment['amount']);
    }

    public function testWriteNewKey(): void
    {
        $this->interpreter->evaluate("payment.sender.bank.newKey = 'added'");

        $this->assertSame('added', $this->payment['sender']['bank']['newKey']);
    }

    public function testWriteNumericIndex(): void
    {
        $this->interpreter->evaluate('payment.items.0.0 = 99');

        $this->assertSame([[99, 2], [3, 4]], $this->payment['items']);
    }

    public function testWriteComputedValue(): void
    {
        $this->interpreter->evaluate('payment.amount = payment.amount * 2');

        $this->assertEquals(3000, $this->payment['amount']);
    }

    public function testToJsonRoundTrip(): void
    {
        $this->interpreter->evaluate('r = payment.toJSON()');

        $this->assertSame($this->payment, json_decode($this->r, true));
    }

    public function testToJsonOnNestedValue(): void
    {
        $this->interpreter->evaluate('r = payment.sender.bank.toJSON()');

        $this->assertSame(['bic' => '044525225', 'title' => 'Сбер'], json_decode($this->r, true));
    }

    public function testToStringAndToNumStillWork(): void
    {
        $this->interpreter->evaluate('r = payment.amount.toString()');
        $this->assertSame('1500', $this->r);

        $this->interpreter->evaluate('r = payment.sender.bank.bic.toNum()');
        $this->assertEquals(44525225, $this->r);
    }

    public function testAccessAfterAssignmentChangesType(): void
    {
        // Переменная объявлена как null, массив ей присвоило выражение — обращение
        // по точке всё равно должно работать.
        $interpreter = new ExpressionInterpreter();
        $cfg = null;
        $out = null;
        $interpreter->setVariables(['cfg' => &$cfg, 'out' => &$out]);

        $interpreter->evaluate('cfg = [{"a":{"b":7}}]; out = cfg.a.b;');

        $this->assertSame(7, $out);
    }
}
