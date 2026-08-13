<?php
// tests/JsonArrayTest.php
namespace TestProj\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use iustato\Bql\ExpressionInterpreter;

/**
 * Литералы массивов: JSON-форма и историческая форма через запятую.
 *
 * JSON-объект всегда обёрнут в квадратные скобки — '{' не должен появляться в
 * позиции значения, иначе он конфликтовал бы с блоками '{...}'.
 */
class JsonArrayTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private mixed $v;

    protected function setUp(): void
    {
        $this->v = null;
        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables(['v' => &$this->v]);
    }

    public function testAssociativeArrayFromJson(): void
    {
        $this->interpreter->evaluate('v = [{"param1":"value1","inner":{"a":1,"b":2}}]');

        // Внешние скобки — только обёртка, поэтому это ассоциативный массив,
        // а не список из одного элемента.
        $this->assertSame(
            ['param1' => 'value1', 'inner' => ['a' => 1, 'b' => 2]],
            $this->v
        );
    }

    public function testNestedArrays(): void
    {
        $this->interpreter->evaluate('v = [[1,2,3], [4,5,6],[7,8,9]]');

        $this->assertSame([[1, 2, 3], [4, 5, 6], [7, 8, 9]], $this->v);
    }

    public function testListOfObjectsStaysList(): void
    {
        // Больше одного объекта — разворачивать нечего, это обычный JSON-массив.
        $this->interpreter->evaluate('v = [{"a":1},{"b":2}]');

        $this->assertSame([['a' => 1], ['b' => 2]], $this->v);
    }

    public function testLegacyLiteralStillWorks(): void
    {
        $this->interpreter->evaluate("v = ['a', 'b']");
        $this->assertSame(['a', 'b'], $this->v);

        $this->interpreter->evaluate('v = [1, 2, 3]');
        $this->assertSame([1, 2, 3], $this->v);
    }

    public function testNumbersKeepBcmathRepresentation(): void
    {
        $this->interpreter->evaluate('v = [{"price":1.5,"big":12345678901234567890,"zip":"01234","n":7}]');

        // Дробные и большие числа — строки (внутреннее представление bcmath),
        // обычные целые — int, а строка с ведущими нулями остаётся строкой.
        $this->assertSame('1.5', $this->v['price']);
        $this->assertSame('12345678901234567890', $this->v['big']);
        $this->assertSame('01234', $this->v['zip']);
        $this->assertSame(7, $this->v['n']);
    }

    public function testJsonArithmeticGoesThroughBcmath(): void
    {
        $sum = null;
        $this->interpreter->setVariables(['v' => &$this->v, 'sum' => &$sum]);

        $this->interpreter->evaluate('v = [{"price":0.1}]; sum = v.price + 0.2;');

        // Через float получилось бы 0.30000000000000004.
        $this->assertSame('0.3', $sum);
    }

    public function testStructuralCharactersInsideJsonStrings(): void
    {
        // ']' и ';' внутри строки не должны обрывать литерал и команду.
        $this->interpreter->evaluate('v = [{"a":"]","b":"x;y"}]');

        $this->assertSame(['a' => ']', 'b' => 'x;y'], $this->v);
    }

    public function testBrokenJsonThrows(): void
    {
        // Молчаливый откат к разбору по запятой превратил бы опечатку в мусор.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON in array literal/');

        $this->interpreter->evaluate('v = [{"a": broken}]');
    }

    public function testAssignmentReachesHostVariableByReference(): void
    {
        $this->interpreter->evaluate('v = [{"a":1}]');

        // Значение должно доехать по ссылке, а не только в getModifiedVariables().
        $this->assertSame(['a' => 1], $this->v);
        $this->assertSame(['a' => 1], $this->interpreter->getModifiedVariables()['v']);
    }

    public function testAssignmentOverExistingArray(): void
    {
        $this->v = ['old' => 1];
        $this->interpreter->setVariables(['v' => &$this->v]);

        $this->interpreter->evaluate('v = [{"new":2}]');

        // Замена целиком, а не запись под собственным именем переменной.
        $this->assertSame(['new' => 2], $this->v);
    }

    public function testKeysWithHyphenAreStoredButNotDotAddressable(): void
    {
        $json = null;
        $this->interpreter->setVariables(['v' => &$this->v, 'json' => &$json]);

        $this->interpreter->evaluate('v = [{"inner-value":{"a":1}}]');

        // Ключ сохраняется как есть и доступен целиком через toJSON().
        $this->assertSame(['inner-value' => ['a' => 1]], $this->v);

        $this->interpreter->evaluate('json = v.toJSON()');
        $this->assertSame('{"inner-value":{"a":1}}', $json);

        // Но по точке к нему не обратиться: '-' в пути читается как вычитание,
        // потому что 'a.count-1' иначе стало бы неоднозначным. Для доступа по
        // точке ключи должны состоять из букв, цифр и '_'.
        $this->interpreter->evaluate('v = [{"inner_value":{"a":1}}]');
        $this->assertSame(1, $this->interpreter->evaluate('v.inner_value.a')[0]->get());
    }

    public function testInOperatorWithJsonList(): void
    {
        $found = null;
        $this->interpreter->setVariables(['v' => &$this->v, 'found' => &$found]);

        $this->interpreter->evaluate("v = [1,2,3]; found = 2 in v;");

        $this->assertTrue($found);
    }
}
