# AGENTS.md — BQL Interpreter

## Что это

BQL — интерпретатор простого языка запросов (в стиле SQL-выражений) для встраивания в
PHP-проекты. Цель: дать конечному пользователю возможность писать **безопасные**
выражения для тонкой конфигурации — сравнивать значения, вычислять арифметику/логику
и **изменять по ссылке** значения переменных, свойств объектов и элементов массивов,
которые ему явно разрешил хост-проект.

**Ключевой принцип безопасности: `eval()` НЕ используется.** Разбор и вычисление
выполняются собственным интерпретатором. Пользователь оперирует только теми
переменными, которые ему передали через `setVariables()` — доступа к произвольному
PHP-коду нет. Любое изменение, ослабляющее эту изоляцию, недопустимо.

## Стек

- PHP 8.4 (используются typed properties, `mixed`, union-типы).
- PSR-4 автозагрузка, **namespace `iustato\Bql`** (строчными буквами!).
- PHPUnit 10 для тестов. Единственная runtime-зависимость — `ext-ctype`.

## Команды

```bash
composer install                 # установка (vendor/ частично закоммичен, но обнови)
composer test                    # весь набор тестов (phpunit tests)
composer test-simple             # tests/SimpleTest.php
composer test-math               # tests/MathOperationsTest.php
composer test-orders             # tests/OrderExpressionTest.php
composer test-coverage           # HTML-покрытие в coverage/
vendor/bin/phpunit tests         # то же, что composer test
php examples/index.php           # ручная песочница для быстрой проверки
```

Запускай `composer test` после любого изменения в `src/`. На момент написания:
35 тестов, 62 проверки — все зелёные (есть 2 deprecation-предупреждения, не критично).

## Архитектура

Конвейер вычисления (`ExpressionInterpreter::evaluate()`):

1. **`tokenizeWithAutomaton(string): Token[][]`** — ручной конечный автомат,
   режет строку на токены. Разделитель команд — `;` (возвращается массив команд,
   каждая — массив токенов). Состояния: `default, string, number, identifier,
   sausage, operator, array, function_call, method_params`.
2. **`toReversePolishNotation(Token[]): Token[]`** — алгоритм сортировочной станции
   (shunting-yard): учитывает приоритет и ассоциативность операторов, разворачивает
   скобки.
3. **`evaluateRPN(Token[])`** — вычисляет ОПН через стек, вызывая операторы на
   обработчиках переменных. Если оператор помечен `modifiesVariable`, результат
   пишется обратно через `VariableStorage::modifyVariable()`.

### Ключевые классы (`src/`)

- **`ExpressionInterpreter.php`** — публичный фасад + токенайзер + ОПН + реестры
  операторов и функций. Все операторы регистрируются в конструкторе через
  `registerOperator($op, $precedence, $assoc, $modifiesVariable, $operandCount)`.
- **`Token.php` / `FunctionCallToken.php`** — единица разбора. `FunctionCallToken`
  сам парсит `name(arg, arg)` и умеет рекурсивно вычислять аргументы как
  под-выражения (`evaluateParameters()`).
- **`VariableStorage.php`** — хранит обработчики переменных, отслеживает
  `modifiedVariables` (см. `getModifiedVariables()`) и `usedVariables`
  (`getUsedVariables()`). Предопределены `true`, `false`, `null`.
- **`VariableHandlerFactory.php`** — выбирает нужный `*VarHandler` по значению
  (`supports()`) или по типу токена. **Порядок в `$availableHandlers` важен** —
  проверка идёт сверху вниз, `SimpleVarHandler` последний как fallback.

### Система обработчиков типов (`src/VarTypes/`)

Все наследуют **`AbstractVariableHandler`** и обязаны реализовать:
`supports()`, `get()`, `set()`, `has()`, `operatorCall()` (бинарные),
`operatorUnaryCall()` (унарные), `toString()`, `toNum()`, `convertToMe()`.

| Handler | Для чего |
|---|---|
| `NumVarHandler` | int/float — вся арифметика и сравнения |
| `StringVarHandler` | строки, `like`, конкатенация `.` |
| `BoolVarHandler` | булевы |
| `ArrayHandler` | массивы, оператор `in` |
| `ObjectHandler` | объекты — доступ через public property, геттер/сеттер (`getX`/`setX`) или магические `__get`/`__set`; использует Reflection + кэш |
| `DateTimeVarHandler` / `DateTimeIntervalVarHandler` | даты и интервалы |
| `SausageVarHandler` | «колбаса» — вложенный доступ через точку (`Order.Customer.Age`); разрешает путь и пишет значение обратно по ссылке |
| `SimpleVarHandler` | базовый/fallback |

`operatorCall` возвращает **новый** обработчик для чистых операций (`+`, `==`, …)
и обычно `$this` для мутирующих (`+=`, `++`). Промежуточные результаты
регистрируются как анонимные переменные через `registerAnonymous()`.

## Как расширять

**Новый оператор:** `registerOperator()` в конструкторе + реализовать ветку в
`operatorCall()`/`operatorUnaryCall()` каждого релевантного `*VarHandler`.

**Новая функция:** `registerFunction($name, callable, $minArgs, $maxArgs, $returnType)`.
Встроенные (`iif, min, max, abs, len`) — образец, см. `registerBuiltinFunctions()`.
Аргументы приходят как `AbstractVariableHandler`; извлекай значение через `->get()`.

**Новый тип переменной:** новый класс-наследник `AbstractVariableHandler`, добавить
в `VariableHandlerFactory::$availableHandlers` в правильном порядке приоритета.

## Соглашения и подводные камни

- **Изменение по ссылке**: чтобы выражение могло записать значение обратно в
  переменную хоста, её нужно передать по ссылке: `['a' => &$a]`. Иначе изменение
  видно только через `getModifiedVariables()`.
- **Namespace — строчными**: код использует `iustato\Bql` (см. `composer.json`).
  Не пиши `Iustato\Bql` с заглавной — PSR-4 такой класс не загрузит.
- Операторы регистрируются в нижнем регистре; словесные (`and`, `in`, `like`)
  распознаются токенайзером как операторы, а не идентификаторы.
- Комментарии в коде на русском — сохраняй язык и стиль при правках.
- `vendor/bin/` и часть `vendor/` закоммичены (нестандартно). Не полагайся на это —
  запускай `composer install`.
- Не трогай отладочный/закомментированный код без причины; в
  `evaluateRPN`/`executeOperator` есть намеренно закомментированные ветки.

## Файлы для ориентира

- Точка входа и весь конвейер: `src/ExpressionInterpreter.php`.
- Живые примеры синтаксиса: `tests/*Test.php` (особенно `OrderExpressionTest`,
  `AccessViaMethodsTest`, `DateTimeTest`) и `examples/index.php`.
- Тестовые доменные объекты: `tests/Parts/{Customer,Goods,Order}.php`.
