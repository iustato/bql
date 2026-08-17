<?php
// tests/SwitchExpressionTest.php
namespace TestProj\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * switch как выражение, возвращающее значение.
 *
 *   risk = switch { case amount >= 10000: 'high'; default: 'none' };
 */
class SwitchExpressionTest extends TestCase
{
    /** Вычисляет выражение с заданными переменными и возвращает значение 'r'. */
    private function evaluate(string $expression, array $variables = []): mixed
    {
        $interpreter = new ExpressionInterpreter();

        $variables['r'] = null;
        $bound = [];
        foreach ($variables as $name => $value) {
            $variables[$name] = $value;
            $bound[$name] = &$variables[$name];
        }

        $interpreter->setVariables($bound);
        $interpreter->evaluate($expression);

        return $variables['r'];
    }

    public function testPicksFirstMatchingArm(): void
    {
        $expression = "r = switch {
            case amount >= 10000: 'high';
            case amount >= 1000: 'medium';
            case amount > 0: 'low';
            default: 'none';
        };";

        $this->assertSame('high', $this->evaluate($expression, ['amount' => 50000]));
        $this->assertSame('medium', $this->evaluate($expression, ['amount' => 5000]));
        $this->assertSame('low', $this->evaluate($expression, ['amount' => 500]));
        $this->assertSame('none', $this->evaluate($expression, ['amount' => 0]));
        $this->assertSame('none', $this->evaluate($expression, ['amount' => -10]));
    }

    public function testNoMatchWithoutDefaultGivesNull(): void
    {
        $this->assertNull($this->evaluate("r = switch { case a > 100: 'big' }", ['a' => 1]));
    }

    public function testDefaultPositionDoesNotMatter(): void
    {
        // 'default' используется только когда не подошло ни одно условие,
        // независимо от того, где он объявлен.
        $expression = "r = switch { default: 'none'; case a > 0: 'pos' }";

        $this->assertSame('pos', $this->evaluate($expression, ['a' => 5]));
        $this->assertSame('none', $this->evaluate($expression, ['a' => -5]));
    }

    public function testBareBooleanVariableAsCondition(): void
    {
        $expression = "r = switch { case isVip: 'vip'; default: 'plain' }";

        $this->assertSame('vip', $this->evaluate($expression, ['isVip' => true]));
        $this->assertSame('plain', $this->evaluate($expression, ['isVip' => false]));
    }

    public function testArithmeticInArmBody(): void
    {
        $this->assertSame(21, $this->evaluate("r = switch { case a > 0: a * 2 + 1; default: 0 }", ['a' => 10]));
    }

    public function testNestedSwitch(): void
    {
        $expression = "r = switch {
            case a > 0: switch { case a > 100: 'huge'; default: 'small' };
            default: 'neg';
        }";

        $this->assertSame('huge', $this->evaluate($expression, ['a' => 500]));
        $this->assertSame('small', $this->evaluate($expression, ['a' => 5]));
        $this->assertSame('neg', $this->evaluate($expression, ['a' => -5]));
    }

    public function testArrayLiteralAsArmBody(): void
    {
        $this->assertSame(
            ['k' => 7],
            $this->evaluate('r = switch { case a > 0: [{"k":7}]; default: [] }', ['a' => 1])
        );
    }

    public function testSausageInConditionAndBody(): void
    {
        $expression = "r = switch { case cfg.level > 2: cfg.name; default: 'flat' }";

        $this->assertSame('deep', $this->evaluate($expression, ['cfg' => ['level' => 5, 'name' => 'deep']]));
        $this->assertSame('flat', $this->evaluate($expression, ['cfg' => ['level' => 1, 'name' => 'deep']]));
    }

    public function testStructuralCharactersInsideArmString(): void
    {
        // ';' и '}' внутри строки не должны обрывать плечо и тело switch.
        $this->assertSame('a;b}c', $this->evaluate("r = switch { case a > 0: 'a;b}c'; default: 'x' }", ['a' => 1]));
    }

    public function testComposesAsOperand(): void
    {
        // switch — выражение, поэтому участвует в конкатенации и в аргументах функций.
        $this->assertSame(
            'risk:pos',
            $this->evaluate("r = 'risk:' . switch { case a > 0: 'pos'; default: 'neg' }", ['a' => 5])
        );

        $this->assertSame(
            'l',
            $this->evaluate("r = iif(a > 0, switch { case a > 100: 'h'; default: 'l' }, 'neg')", ['a' => 5])
        );
    }

    public function testWorksWithoutSpaceBeforeBrace(): void
    {
        $this->assertSame('pos', $this->evaluate("r = switch{ case a > 0: 'pos'; default: 'neg' }", ['a' => 5]));
    }

    public function testOnlyMatchingArmIsEvaluated(): void
    {
        // Ленивость важна: плечо с побочным эффектом не должно срабатывать вхолостую.
        $interpreter = new ExpressionInterpreter();
        $a = 1;
        $counter = 10;
        $r = null;
        $interpreter->setVariables(['a' => &$a, 'counter' => &$counter, 'r' => &$r]);

        $interpreter->evaluate("r = switch { case a > 100: counter += 1; default: 'skipped' }");

        $this->assertSame(10, $counter);
        $this->assertSame('skipped', $r);

        $a = 500;
        $interpreter->evaluate("r = switch { case a > 100: counter += 1; default: 'skipped' }");

        $this->assertSame(11, $counter);
    }

    public function testDuplicateDefaultThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/more than one 'default'/");

        $this->evaluate("r = switch { default: 1; default: 2 }");
    }

    public function testArmWithoutCaseKeywordThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/must start with 'case' or 'default'/");

        $this->evaluate("r = switch { a > 0: 'x' }", ['a' => 1]);
    }

    public function testArmWithoutResultThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no result expression/');

        $this->evaluate("r = switch { case a > 0: }", ['a' => 1]);
    }

    public function testEmptySwitchThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no arms/');

        $this->evaluate("r = switch { }");
    }

    public function testUnterminatedSwitchThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unterminated switch block/');

        $this->evaluate("r = switch { case a > 0: 'x'", ['a' => 1]);
    }
}
