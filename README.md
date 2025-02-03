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

Поддерживает добавление собственных операторов

**Простой пример**
```php
use Iustato\Bql\ExpressionInterpreter;

$bql = new ExpressionInterpreter();

// Определяем переменные
$variables = [
    'a' => 10,
    'b' => 5
];

// Устанавливаем переменные в интерпретатор
$bql->setVariables($variables);

// Выполняем выражение
$bql->evaluate("a + b");

$result = $bql->getModifiedVariables();

echo "Результат: " . json_encode($result) . PHP_EOL; // 15

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
        $this->var = $v;
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
$bql->evaluate("A.a = 5 + 3 * 8; A.var = A.a - 7;");

echo "Так поменялся объект a: " . json_encode($a) . PHP_EOL; 




```