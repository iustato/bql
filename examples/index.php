<?php
namespace TestProj;

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверяем, где находится autoload.php
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',   // Локальная разработка
    __DIR__ . '/../../vendor/autoload.php' // Когда пакет установлен через Composer
];

foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

// Проверяем, подключён ли autoload.php
if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
    die("❌ Ошибка: Не найден autoload.php! Запустите `composer install` в корне проекта.\n");
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

use iustato\Bql\ExpressionInterpreter;
use TestProj\Tests\Parts\Customer;
use TestProj\Tests\Parts\Goods;
use TestProj\Tests\Parts\Order;

echo "<h1>BQL TESTS</h1>";

$bql = new ExpressionInterpreter();

// Создаем тестовые данные точно как в тесте
$ResultArr = [
    'HaveDiscount' => -1,
    'TestResult' => null
];

// Создаем клиента и товар точно как в тесте
$testCustomer = new Customer("Test Customer", "USA", 25);
$testGoods = new Goods("iPhone 14 Pro", 999.99, "Apple Inc", "USA");  // Цена 999.99
$testOrder = new Order($testCustomer, $testGoods, 5); // Количество 5

$class = new AccessViaMethods();
$initial = 10;
$class->setCounter($initial);

$result = [];
$r = 0;
$bql->setVariables([
    'class' => $class,
    'dateTime' => '2025-05-17 15:30:00',
    'result' => &$result,
    'r' => $r
]);

// Тестируем несколько операций подряд

$expression = "
            class.counter++;
            result.afterIncrement = class.counter;
            class.counter += 5; 
            result.afterAdd = class.counter;
            result.r1 = iif(class.counter < 10, 'yes', 'no');
            result.r2  = max(2 * (5 + 4), 3, 28, 44, 99, 17); 
            result.r3 = abs(0+8);
";

//$expression = "result.r1 = iif(class.counter < 10, 'yes', 'no'); result.r2  = max(2 * (5 + 4), 3, 28, 44, 99, 17); result.r3 = abs(0+8);";
//$expression = "result.businessHours = (dateTime.Hour >= 9 && dateTime.Hour < 17)";

$bql->evaluate($expression);
//            result.funcRez = iif( 5 > 4, result.afterAdd, 0)
var_dump($result);
//$this->assertEquals($initial + 1, $result['afterIncrement']);
//$this->assertEquals($initial + 1 + 5, $result['afterAdd']);




