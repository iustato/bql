# ExpressionInterpreter

**ExpressionInterpreter** — это библиотека для обработки и вычисления выражений в стиле SQL в PHP. Позволяет легко вычислять выражения с операторами, переменными и логическими условиями. Важно, что пакет не использует функцию eval от php. В проекте написан собственный интерпретатор.
Таеим образом, Вы можете давать вашему пользователю писать простенькие запросы с теми переменными, с которыми вы ему разрешите.

**Есть `examples/` для быстрого тестирования**  

Поддерживает операторы: 
+ \+	Сложение
+ \-	Вычитание
+ \*	Умножение
+ \/	Деление
+ \==	Сравнение
+ !=	Не равно
+ \<	Меньше
+ \>    Больше
+ \<=	Меньше или равно
+ \>=	Больше или равно
+ \&&, AND	Логическое И
+ !	Логическое НЕ
+ in	Проверка в массиве
+ like	Поиск по шаблону (аналог SQL LIKE)

Поддерживаемые функции:
+ var = iif (condition, true_result, false_result);
+ var = min (5, 10 23, 3, 55, 16);
+ var = max (5, 10 23, 3, 55, 16);

Методы значения:
+ var.toString() — строковое представление
+ var.toNum() — числовое представление
+ var.toJSON() — JSON-представление (удобно для массивов)

Поддерживает добавление собственных операторов

**Конструкция switch**

`switch` — это выражение: оно возвращает значение, поэтому его можно присвоить
переменной, склеить со строкой или передать в функцию. Условия проверяются сверху
вниз, вычисляется тело только того плеча, которое подошло первым. Плечо `default`
может стоять в любом месте. Если ни одно условие не подошло и `default` нет,
результат — `null`.

```php
$bql->evaluate("
    risk = switch {
        case amount >= 10000: 'high';
        case amount >= 1000:  'medium';
        case amount > 0:      'low';
        default:              'none';
    };
");
```

**Ассоциативные массивы через JSON**

JSON-объект всегда оборачивается в квадратные скобки: `[{...}]`. Внешние скобки —
это обёртка литерала, поэтому результат — именно ассоциативный массив, а не список
из одного элемента. Одинарные кавычки — строки BQL, двойные — строки внутри JSON.

```php
// Ассоциативный массив
$bql->evaluate('cfg = [{"param1":"value1","inner":{"a":1,"b":2}}]');
// -> ['param1' => 'value1', 'inner' => ['a' => 1, 'b' => 2]]

// Вложенные массивы задаются вложенностью
$bql->evaluate('arrayOfSet = [[1,2,3], [4,5,6], [7,8,9]]');
// -> [0 => [1,2,3], 1 => [4,5,6], 2 => [7,8,9]]

// Список записей
$bql->evaluate('rows = [{"a":1},{"b":2}]');
// -> [0 => ['a' => 1], 1 => ['b' => 2]]

// Историческая форма по-прежнему работает
$bql->evaluate("list = ['a', 'b']");
```

Числа из JSON приводятся к внутреннему представлению `NumVarHandler`: дробные и
очень большие остаются строками, чтобы арифметика шла через bcmath, а не через
float. Строки не меняются — `"01234"` остаётся строкой с ведущими нулями.

К элементу любой вложенности можно обратиться через точку, в том числе по
числовому индексу — и на чтение, и на запись:

```php
$bql->evaluate("r = payment.sender.bank.bic");
$bql->evaluate("payment.sender.name = 'Пётр'");
$bql->evaluate("r = arrayOfSet.1.2");        // 6
$bql->evaluate("arrayOfSet.0.0 = 99");
```

Ограничение: ключи, к которым обращаются через точку, должны состоять из букв,
цифр и `_`. Ключ вида `inner-value` сохранится и будет виден в `toJSON()`, но в
пути `cfg.inner-value` дефис читается как вычитание.

**Простой пример**
```php
use iustato\Bql\ExpressionInterpreter;

$bql = new ExpressionInterpreter();

$a = 10;

// Определяем переменные. Если мы хотим, что бы значение переменной могло быть изменено интерпретатором, то передаём его по ссылке &$a
$variables = [
    'a' => &$a,
    'b' => 5
];

// Устанавливаем переменные в интерпретатор
$bql->setVariables($variables);

// Выполняем выражение
$bql->evaluate("a = a + b");

$modifVars = $bql->getModifiedVariables();

echo "Результат: " . json_encode($modifVars) . PHP_EOL; // 15

```

**Работа с массивом и in**

```php
$bql = new ExpressionInterpreter();

$variables = [
'user_country' => 'USA',
'allowed_countries' => ['USA', 'CAN', 'GBR'],
'res' => -1,
'res2' => -1
];

$bql->setVariables($variables);

// Проверяем, находится ли страна в списке разрешённых
$bql->evaluate("res = user_country in allowed_countries; res2 = !( user_country in ['GBR', 'ITA', 'MDA'])");

$result = $bql->getModifiedVariables();

echo "Результат: " . json_encode($result) . PHP_EOL; // true
```

**возможно устанавливать значение в массиве или объекте класса**

```php

class A {
    private $my_value;
    
    public $var;
    
    public function __construct($v)
    {
        $this->my_value = $v;
        $this->var = 123;
    }
    public function setValue($value)
    {
        $this->my_value = $value;
    }

    public function getValue()
    {
        return $this->my_value;
    }
}

// index.php

$a = new A(15);

$bql = new ExpressionInterpreter();

$variables = [
    'A' => $a    
];

$bql->setVariables($variables);

// Проверяем, находится ли страна в списке разрешённых
$bql->evaluate("A.Value = 5 + 3 * 8; A.var = A.Value - 7;");

echo "Так поменялся объект a: " . json_encode($a) . PHP_EOL; 




```