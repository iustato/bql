<?php

namespace iustato\Bql;

use InvalidArgumentException;
use iustato\Bql\VarTypes\BoolVarHandler;
use iustato\Bql\VarTypes\SimpleVarHandler;
use LogicException;
use iustato\Bql\VarTypes\AbstractVariableHandler;

class ExpressionInterpreter
{
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
        //     < + - . < * / < унарный ! < ++ --
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

        // Аддитивные и конкатенация.
        $this->registerOperator('+', 7, 'left', false, 2);
        $this->registerOperator('-', 7, 'left', false, 2);
        $this->registerOperator('.', 7, 'left', false, 2);

        // Мультипликативные.
        $this->registerOperator('*', 8, 'left', false, 2);
        $this->registerOperator('/', 8, 'left', false, 2);

        // Унарные (самый высокий приоритет).
        $this->registerOperator('!', 9, 'right', false, 1);
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

        // Функция max
        $this->registerFunction('max', function (...$args) {
            $values = array_map(function ($arg) {
                return $arg instanceof AbstractVariableHandler ? $arg->get() : $arg;
            }, $args);
            return max($values);
        }, 1);

        // Функция min
        $this->registerFunction('min', function (...$args) {
            $values = array_map(function ($arg) {
                return $arg instanceof AbstractVariableHandler ? $arg->get() : $arg;
            }, $args);
            return min($values);
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


    private function executeOperator(string $operator, Token &$a, Token &$b = null): ?AbstractVariableHandler
    {
        $operator_lower = strtolower($operator);

        if (!isset($this->operators[$operator_lower])) {
            throw new InvalidArgumentException("Operator '$operator_lower' is not defined.");
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
                // Не скобка — идентификатор завершён, символ пересматриваем.
                $st->name = 'identifier';
                $this->flushToken($st);
                $i--;
                continue;
            }

            // Для литералов массива и вызова функции пробелы и скобки — часть токена.
            $isLiteral = ($st->name === 'array' || $st->name === 'function_call');

            if (!$isLiteral && ctype_space($char)) {
                $this->flushToken($st);
                continue;
            }

            if (!$isLiteral && ($char === '(' || $char === ')')) {
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
        $st->name = 'default';
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

    /** Является ли буфер обращением к методу '.toString'/'.toNum'. */
    private function isMethodCall(string $buffer): bool
    {
        return (bool) preg_match('/\.(toString|toNum)$/', $buffer);
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

            case 'array':
                $st->buffer .= $char;
                if ($char === ']') {
                    if (--$st->depth <= 0) {
                        $st->tokens[] = new Token('array', $st->buffer);
                        $st->buffer = '';
                        $st->name = 'default';
                    }
                } elseif ($char === '[') {
                    $st->depth++;
                }
                break;

            case 'identifier':
                if (ctype_alnum($char) || $char === '_') {
                    $st->buffer .= $char;
                } elseif ($char === '.') {
                    $st->buffer .= $char;
                    $st->name = 'sausage';
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

            case 'function_call':
                $st->buffer .= $char;
                if ($char === '(') {
                    $st->depth++;
                } elseif ($char === ')') {
                    if (--$st->depth <= 0) {
                        $st->tokens[] = new FunctionCallToken($st->buffer);
                        $st->buffer = '';
                        $st->name = 'default';
                    }
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

    foreach ($tokens as $token) {
        /*  @var  Token $token */
        $tokenType = $token->getType();
        $tokenValue = $token->getValue();

        // Функции и другие не-операторы идут прямо в вывод
        if ($tokenType !== 'operator' && $tokenType !== 'parenthesis') {
            $output[] = $token;
        } 
        // Открывающая скобка (не функция)
        elseif ($tokenValue === '(') {
            $operators[] = $token;
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
        $stack = [];

        foreach ($rpn as $token) {
            /* @var Token $token */
            if ($token->getType() !== 'operator') {
                $stack[] = $token;
            } else {
                $operator = $token->getValue();
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

        if (count($stack) > 0) {

            $token_var = array_pop($stack);

            if ($token_var instanceof Token && $token_var->getType() == 'sausage')
            {
                $var = $this->resolveValue($token_var);
            }
            else
            {
                $var = $token_var->getValue();
            }

            return $var;    //array_pop($stack)->getValue();
        } else {
            return null;
        }
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

        if ($token->getType() == 'method_call') {
            // Обработка вызовов методов .toString() и .toNum()
            $methodCall = $token->getValue();

            // Извлекаем переменную и метод
            if (preg_match('/^(.+)\.(toString|toNum)\(\)$/', $methodCall, $matches)) {
                $variableName = $matches[1];
                $methodName = $matches[2];

                // Определяем тип токена для переменной
                $variableTokenType = (strpos($variableName, '.') !== false) ? 'sausage' : 'identifier';

                // Получаем обработчик переменной через рекурсивный вызов
                $variableHandler = $this->resolveValue(new Token($variableTokenType, $variableName));


                //VariableHandlerFactory::createHandler($variableName,$variableName,null,$this->variableStorage);
                if ($variableHandler) {
                    if ($methodName === 'toString') {
                        $result = $variableHandler->toString();
                        return $result;
                    } elseif ($methodName === 'toNum') {
                        $result = $variableHandler->toNum();
                        return $result;
                    }
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
                    if ($methodName === 'toString') {
                        $result = $baseHandler->toString();
                        return $result;
                    } elseif ($methodName === 'toNum') {
                        $result = $baseHandler->toNum();
                        return $result;
                    }
                }
            }

            // Возвращаем null-обработчик если метод не найден
            $null =$this->variableStorage->getVariable('null');
            return $null;
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