<?php
namespace TestProj;


use iustato\Bql\ExpressionInterpreter;

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

$data = [
    'country' => 'MDA',
    'name' => 'Ebun Hatab Abli Babah',
    'email' => 'ebunbabah@gmail.com',
    'age' => 42, // the answer
];

$results = [
    'isAllowedCountry' => false,
    'isUniversalAnswer' => false,
    'isEmail' => false,
    'isNormalAge' => false,
    'isNormalName' => false,
    'isUndefinedKey' => false,
];

$interpreter = new ExpressionInterpreter();
$interpreter->setVariables([
    'data' => $data,
    'results' => &$results,
]);

$interpreter->evaluate(
    "results.isAllowedCountry = (data.country in ['USA', 'MDA', 'CAD'])"
);
var_dump( $results['isAllowedCountry']);

$interpreter->evaluate(
    "results.isUniversalAnswer = (data.age == 42)"
);
var_dump(  $results['isUniversalAnswer']);

$interpreter->evaluate(
    "results.isNormalAge = (data.age > 5 && data.age < 120)"
);
var_dump(  $results['isNormalAge']);


$interpreter->evaluate(
    "results.isUndefinedKey = (data.notExists ?? true)"
);
var_dump( $results['isUndefinedKey']);

$interpreter->evaluate(
    "results.isEmail = data.email like '%@%'"
);
var_dump(  $results['isEmail']);

$interpreter->evaluate("results.isNormalName = (data.name != 'John Doe')");
var_dump(  $results['isNormalName']);

var_dump($interpreter->getUsedVariables());;



