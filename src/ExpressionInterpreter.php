<?php

namespace iustato\Bql;

use InvalidArgumentException;
use iustato\Bql\VarTypes\BoolVarHandler;
use iustato\Bql\VarTypes\NumVarHandler;
use iustato\Bql\VarTypes\SimpleVarHandler;
use LogicException;
use iustato\Bql\VarTypes\AbstractVariableHandler;

class ExpressionInterpreter
{
    /**
     * Состояния-литералы: копят символы до парной закрывающей скобки.
     *
     * Значение — пара [открывающая, закрывающая]. Внутри таких состояний пробелы,
     * ';' и скобки в строках частью структуры не являются (см. trackLiteralDepth()).
     */
    private const LITERAL_STATES = [
        'array' => ['[', ']'],
        'function_call' => ['(', ')'],
        'switch_body' => ['{', '}'],
    ];

    /** Методы, вызываемые на переменной как 'var.toString()'. Единый список для обеих регулярок. */
    private const VALUE_METHODS = ['toString', 'toNum', 'toJSON'];

    /**
     * Знак числа: во что переименовывается оператор, стоящий в позиции операнда.
     *
     * На уровне символов '-a' и 'a - b' не различить, поэтому токенайзер всегда
     * отдаёт обычный '-', а решение принимает {@see toReversePolishNotation()}
     * по позиции в потоке токенов. Отдельные имена нужны, чтобы у унарной формы
     * были свои приоритет и количество операндов.
     */
    private const UNARY_SIGNS = ['-' => 'u-', '+' => 'u+'];

    /** Постфиксные операторы: значение слева готово, следующий '-' — бинарный. */
    private const POSTFIX_OPERATORS = ['++', '--'];

    /**
     * Отрицающие операторы: во что разворачивается 'not <оператор>'.
     *
     * Ключ — составной оператор, значение — базовый, результат которого
     * инвертируется. Реализация одна на все типы значений (см. executeOperator()),
     * потому что базовые 'in'/'like' всюду возвращают BoolVarHandler — так
     * отрицание не может разъехаться с исходным оператором.
     *
     * Токенайзер отдаёт 'not' и 'in' разными токенами и приставку не распознаёт:
     * решение принимается по позиции в toReversePolishNotation(), как и у знака
     * числа (см. UNARY_SIGNS). Составные имена всё же зарегистрированы — именно
     * запись в реестре несёт приоритет и число операндов.
     */
    private const NEGATED_OPERATORS = [
        'not in' => 'in',
        'not like' => 'like',
    ];

    public VariableStorage $variableStorage;
    private array $operators = [];
    private array $functions = [];

    public function __construct()
    {
        $this->variableStorage = new VariableStorage();

        // Регистрация операторов.
        // Приоритет: чем БОЛЬШЕ число, тем сильнее связывание (см. shunting-yard
        // в toReversePolishNotation). Порядок — как в большинстве языков:
        //   присваивание < ?? < || < && < равенство < сравнения
        //     < + - . < * / < унарные ! и знак числа < ++ --
        // Ключевое: арифметика связывается СИЛЬНЕЕ сравнений, поэтому
        // `1 + 2 == 3` разбирается как `(1 + 2) == 3`, а не `1 + (2 == 3)`.

        // Присваивание (самый низкий приоритет).
        $this->registerOperator('=', 1, 'right', true, 2);
        $this->registerOperator('+=', 1, 'right', true, 2);
        $this->registerOperator('-=', 1, 'right', true, 2);

        // Слияние null.
        $this->registerOperator('??', 2, 'right');

        // Логическое ИЛИ.
        $this->registerOperator('||', 3, 'left');
        $this->registerOperator('OR', 3, 'left');

        // Логическое И.
        $this->registerOperator('&&', 4, 'left');
        $this->registerOperator('AND', 4, 'left');

        // Равенство.
        $this->registerOperator('==', 5);
        $this->registerOperator('!=', 5);

        // Сравнения и поиск в множестве.
        $this->registerOperator('<', 6);
        $this->registerOperator('>', 6);
        $this->registerOperator('<=', 6);
        $this->registerOperator('>=', 6);
        $this->registerOperator('in', 6);
        $this->registerOperator('like', 6);
        // Отрицающие формы — тот же приоритет, что и у базовых (см. NEGATED_OPERATORS).
        $this->registerOperator('not in', 6);
        $this->registerOperator('not like', 6);

        // Аддитивные и конкатенация.
        $this->registerOperator('+', 7, 'left', false, 2);
        $this->registerOperator('-', 7, 'left', false, 2);
        $this->registerOperator('.', 7, 'left', false, 2);

        // Мультипликативные.
        $this->registerOperator('*', 8, 'left', false, 2);
        $this->registerOperator('/', 8, 'left', false, 2);

        // Унарные (самый высокий приоритет).
        $this->registerOperator('!', 9, 'right', false, 1);
        // Знак числа ('-5', 'a * -b', '-(a + b)'). Связывается сильнее умножения,
        // поэтому '-2 * 3' — это '(-2) * 3'. См. UNARY_SIGNS.
        $this->registerOperator('u-', 9, 'right', false, 1);
        $this->registerOperator('u+', 9, 'right', false, 1);
        $this->registerOperator('++', 10, 'right', true, 1);
        $this->registerOperator('--', 10, 'right', true, 1);


        // Регистрация встроенных функций
        $this->registerBuiltinFunctions();

        // default variables:
        /*
        $true = true; $false = false; $null = null;
        $this->variables['true'] = new SimpleVarHandler('true', $true);
        $this->variables['false'] = new SimpleVarHandler('false', $false);
        $this->variables['null'] = new SimpleVarHandler('null', $null);;
        */
    }


    public function registerOperator(string $operator, int $precedence, string $associativity = 'left', bool $modifiesVariable = false, int $operandCount = 2): void
    {
        $operator_lower = strtolower($operator);
        $this->operators[$operator_lower] = [
            'precedence' => $precedence,
            'associativity' => $associativity,
            'modifiesVariable' => $modifiesVariable,
            'operandCount' => $operandCount,
        ];
    }


    /**
     * Регистрирует новую функцию
     */
    public function registerFunction(string $name, callable $callback, int $minArgs = 0, int $maxArgs = PHP_INT_MAX, string $returnType = null): void
    {
        $func_name = strtolower($name);
        $this->functions[$func_name] = [
            'callback' => $callback,
            'minArgs' => $minArgs,
            'maxArgs' => $maxArgs,
            'returnType' => $returnType
        ];
    }

    /**
     * Регистрирует встроенные функции
     */
    private function registerBuiltinFunctions(): void
    {
        // Функция iif (condition, true_result, false_result)
        $this->registerFunction('iif', function ($condition, $trueResult, $falseResult) {
            $conditionValue = $condition instanceof AbstractVariableHandler ? $condition->get() : $condition;
            $conditionBool = filter_var($conditionValue, FILTER_VALIDATE_BOOLEAN);

            if ($conditionBool) {
                return $trueResult instanceof AbstractVariableHandler ? $trueResult->get() : $trueResult;
            } else {
                return $falseResult instanceof AbstractVariableHandler ? $falseResult->get() : $falseResult;
            }
        }, 3, 3);

        // Функции max и min — сравнение через bcmath, см. extremum().
        $this->registerFunction('max', function (...$args) {
            return $this->extremum($args, 1);
        }, 1);

        $this->registerFunction('min', function (...$args) {
            return $this->extremum($args, -1);
        }, 1);

        // Функция abs
        $this->registerFunction('abs', function ($value) {
            $val = $value instanceof AbstractVariableHandler ? $value->get() : $value;
            return abs($val);
        }, 1, 1);

        // Функция len (длина строки или массива)
        $this->registerFunction('len', function ($value) {
            $val = $value instanceof AbstractVariableHandler ? $value->get() : $value;
            if (is_string($val) || is_array($val)) {
                return count($val);
            }
            return 0;
        }, 1, 1);
    }

    /**
     * Общая реализация min/max: выбирает крайнее из чисел через bcmath.
     *
     * Нативные min()/max() тут не годятся: PHP сравнивает числовые строки,
     * скатываясь к float, поэтому значения, различающиеся дальше 17-й значащей
     * цифры, считались равными — и возвращался просто первый аргумент
     * (`min(0.30000000000000001, 0.3)` отдавал первый). Оператор '<' на тех же
     * числах отвечает верно (bccomp), а расхождение между функцией и оператором
     * недопустимо. Результат нормализуется через present(), поэтому
     * `min(10.0, 10)` — это int 10, а не строка '10.0'.
     *
     * @param array $args аргументы вызова: обработчики или готовые значения
     * @param int $sign 1 — максимум, -1 — минимум (совпадает с кодом bccomp)
     * @return mixed выбранное значение
     */
    private function extremum(array $args, int $sign)
    {
        $values = array_map(function ($arg) {
            return $arg instanceof AbstractVariableHandler ? $arg->get() : $arg;
        }, $args);

        // Нечисловые аргументы (строки, массивы, даты) bcmath сравнивать не
        // умеет — они остаются на нативных min()/max() с их правилами.
        foreach ($values as $value) {
            if (!self::isBcOperand($value)) {
                return $sign > 0 ? max($values) : min($values);
            }
        }

        $best = NumVarHandler::toNumericString(array_shift($values));

        foreach ($values as $value) {
            $candidate = NumVarHandler::toNumericString($value);
            if (bccomp($candidate, $best, NumVarHandler::$scale) === $sign) {
                $best = $candidate;
            }
        }

        return NumVarHandler::present($best);
    }

    /** Годится ли значение как операнд bcmath (число или числовая строка). */
    private static function isBcOperand($value): bool
    {
        return is_int($value) || is_float($value)
            || (is_string($value) && is_numeric(trim($value)));
    }


    private function executeOperator(string $operator, Token &$a, Token &$b = null): ?AbstractVariableHandler
    {
        $operator_lower = strtolower($operator);

        if (!isset($this->operators[$operator_lower])) {
            throw new InvalidArgumentException("Operator '$operator_lower' is not defined.");
        }

        // 'not in' / 'not like' — это '!' поверх базового оператора. Считаем их
        // здесь, а не в каждом обработчике значения: базовые операторы всюду
        // возвращают BoolVarHandler, поэтому отрицание одинаково корректно для
        // строк, чисел и массивов и не может разойтись с исходным оператором.
        if (isset(self::NEGATED_OPERATORS[$operator_lower])) {
            $base = $this->executeOperator(self::NEGATED_OPERATORS[$operator_lower], $a, $b);

            return $base === null ? null : $base->operatorUnaryCall('!');
        }

        $operatorConfig = $this->operators[$operator_lower];
        $var_a = $this->resolveValue($a);

        // Обработка унарных операторов
        if ($operatorConfig['operandCount'] === 1) {
            $result = $var_a->operatorUnaryCall($operator);

            /*
            // Обработка операторов, изменяющих переменную (++, --)
            if ($operatorConfig['modifiesVariable']) {
                $this->handleVariableModification($a, $result);
            }*/

            return $result;
        }

        // Обработка бинарных операторов
        $var_b = $this->resolveValue($b);
        $result = $var_a->operatorCall($operator, $var_b);

        /*
        // Обработка операторов, изменяющих переменную (=, +=, -=)
        if ($operatorConfig['modifiesVariable']) {
            $this->handleVariableModification($a, $result);
        }
        */
        return $result;
    }

    private function getOperators(): array
    {
        return array_keys($this->operators);
    }


    private function getPrecedence(string $operator): int
    {
        $operator_lower = strtolower($operator);
        return $this->operators[$operator_lower]['precedence'] ?? 0;
    }

    public function setVariables(array $variables): void
    {
        $this->variableStorage->setVariables($variables);
    }

    public function getModifiedVariables(): array
    {
        return $this->variableStorage->getModifiedVariables();
    }

    public function getUsedVariables(): array
    {
        return $this->variableStorage->getUsedVariables();
    }


    public function evaluate(string $expression): array
    {
        $commandTokens = $this->tokenizeWithAutomaton($expression);
        // TODO: remove after debug
        //Log::debug('ExpressionInterpreter', ['commandTokens' => $commandTokens]);
        $results = [];

        foreach ($commandTokens as $tokens) {
            if (empty($tokens)) {
                continue;
            }

            $rpn = $this->toReversePolishNotation($tokens);
            // TODO: remove after debug
            //Log::debug('ExpressionInterpreter', ['rpn' => $rpn]);

            $results[] = $this->evaluateRPN($rpn);
        }

        return $results;
    }

    /**
     * Разбивает выражение на команды (по ';') и токены с помощью конечного автомата.
     *
     * Логика разнесена на маленькие обработчики: кросс-режимные правила (конец команды,
     * пробелы, скобки) остаются в цикле, а поведение каждого состояния вынесено в
     * отдельный метод, работающий с общим {@see TokenizerState}.
     *
     * @return Token[][]
     */
    public function tokenizeWithAutomaton(string $expression): array
    {
        $st = new TokenizerState();
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            // Режимы, поглощающие любой символ до собственного терминатора.
            if ($st->name === 'string') {
                $this->consumeString($st, $char);
                continue;
            }
            if ($st->name === 'method_params') {
                $this->consumeMethodParams($st, $char);
                continue;
            }
            // Литералы забирают всё до своей закрывающей скобки — в том числе ';',
            // который иначе разорвал бы команду посреди тела switch или JSON-строки.
            if (isset(self::LITERAL_STATES[$st->name])) {
                $this->advanceState($st, $char);
                continue;
            }

            // Конец команды.
            if ($char === ';') {
                $this->flushToken($st);
                $this->flushCommand($st);
                continue;
            }

            // Пробел(ы) после идентификатора могут предшествовать '(' — тогда это
            // всё ещё вызов функции ('iif (...)' эквивалентно 'iif(...)').
            if ($st->name === 'identifier' && ctype_space($char)) {
                $st->name = 'identifier_ws';
                continue;
            }
            if ($st->name === 'identifier_ws') {
                if (ctype_space($char)) {
                    continue;
                }
                if ($char === '(') {
                    // 'identifier (' — вызов функции с пробелом перед скобкой.
                    $st->name = 'function_call';
                    $this->advanceState($st, $char); // buffer .= '(' и depth++
                    continue;
                }
                if ($char === '{' && $this->isSwitchKeyword($st->buffer)) {
                    // 'switch {' — начало тела switch с пробелом перед скобкой.
                    $this->openSwitchBody($st);
                    continue;
                }
                // Не скобка — идентификатор завершён, символ пересматриваем.
                $st->name = 'identifier';
                $this->flushToken($st);
                $i--;
                continue;
            }

            if (ctype_space($char)) {
                $this->flushToken($st);
                continue;
            }

            if ($char === '(' || $char === ')') {
                // handleParenthesis() возвращает false только для 'identifier(' — тогда
                // символ '(' нужно пробросить в состояние 'function_call' ниже.
                if ($this->handleParenthesis($st, $char)) {
                    continue;
                }
            }

            $this->advanceState($st, $char);

            if ($st->reconsider) {
                $st->reconsider = false;
                $i--; // Пересмотреть текущий символ в состоянии 'default'.
            }
        }

        // Завершаем последний токен и команду.
        $this->flushToken($st);
        $this->flushCommand($st);

        return $st->commands;
    }

    /**
     * Завершает накопленный токен (если он есть) и возвращает автомат в 'default'.
     */
    private function flushToken(TokenizerState $st): void
    {
        if ($st->buffer !== '') {
            $this->finalizeCurrentToken($st->tokens, $st->buffer, $st->name);
            $st->buffer = '';
        }
        $this->resetToDefault($st);
    }

    /** Возвращает автомат в 'default', сбрасывая счётчики литерала. */
    private function resetToDefault(TokenizerState $st): void
    {
        $st->name = 'default';
        $st->depth = 0;
        $st->quote = '';
        $st->escaped = false;
    }

    /**
     * Закрывает текущую команду, перенося её токены в список команд.
     */
    private function flushCommand(TokenizerState $st): void
    {
        if (!empty($st->tokens)) {
            $st->commands[] = $st->tokens;
            $st->tokens = [];
        }
    }

    /** Является ли буфер обращением к методу значения ('.toString', '.toJSON', ...). */
    private function isMethodCall(string $buffer): bool
    {
        return (bool) preg_match('/\.(' . implode('|', self::VALUE_METHODS) . ')$/', $buffer);
    }

    /**
     * Открыт ли конструкцией 'switch' — единственное место, где '{' начинает тело.
     */
    private function isSwitchKeyword(string $buffer): bool
    {
        return strtolower($buffer) === 'switch';
    }

    /**
     * Переводит автомат в тело switch. Ключевое слово из буфера отбрасывается,
     * в буфере остаётся сам блок '{...}' — его разберёт {@see SwitchToken}.
     */
    private function openSwitchBody(TokenizerState $st): void
    {
        $st->buffer = '{';
        $st->name = 'switch_body';
        $st->depth = 1;
        $st->quote = '';
        $st->escaped = false;
    }

    /**
     * Отслеживает строки и вложенность внутри поглощающего литерала.
     *
     * Наивный подсчёт скобок ломается, если внутри литерала есть строка со скобкой
     * или с ';' — поэтому строки распознаются здесь, и их содержимое на глубину не
     * влияет. Кавычки обоих видов: одинарные — строки BQL, двойные — строки JSON.
     *
     * @return bool true, если литерал закрылся на этом символе
     */
    private function trackLiteralDepth(TokenizerState $st, string $char, string $open, string $close): bool
    {
        // Внутри строки ищем только её конец, учитывая экранирование.
        if ($st->quote !== '') {
            if ($st->escaped) {
                $st->escaped = false;
            } elseif ($char === '\\') {
                $st->escaped = true;
            } elseif ($char === $st->quote) {
                $st->quote = '';
            }
            return false;
        }

        if ($char === "'" || $char === '"') {
            $st->quote = $char;
            return false;
        }

        if ($char === $open) {
            $st->depth++;
        } elseif ($char === $close) {
            return --$st->depth <= 0;
        }

        return false;
    }

    /** Создаёт токен завершённого литерала по имени состояния. */
    private function makeLiteralToken(string $state, string $buffer): Token
    {
        return match ($state) {
            'array' => new Token('array', $buffer),
            'function_call' => new FunctionCallToken($buffer),
            'switch_body' => new SwitchToken($buffer),
        };
    }

    /** Состояние 'string': копим символы до закрывающей кавычки. */
    private function consumeString(TokenizerState $st, string $char): void
    {
        if ($char === "'") {
            $st->tokens[] = new Token('string', $st->buffer);
            $st->buffer = '';
            $st->name = 'default';
        } else {
            $st->buffer .= $char;
        }
    }

    /** Состояние 'method_params': копим символы до закрывающей скобки. */
    private function consumeMethodParams(TokenizerState $st, string $char): void
    {
        $st->buffer .= $char;
        if ($char === ')') {
            $st->tokens[] = new Token('method_call', $st->buffer);
            $st->buffer = '';
            $st->name = 'default';
        }
    }

    /**
     * Обрабатывает '(' и ')' вне литералов.
     *
     * @return bool true — символ полностью обработан; false — 'identifier(' начинает
     *              вызов функции, символ нужно передать в состояние 'function_call'.
     */
    private function handleParenthesis(TokenizerState $st, string $char): bool
    {
        // Идентификатор, за которым сразу идёт '(', начинает литерал вызова функции.
        if ($st->name === 'identifier' && $char === '(') {
            $st->name = 'function_call';
            return false;
        }

        if ($st->buffer !== '') {
            // '.toString(' / '.toNum(' у вложенной переменной начинает вызов метода.
            if ($st->name === 'sausage' && $char === '(' && $this->isMethodCall($st->buffer)) {
                $st->buffer .= $char;
                $st->name = 'method_params';
                return true;
            }
            $this->flushToken($st);
        }

        $st->tokens[] = new Token('parenthesis', $char);
        return true;
    }

    /**
     * Выполняет один шаг автомата для классифицирующих и литеральных состояний.
     */
    private function advanceState(TokenizerState $st, string $char): void
    {
        switch ($st->name) {
            case 'default':
                if ($char === "'") {
                    $st->name = 'string';
                } elseif ($char === '[') {
                    $st->buffer = $char;
                    $st->name = 'array';
                    $st->depth = 1;
                } elseif (ctype_digit($char)) {
                    $st->buffer = $char;
                    $st->name = 'number';
                } elseif (ctype_alpha($char) || $char === '_') {
                    $st->buffer = $char;
                    $st->name = 'identifier';
                } elseif (!ctype_space($char)) {
                    $st->buffer = $char;
                    $st->name = 'operator';
                }
                break;

            case 'number':
                if (ctype_digit($char) || $char === '.') {
                    $st->buffer .= $char;
                } else {
                    $st->tokens[] = new Token('number', $st->buffer);
                    $this->resetForReconsider($st);
                }
                break;

            // Литералы: '[...]', 'name(...)', 'switch {...}'. Копим до парной
            // закрывающей скобки, не считая скобок внутри строк.
            case 'array':
            case 'function_call':
            case 'switch_body':
                $st->buffer .= $char;
                [$open, $close] = self::LITERAL_STATES[$st->name];
                if ($this->trackLiteralDepth($st, $char, $open, $close)) {
                    $token = $this->makeLiteralToken($st->name, $st->buffer);
                    $st->buffer = '';
                    $this->resetToDefault($st);
                    $st->tokens[] = $token;
                }
                break;

            case 'identifier':
                if (ctype_alnum($char) || $char === '_') {
                    $st->buffer .= $char;
                } elseif ($char === '.') {
                    $st->buffer .= $char;
                    $st->name = 'sausage';
                } elseif ($char === '{' && $this->isSwitchKeyword($st->buffer)) {
                    // 'switch{' — единственный случай, когда '{' начинает конструкцию.
                    $this->openSwitchBody($st);
                } else {
                    $st->tokens[] = new Token('identifier', $st->buffer);
                    $this->resetForReconsider($st);
                }
                break;

            case 'sausage':
                if (ctype_alnum($char) || $char === '_' || $char === '.') {
                    $st->buffer .= $char;
                } elseif ($char === '(' && $this->isMethodCall($st->buffer)) {
                    // Обычно перехватывается в handleParenthesis(); оставлено для полноты.
                    $st->buffer .= $char;
                    $st->name = 'method_params';
                } else {
                    $st->tokens[] = new Token('sausage', $st->buffer);
                    $this->resetForReconsider($st);
                }
                break;

            case 'operator':
                if (!ctype_alnum($char) && $char !== '_' && !ctype_space($char) && $char !== '(' && $char !== ')') {
                    $st->buffer .= $char;
                } else {
                    $st->tokens[] = new Token('operator', strtolower($st->buffer));
                    $this->resetForReconsider($st);
                }
                break;
        }
    }

    /** Сбрасывает буфер/состояние и просит повторно рассмотреть текущий символ. */
    private function resetForReconsider(TokenizerState $st): void
    {
        $st->buffer = '';
        $st->name = 'default';
        $st->reconsider = true;
    }


public function toReversePolishNotation(array $tokens): array
{
    $output = [];
    $operators = [];
    // Ждём ли операнд. В начале выражения — да, поэтому '-' здесь знак числа,
    // а не вычитание. Дальше флаг переключается по ходу потока токенов.
    $expectOperand = true;
    $tokens = array_values($tokens);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        /*  @var  Token $token */
        $tokenType = $token->getType();
        $tokenValue = $token->getValue();

        // Знак в позиции операнда — унарная форма: '-5', 'a * -b', '-(a + b)'.
        if ($tokenType === 'operator' && $expectOperand && isset(self::UNARY_SIGNS[$tokenValue])) {
            $tokenValue = self::UNARY_SIGNS[$tokenValue];
            $token = new Token('operator', $tokenValue);
        }

        // 'not' в позиции оператора — приставка к следующему слову: 'not in'.
        // Автомат режет токены по пробелам и операторов из двух слов не знает,
        // поэтому 'not' всегда приезжает идентификатором — что он такое на самом
        // деле, видно только по позиции, ровно как у знака числа выше. В позиции
        // операнда 'not' остаётся обычным именем переменной.
        if ($tokenType === 'identifier' && !$expectOperand
            && strtolower((string) $tokenValue) === 'not'
            && isset($tokens[$i + 1])
            && $tokens[$i + 1]->getType() === 'operator'
            && isset(self::NEGATED_OPERATORS['not ' . $tokens[$i + 1]->getValue()])
        ) {
            $tokenValue = 'not ' . $tokens[++$i]->getValue();
            $tokenType = 'operator';
            $token = new Token('operator', $tokenValue);
        }

        // Функции и другие не-операторы идут прямо в вывод
        if ($tokenType !== 'operator' && $tokenType !== 'parenthesis') {
            // Два значения подряд — между ними потерян оператор. Молчать тут
            // нельзя: лишний токен уезжал в стек ОПН, откуда его вытеснял
            // результат, и '5 not > 1' тихо считалось как 'null > 1'. Ошибка в
            // правиле должна быть видна, а не превращаться в чужое значение.
            if (!$expectOperand) {
                throw new InvalidArgumentException(
                    "Unexpected '{$tokenValue}': an operator is missing before it."
                );
            }

            $output[] = $token;
            $expectOperand = false;
        }
        // Открывающая скобка (не функция)
        elseif ($tokenValue === '(') {
            $operators[] = $token;
            $expectOperand = true;
        }
        // Закрывающая скобка
        elseif ($tokenValue === ')') {
            // Выталкиваем операторы до открывающей скобки
            while (!empty($operators) && end($operators)->getValue() !== '(') {
                $output[] = array_pop($operators);
            }
            // Удаляем открывающую скобку
            if (!empty($operators)) {
                array_pop($operators);
            }
            $expectOperand = false;
        }
        // Операторы
        else {
            // Обработка операторов с учётом приоритета и ассоциативности
            while (
                !empty($operators) &&
                end($operators)->getType() === 'operator' &&
                (
                    $this->getPrecedence(end($operators)->getValue()) > $this->getPrecedence($tokenValue) ||
                    (
                        $this->getPrecedence(end($operators)->getValue()) === $this->getPrecedence($tokenValue) &&
                        ($this->operators[strtolower($tokenValue)]['associativity'] ?? 'left') === 'left'
                    )
                )
            ) {
                $output[] = array_pop($operators);
            }
            $operators[] = $token;
            // После бинарного и префиксного оператора снова ждём операнд, а вот
            // после постфиксного ('a++ - b') значение уже готово — там '-' бинарный.
            // У '++'/'--' форму задаёт тот же флаг: в позиции операнда ('--x') это
            // префикс, и операнд всё ещё нужен, поэтому флаг остаётся как был.
            if (!in_array($tokenValue, self::POSTFIX_OPERATORS, true)) {
                $expectOperand = true;
            }
        }
    }

    // Выталкиваем оставшиеся операторы
    while (!empty($operators)) {
        $output[] = array_pop($operators);
    }

    return $output;
}

    public function evaluateRPN(array $rpn)
    {
        $token = $this->evaluateRPNToToken($rpn);

        if ($token === null) {
            return null;
        }

        if ($this->isDeferredToken($token)) {
            return $this->resolveValue($token);
        }

        return $token->getValue();
    }

    /**
     * Прогоняет ОПН через стек и возвращает токен-результат (или null, если стек пуст).
     *
     * Отделено от evaluateRPN(), чтобы вызывающий мог решить сам, что делать с
     * итоговым токеном: развернуть в «сырое» значение или в обработчик значения.
     */
    private function evaluateRPNToToken(array $rpn): ?Token
    {
        $stack = [];

        foreach ($rpn as $token) {
            /* @var Token $token */
            if ($token->getType() !== 'operator') {
                $stack[] = $token;
            } else {
                $operator = $token->getValue();

                // Без этой проверки неизвестный оператор давал warning об отсутствующем
                // ключе и падал где-то дальше с ArgumentCountError вместо внятной ошибки.
                if (!isset($this->operators[$operator])) {
                    throw new InvalidArgumentException("Operator '$operator' is not defined.");
                }

                $operandCount = $this->operators[$operator]['operandCount'];

                // Проверяем, достаточно ли операндов в стеке
                if (count($stack) < $operandCount) {
                    throw new LogicException("Not enough operands for operator '$operator'. Required: $operandCount, available: " . count($stack));
                }

                // Извлекаем операнды
                $operands = [];
                for ($i = 0; $i < $operandCount; $i++) {
                    $operands[] = array_pop($stack);
                }

                //\log::log_message('debug', "Operands for operator '$operator': " . var_export($operands, true));

                $operands = array_reverse($operands);

                // Выполняем оператор
                $result = $this->executeOperator($operator, ...$operands);

                // Если оператор изменяет переменную, обновляем её значение
                if ($this->operators[$operator]['modifiesVariable']) {
                    $variableName = $operands[0]->getValue();
                    $this->variableStorage->modifyVariable($variableName, $result);
                    //$this->variables[$variableName] = $result;
                }

                // Добавляем результат обратно в стек
                if (!empty($result))
                {
                    $stack[] = new Token('variable', $result);
                }

            }
        }

        // Убедимся, что в стеке осталось ровно одно значение (результат вычисления)
        /*
        if (count($stack) !== 1) {
            throw new LogicException("Invalid RPN evaluation. Stack state: " . var_export($stack, true));
        }*/

        return count($stack) > 0 ? array_pop($stack) : null;
    }

    /**
     * Ссылается ли токен на ещё не вычисленное значение.
     *
     * Такие токены несут имя/путь/тело, а не значение, поэтому на вершине стека их
     * надо доразрешить через resolveValue(). Раньше так обрабатывался только
     * 'sausage', из-за чего 'iif(a, 5, 9)' получал строку 'a' вместо значения `a`
     * и любое условие из одной переменной молча считалось ложным.
     *
     * Тип 'array' сюда намеренно не входит: у литерала '[1,2]' значение — это текст
     * литерала, а у результата операции — уже настоящий PHP-массив, и различить их
     * по типу нельзя. Проверка is_string() страхует от того же на остальных типах.
     */
    private function isDeferredToken(Token $token): bool
    {
        return in_array($token->getType(), ['identifier', 'sausage', 'function_call', 'method_call', 'switch'], true)
            && is_string($token->getValue());
    }

    /**
     * Вычисляет строку-подвыражение и возвращает обработчик значения.
     *
     * Нужен там, где подвыражение — часть большей конструкции: аргументы функций и
     * плечи switch. В отличие от evaluateRPN() корректно отдаёт и литералы массивов:
     * '[{"a":1}]' превращается в ArrayHandler, а не в текст литерала.
     */
    public function evaluateExpressionToHandler(string $expression): ?AbstractVariableHandler
    {
        $commands = $this->tokenizeWithAutomaton($expression);

        if (empty($commands[0])) {
            return null;
        }

        $token = $this->evaluateRPNToToken($this->toReversePolishNotation($commands[0]));

        if ($token === null) {
            return null;
        }

        // Ссылки и литералы разрешает resolveValue(): она знает, как превратить
        // путь, вызов или текст '[{"a":1}]' в обработчик нужного типа.
        if ($this->isDeferredToken($token) || $token->getType() === 'array') {
            return $this->resolveValue($token);
        }

        // Остальное на вершине стека — уже посчитанное значение.
        $value = $token->getValue();

        return VariableHandlerFactory::createHandler(
            $value,
            'expression_result',
            null,
            $this->variableStorage
        );
    }

    /**
     * Подставляет '{{ выражение }}' в строках разобранной JSON-структуры.
     *
     * Обходятся и значения, и ключи, на любой глубине вложенности.
     */
    private function interpolatePlaceholders(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? $this->interpolateText($key) : $key;

            if (!is_string($newKey) && !is_int($newKey)) {
                throw new InvalidArgumentException(
                    "Placeholder in JSON key '$key' must produce a string or a number"
                );
            }

            if (is_array($value)) {
                $result[$newKey] = $this->interpolatePlaceholders($value);
            } elseif (is_string($value)) {
                $result[$newKey] = $this->interpolateText($value);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Вычисляет подстановки в одной строке.
     *
     * Если строка целиком состоит из одной подстановки, возвращается значение
     * выражения СО СВОИМ ТИПОМ: денежная сумма останется строкой bcmath, целое —
     * целым, массив — массивом. Если подстановка стоит внутри текста, склеиваются
     * строковые представления — тип неизбежно теряется.
     *
     * @return mixed строка, либо значение единственной подстановки
     */
    private function interpolateText(string $text): mixed
    {
        if (!str_contains($text, '{{')) {
            return $text; // Быстрый путь: подстановок нет.
        }

        $segments = LiteralScanner::splitPlaceholders($text);

        if (count($segments) === 1 && $segments[0]['type'] === 'expr') {
            $handler = $this->evaluateExpressionToHandler($segments[0]['value']);

            return $handler === null ? null : $handler->get();
        }

        $out = '';

        foreach ($segments as $segment) {
            if ($segment['type'] === 'text') {
                $out .= $segment['value'];
                continue;
            }

            $handler = $this->evaluateExpressionToHandler($segment['value']);

            if ($handler === null) {
                continue;
            }

            $stringHandler = $handler->toString();
            $out .= $stringHandler === null ? '' : (string) $stringHandler->get();
        }

        return $out;
    }

    public function &resolveValue(Token $token): ?AbstractVariableHandler
    {
        $null = null;

        if ($token->getType() == 'variable') {
            $varr = $token->getValue();
            return $varr;
        }


        // Добавляем обработку function_call
        if ($token->getType() == 'function_call') {

                $result = $this->executeFunctionCallToken($token);
            return $result;
        }

        // switch как выражение: сам выбирает плечо и возвращает его значение.
        if ($token instanceof SwitchToken) {
            $result = $token->evaluate($this);

            if ($result === null) {
                // Ни одно условие не подошло и нет 'default'.
                $null = $this->variableStorage->getVariable('null');
                return $null;
            }

            return $result;
        }

        if ($token->getType() == 'method_call') {
            // Обработка вызовов методов значения: .toString(), .toNum(), .toJSON()
            $methodCall = $token->getValue();

            // Извлекаем переменную и метод
            if (preg_match('/^(.+)\.(' . implode('|', self::VALUE_METHODS) . ')\(\)$/', $methodCall, $matches)) {
                $variableName = $matches[1];
                $methodName = $matches[2];

                // Определяем тип токена для переменной
                $variableTokenType = (strpos($variableName, '.') !== false) ? 'sausage' : 'identifier';

                // Получаем обработчик переменной через рекурсивный вызов
                $variableHandler = $this->resolveValue(new Token($variableTokenType, $variableName));


                //VariableHandlerFactory::createHandler($variableName,$variableName,null,$this->variableStorage);
                if ($variableHandler) {
                    // Имена в VALUE_METHODS совпадают с именами методов обработчика,
                    // поэтому вызываем напрямую — список сверху и служит белым списком.
                    $result = $variableHandler->$methodName();
                    return $result;
                }


                // Получаем базовый обработчик переменной
                $baseHandler = null;
                if (strpos($variableName, '.') !== false) {
                    // Вложенная переменная
                    $baseHandler = VarTypes\SausageVarHandler::createForNestedVariable(
                        $variableName,
                        $this->variableStorage
                    );
                } else {
                    // Обычная переменная
                    $baseHandler = $this->variableStorage->getVariable($variableName);
                }

                if ($baseHandler) {
                    // Отмечаем использование переменной
                    $this->variableStorage->markUsed($variableName, $baseHandler->get());

                    // Вызываем соответствующий метод и возвращаем результат
                    $result = $baseHandler->$methodName();
                    return $result;
                }
            }

            // Возвращаем null-обработчик если метод не найден
            $null =$this->variableStorage->getVariable('null');
            return $null;
        }

        // Литерал массива: разбираем данные, затем подставляем '{{ ... }}'.
        // Подстановка живёт здесь, а не в фабрике, потому что ей нужен интерпретатор
        // для вычисления выражений, а фабрика о нём ничего не знает.
        if ($token->getType() == 'array' && is_string($token->getValue())) {
            $parsed = VariableHandlerFactory::parseArrayLiteral($token->getValue());
            $interpolated = $this->interpolatePlaceholders($parsed);

            $arrayHandler = new VarTypes\ArrayHandler(
                'array_literal',
                $interpolated,
                null,
                $this->variableStorage
            );

            return $arrayHandler;
        }

        // Обработка других типов токенов
        $varHandler = null;

        if ($token->getType() == 'sausage') {
            $varHandler = VarTypes\SausageVarHandler::createForNestedVariable(
                $token->getValue(),
                $this->variableStorage
            );

            if (!$varHandler) {
                $null =$this->variableStorage->getVariable('null');
                return $null;
            }
        } elseif ($token->getType() == 'identifier') {
            $varHandler = $this->variableStorage->getVariable($token->getValue());

            if (!$varHandler) {
                $null =$this->variableStorage->getVariable('null');
                return $null;
            }
        } else {
            $varHandler = VariableHandlerFactory::createHandlerByTokenValue(
                $token,
                $token->getValue(),
                $token->getValue(),
                null,
                $this->variableStorage
            );
        }

        if ($token->getType() == 'identifier' || $token->getType() == 'sausage') {
            if ($varHandler) {
                $this->variableStorage->markUsed($token->getValue(), $varHandler->get());
            }
        }

        return $varHandler;
    }

    public function tempSetVar()
    {
        $this->variableStorage->modifyVariable('Rez', 25);
    }

    private function finalizeCurrentToken(array &$tokens, string &$currentToken, string &$state): void
    {
        switch ($state) {
            case 'identifier':
                // Проверяем, не является ли идентификатор словесным оператором
                $lowerToken = strtolower($currentToken);
                if (isset($this->operators[$lowerToken])) {
                    $tokens[] = new Token('operator', $lowerToken);
                } elseif (isset($this->functions[$lowerToken])) {
                    // Проверяем, является ли идентификатор зарегистрированной функцией
                    $tokens[] = new Token('function', $currentToken);
                } else {
                    $tokens[] = new Token('identifier', $currentToken);
                }
                break;
            case 'number':
                $tokens[] = new Token('number', $currentToken);
                break;
            case 'operator':
                $tokens[] = new Token('operator', strtolower($currentToken));
                break;
            case 'sausage':
                $tokens[] = new Token('sausage', $currentToken);
                break;
            case 'function_call':
                // Создаем FunctionCallToken, который сам парсит вызов
                $tokens[] = new FunctionCallToken($currentToken);
                break;
            case 'switch_body':
                // Сюда попадаем только при незакрытой '}' — иначе токен уже создан
                // в advanceState(). Явная ошибка понятнее, чем разбор огрызка.
                throw new InvalidArgumentException(
                    "Unterminated switch block: missing closing '}' in '$currentToken'"
                );
            default:
                if ($currentToken !== '') {
                    $tokens[] = new Token($state, $currentToken);
                }
                break;
        }
    }

    /**
     * Выполняет функцию
     */
    private function executeFunctionCallToken(FunctionCallToken $functionToken): ?AbstractVariableHandler
    {
        $functionName = $functionToken->getFunctionName();

        if (!isset($this->functions[$functionName])) {
            throw new \InvalidArgumentException("Function '$functionName' is not defined.");
        }

        $functionConfig = $this->functions[$functionName];

        // Вычисляем параметры через метод токена
        $args = $functionToken->evaluateParameters($this);

        // Проверяем количество аргументов
        $argCount = count($args);
        if ($argCount < $functionConfig['minArgs'] || $argCount > $functionConfig['maxArgs']) {
            throw new \InvalidArgumentException(
                "Function '$functionName' expects {$functionConfig['minArgs']}-{$functionConfig['maxArgs']} arguments, got $argCount"
            );
        }

        // Выполняем функцию
        $result = call_user_func_array($functionConfig['callback'], $args);

        if ($functionConfig['returnType'] == null)
        {
            // Создаем обработчик для результата
            return VariableHandlerFactory::createHandler(
                $result,
                "function_result_$functionName",
                null,
                $this->variableStorage
            );
        }else
        {
            $type_name = 'iustato\\Bql\\VarTypes\\'.$functionConfig['returnType'];
            /* @var AbstractVariableHandler $type_name */
            $typeWorker = new $type_name("function_result_$functionName", $result, null, $this->variableStorage);

            $this->variableStorage->addAnonymousVariableHandler($typeWorker);

            return $typeWorker;
        }
    }

    /**
     * Создает токен из строки
     */
    private function createTokenFromString(string $str): Token
    {
        $str = trim($str);
        
        // Проверяем на пустую строку или только запятую
        if ($str === '' || $str === ',') {
            return new Token('identifier', 'null'); // Возвращаем null токен
        }

        // Число
        if (is_numeric($str)) {
            return new Token('number', $str);
        }

        // Строка в кавычках
        if (preg_match("/^'([^']*)'$/", $str, $matches)) {
            return new Token('string', $matches[1]);
        }

        // Массив
        if (preg_match('/^\[.*\]$/', $str)) {
            return new Token('array', $str);
        }

        // Вложенная переменная (sausage)
        if (strpos($str, '.') !== false) {
            return new Token('sausage', $str);
        }

        // Обычная переменная
        return new Token('identifier', $str);
    }
}