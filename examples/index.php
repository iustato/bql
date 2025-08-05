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

require 'Order.php';
require 'Goods.php';
require 'Customer.php';


use iustato\Bql\ExpressionInterpreter;
use iustato\Bql\VarTypes\StringVarHandler;

/*
$varA = 'aa';
$varB = 'bb';
    $objA = new StringVarHandler('varA', $varA);
    $objB = new StringVarHandler('varB', $varB);

    $result = $objA->operatorCall('=', $objB);

    var_dump($varA);

return;*/

    $bql = new ExpressionInterpreter();

    // заполняем тестовыми данными
    $ResultArr =  [
        'AllowPay' => 0,
        'HaveDiscount' => -1,
        'IsApple' => -1
    ];

    $goods_list = [
    new Goods("iPhone 14 Pro", 999.99, "Apple Inc", "USA"),
    new Goods("Galaxy S23 Ultra", 1199.99, "Samsung", "KOR"),
    new Goods("Xiaomi 13 Pro", 899.99, "Xiaomi", "CHN"),
    new Goods("OnePlus 11", 799.99, "OnePlus", "CHN"),
    new Goods("Pixel 7 Pro", 899.00, "Google", "USA"),
    new Goods("Sony Xperia 1 V", 1099.99, "Sony", "JPN"),
    new Goods("Huawei P50 Pro", 799.00, "Huawei", "CHN"),
    new Goods("Nokia XR21", 699.00, "Nokia", "FIN"),
    new Goods("Moto Edge 30 Ultra", 849.99, "Motorola", "USA"),
    new Goods("Oppo Find X5 Pro", 899.99, "Oppo", "CHN"),
];

    $customers_list = [
        new Customer("John Doe", "USA"),      // США
        new Customer("Maria Ivanova", "RUS"), // Россия
        new Customer("Liam O'Connor", "IRL"), // Ирландия
        new Customer("Hiroshi Tanaka", "JPN"),// Япония
        new Customer("Carlos Mendoza", "MEX"),// Мексика
        new Customer("Alice Dupont", "FRA"),  // Франция
        new Customer("Hans Muller", "DEU"),   // Германия
        new Customer("Chen Wei", "CHN"),      // Китай
        new Customer("Omar Hassan", "EGY"),   // Египет
        new Customer("Sophia Rossi", "ITA"),  // Италия
        new Customer("Ahmad Khan", "AFG"),    // Афганистан
        new Customer("Reza Pahlavi", "IRN")   // Иран
    ];


$rand_goods = rand(0,count($goods_list) -1);
$rand_customer = rand(0,count($customers_list) -1);

    $current_order = new Order($customers_list[$rand_customer], $goods_list[$rand_goods], rand(1, 25));

    echo "<p> <b>current_order:</b> <br />";
    var_dump($current_order);

    $ResultVar = 'aa';

    echo "</p> \n <br />";
    $bql->setVariables([
        'ResultVar' => &$ResultVar,
        'Result' => &$ResultArr,    // ATTENTION !!! Indicate Arrays by link, if interpreter need to change its values
        'Order' => $current_order,
        'producer_countries_with_discounts' => ['FIN', 'USA', 'GEO', 'ITA', 'DEU'],
        'prohibited_countries' => ['IRN', 'AFG']
        ]
    );

    echo "<p> <b>Dynamic expression : </b> <br /> \n ";

    $expr = "Order.Allow = !(Order.Customer.Country in prohibited_countries); Result.AllowPay = Order.Allow; Result.HaveDiscount = (Order.Totalwithoutdiscount > 5000 && Order.Goods.Producer_country in producer_countries_with_discounts); Result.IsApple = Order.Goods.Producer_name like '%apple%'";

    //$expr = "ResultVar = 'new_value123'; ResultVar = ResultVar + '456'; Order.Deny = (Order.Customer.Country in prohibited_countries); Result.HaveDiscount = (Order.Totalwithoutdiscount > 5000 && Order.Goods.Producer_country in producer_countries_with_discounts); Result.IsApple = Order.Goods.Producer_name like '%apple%';";

    echo $expr;

    echo " \n </p> \n ";

    $rez = $bql->evaluate($expr);

    echo "<p> <b>variable storage:</b> \n <br />";
    var_dump($bql->variableStorage);

    //    echo "Deny: ".$current_order->getDeny();
    echo "</p> \n ";
/*
    echo "<p> Variables: <br />";
    var_dump($bql->getVariables());
    echo "</p>";*/
    /*
    echo "<p> <b>current_order after interpreeter works: </b> \n <br />";
    var_dump($current_order);
    echo " \n </p> \n ";


    echo "<p> <b>ResultArr values: </b> \n <br />";
    var_dump($ResultArr);*/
    var_dump($current_order->getAllow());
    echo "</p> \n ";

    var_dump($ResultArr);

    //var_dump($rez);
    /*
     *  Значение allow_order будет меняться в $current_order
     *
     */



