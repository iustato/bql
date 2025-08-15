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


use iustato\Bql\ExpressionInterpreter;
use TestProj\Tests\Parts\Customer;
use TestProj\Tests\Parts\Goods;
use TestProj\Tests\Parts\Order;

echo "<h1>Тест воспроизведения проблемы из testOrderDiscountLogic</h1>";

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

echo "<h2>Исходные данные:</h2>";
echo "Товар: {$testGoods->Name}, Цена: {$testGoods->Price}<br>";
echo "Количество: {$testOrder->Qnt}<br>";
echo "Общая стоимость без скидки: " . $testOrder->getTotalwithoutdiscount() . "<br>";

$bql->setVariables([
    'Result' => &$ResultArr,
    'Order' => $testOrder,
    'producer_countries_with_discounts' => ['FIN', 'USA', 'GEO', 'ITA', 'DEU'],
    'prohibited_countries' => ['IRN', 'AFG']
]);
/*
echo "<h2>Шаг 1: Проверка скидки</h2>";

// Первое выражение из теста
$expr1 = "Result.HaveDiscount = (Order.Totalwithoutdiscount > 5000 && Order.Goods.Producer_country in producer_countries_with_discounts)";
echo "Выражение: <code>{$expr1}</code><br>";

try {
    $bql->evaluate($expr1);
    echo "Результат HaveDiscount: " . ($ResultArr['HaveDiscount'] ? 'true' : 'false') . "<br>";
    echo "Общая стоимость: " . $testOrder->getTotalwithoutdiscount() . " (должна быть < 5000)<br>";
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "<br>";
}
*/
echo "<h2>Шаг 2: Инкремент цены (проблемная операция)</h2>";

echo "Цена товара ДО инкремента: {$testGoods->Price}<br>";

// Проблемная строка из теста
$expr2 = "Order.Goods.Price++";
echo "Выражение: <code>{$expr2}</code><br>";

try {
    $bql->evaluate($expr2);
    
    echo "✅ Инкремент выполнен успешно!<br>";
    echo "Цена товара ПОСЛЕ инкремента: {$testGoods->Price}<br>";
    
    $usedVars = $bql->getUsedVariables();
    echo "Использованные переменные:<br>";
    foreach ($usedVars as $key => $value) {
        echo "- {$key}: {$value}<br>";
    }
    
} catch (TypeError $e) {
    echo "❌ TypeError: " . $e->getMessage() . "<br>";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "<br>";
    
    // Показываем стек вызовов
    echo "<details><summary>Стек вызовов</summary><pre>" . $e->getTraceAsString() . "</pre></details>";
    
} catch (Exception $e) {
    echo "❌ Другая ошибка: " . $e->getMessage() . "<br>";
}

echo "<h2>Состояние объектов после тестов:</h2>";

echo "<h3>Результаты:</h3>";
echo "<pre>";