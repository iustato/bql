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


    $bql = new ExpressionInterpreter();

    $rezz = -2;
    // заполняем тестовыми данными
    $VarsArr =  [
        'A' => 234.27,
        'B' => 35.41,
        'C' => false,
        'D' => 'ccc',
        'Rez' => &$rezz,
        'Rez2' => 0
    ];

    $bql->setVariables($VarsArr);
    echo "<p> <b>Dynamic expression : </b> <br /> \n ";

    /*$bql->tempSetVar();

    $modif_vars = $bql->getModifiedVariables();

    var_dump($modif_vars);;

    var_dump($rezz);

    return;*/


    $expr = "Rez2 = (A < B) || C; Rez = D in ['ccc', 'bbb', 'aaa1'] ";

    //$expr = "ResultVar = 'new_value123'; ResultVar = ResultVar + '456'; Order.Deny = (Order.Customer.Country in prohibited_countries); Result.HaveDiscount = (Order.Totalwithoutdiscount > 5000 && Order.Goods.Producer_country in producer_countries_with_discounts); Result.IsApple = Order.Goods.Producer_name like '%apple%';";

    echo $expr;

    echo " \n </p> \n ";

    $rez = $bql->evaluate($expr);

    $modif_vars = $bql->getModifiedVariables();

    var_dump($modif_vars);;

    var_dump($rezz);
    //echo "<p> <b>variable storage:</b> \n <br />";
    //var_dump($bql->variableStorage);

    //    echo "Deny: ".$current_order->getDeny();
    echo "</p> \n ";


    //var_dump($rez);
    /*
     *  Значение allow_order будет меняться в $current_order
     *
     */



