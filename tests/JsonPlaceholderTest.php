<?php
// tests/JsonPlaceholderTest.php
namespace TestProj\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * Подстановки '{{ выражение }}' внутри JSON-литералов (синтаксис в духе Twig).
 *
 * json_decode остаётся строгим: подстановка живёт внутри JSON-строки и потому
 * является валидным JSON. Вычисление идёт пост-проходом по разобранной структуре.
 */
class JsonPlaceholderTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private mixed $v;
    private array $payment;

    protected function setUp(): void
    {
        $this->v = null;
        $this->payment = [
            'ToAcc' => '40817810099910004312',
            'ToValute' => 'RUB',
            'Receipt' => 7788,
            'Recipient' => ['BpayAccountObject' => ['Sum' => 1500.55]],
        ];

        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'v' => &$this->v,
            'Payment' => &$this->payment,
            'n' => 7,
            'sum' => '1500.55',
            'flag' => true,
            'list' => [1, 2, 3],
        ]);
    }

    public function testWholeValuePlaceholderKeepsType(): void
    {
        // Строка целиком — одна подстановка: тип значения сохраняется.
        $this->interpreter->evaluate('v = [{"a":"{{ n }}"}]');
        $this->assertSame(7, $this->v['a']);

        $this->interpreter->evaluate('v = [{"a":"{{ flag }}"}]');
        $this->assertTrue($this->v['a']);

        $this->interpreter->evaluate('v = [{"a":"{{ list }}"}]');
        $this->assertSame([1, 2, 3], $this->v['a']);
    }

    public function testMoneyStaysString(): void
    {
        // Финансовые суммы обязаны остаться строками: float для них недопустим.
        $this->interpreter->evaluate('v = [{"amount":"{{ sum }}"}]');
        $this->assertSame('1500.55', $this->v['amount']);

        $this->interpreter->evaluate('v = [{"amount":"{{ Payment.Recipient.BpayAccountObject.Sum }}"}]');
        $this->assertSame('1500.55', $this->v['amount']);
    }

    public function testArbitraryExpressions(): void
    {
        $this->interpreter->evaluate('v = [{"a":"{{ n * 2 + 1 }}"}]');
        $this->assertEquals(15, $this->v['a']);

        $this->interpreter->evaluate('v = [{"a":"{{ iif(n > 5, \'big\', \'small\') }}"}]');
        $this->assertSame('big', $this->v['a']);
    }

    public function testSwitchInsidePlaceholder(): void
    {
        // Фигурные скобки switch не должны обмануть поиск закрывающих '}}'.
        $this->interpreter->evaluate('v = [{"a":"{{ switch { case n > 5: \'big\'; default: \'small\' } }}"}]');

        $this->assertSame('big', $this->v['a']);
    }

    public function testInlinePlaceholderBuildsString(): void
    {
        $this->interpreter->evaluate('v = [{"a":"receipt {{ Payment.Receipt }} ok"}]');
        $this->assertSame('receipt 7788 ok', $this->v['a']);

        $this->interpreter->evaluate('v = [{"a":"{{ n }}/{{ sum }}"}]');
        $this->assertSame('7/1500.55', $this->v['a']);

        // В текст массив и bool попадают своим строковым представлением.
        $this->interpreter->evaluate('v = [{"a":"list={{ list }} flag={{ flag }}"}]');
        $this->assertSame('list=[1,2,3] flag=true', $this->v['a']);
    }

    public function testPlaceholderAtAnyDepth(): void
    {
        $this->interpreter->evaluate('v = [{"outer":{"inner":{"deep":"{{ n }}"}}}]');
        $this->assertSame(7, $this->v['outer']['inner']['deep']);

        $this->interpreter->evaluate('v = [1, "{{ n }}", 3]');
        $this->assertSame([1, 7, 3], $this->v);
    }

    public function testPlaceholderInKey(): void
    {
        $this->interpreter->evaluate('v = [{"{{ Payment.ToValute }}":100}]');

        $this->assertSame(['RUB' => 100], $this->v);
    }

    public function testMissingVariable(): void
    {
        // Значение целиком — null; внутри текста — пустая подстановка.
        $this->interpreter->evaluate('v = [{"a":"{{ nosuch }}"}]');
        $this->assertNull($this->v['a']);

        $this->interpreter->evaluate('v = [{"a":"x={{ nosuch }}"}]');
        $this->assertSame('x=', $this->v['a']);
    }

    public function testLiteralBracesViaStringPlaceholder(): void
    {
        // Собственный синтаксис экранирования не нужен: выражение со строкой
        // возвращает её как есть — тот же приём, что в Twig.
        $this->interpreter->evaluate('v = [{"a":"{{ \'{{\' }}not a placeholder}}"}]');
        $this->assertSame('{{not a placeholder}}', $this->v['a']);

        // Закрывающие скобки внутри строки выражения не завершают подстановку.
        $this->interpreter->evaluate('v = [{"a":"{{ \'}}\' }}"}]');
        $this->assertSame('}}', $this->v['a']);
    }

    public function testTextWithoutPlaceholdersUnchanged(): void
    {
        $this->interpreter->evaluate('v = [{"a":"обычный текст"}]');

        $this->assertSame('обычный текст', $this->v['a']);
    }

    public function testUnterminatedPlaceholderThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unterminated placeholder/');

        $this->interpreter->evaluate('v = [{"a":"{{ n "}]');
    }

    public function testBrokenExpressionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Operator '\+\+\+' is not defined/");

        $this->interpreter->evaluate('v = [{"a":"{{ n +++ }}"}]');
    }

    public function testRealPayoutConfig(): void
    {
        $payload = null;
        $this->interpreter->setVariables([
            'v' => &$this->v,
            'Payment' => &$this->payment,
            'payload' => &$payload,
        ]);

        $this->interpreter->evaluate('v = [{'
            . '"paymethod": "sapi_payout",'
            . '"exaccount": "{{ Payment.ToAcc }}",'
            . '"service": "business_498",'
            . '"servaccount": "14577263",'
            . '"amount": "{{ Payment.Recipient.BpayAccountObject.Sum }}",'
            . '"valute": "{{ Payment.ToValute }}",'
            . '"description": "automatic payout to business acc {{ Payment.Receipt.toString() }}"'
            . '}]');

        $this->assertSame([
            'paymethod' => 'sapi_payout',
            'exaccount' => '40817810099910004312',
            'service' => 'business_498',
            'servaccount' => '14577263',
            'amount' => '1500.55',
            'valute' => 'RUB',
            'description' => 'automatic payout to business acc 7788',
        ], $this->v);

        // Порядок ключей сохраняется, поэтому payload воспроизводит конфиг.
        $this->interpreter->evaluate('payload = v.toJSON()');
        $this->assertSame($this->v, json_decode($payload, true));
    }
}
