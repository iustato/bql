<?php

namespace iustato\Bql;

use iustato\Bql\VarTypes\SimpleVarHandler;
use InvalidArgumentException;
use LogicException;
use iustato\Bql\VarTypes\AbstractVariableHandler;

class ExpressionInterpreter
{
    /* @var AbstractVariableHandler[] $variables */
    private array $variables = [];
    private $operators = [];

    private $modifiedVariables = [];

    private $usedVariables = [];

    public function __construct()
    {
        // Регистрация операторов
        $this->registerOperator('=', function (&$a, $b) {
            $a = $b;
            return $a;
        }, 1, 'left', true, 2);
        $this->registerOperator('&&', fn(&$a, $b) => $a && $b, 3, 'right');
        $this->registerOperator('AND', fn(&$a, $b) => $a && $b, 3, 'right');
        $this->registerOperator('||', fn(&$a, $b) => $a || $b, 2);
        $this->registerOperator('OR', fn(&$a, $b) => $a || $b, 2);
        $this->registerOperator('!', fn(&$a) => !$a, 4, 'right', false, 1);
        $this->registerOperator('<', fn(&$a, $b) => $a < $b, 3);
        $this->registerOperator('>', fn(&$a, $b) => $a > $b, 3);
        $this->registerOperator('<=', fn(&$a, $b) => $a <= $b, 3);
        $this->registerOperator('>=', fn(&$a, $b) => $a >= $b, 3);
        $this->registerOperator('==', fn(&$a, $b) => trim($a) === trim($b), 3);
        $this->registerOperator('!=', fn(&$a, $b) => $a != $b, 3);
        $this->registerOperator('??', function(&$a, $b)
        {
            if (is_null($a)) return $b;
            else return $a;
        } , 3);
        $this->registerOperator('in', function (&$a, $b) {
            if (!is_array($b)) {
                throw new InvalidArgumentException("Right-hand side of 'in' must be an array");
            }
            return in_array($a, $b);
        }, 3);
        $this->registerOperator('like', function (&$a, $b) {
            $pattern = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($b, '/')) . '$/i';
            return preg_match($pattern, $a) === 1;
        }, 3);

        $this->registerOperator('+', fn(&$a, $b) => $a + $b, 3, 'left', false, 2);
        $this->registerOperator('-', fn(&$a, $b) => $a - $b, 3, 'left', false, 2);
        $this->registerOperator('*', fn(&$a, $b) => $a * $b, 4, 'left', false, 2);
        $this->registerOperator('/', fn(&$a, $b) => $b == 0 ? throw new InvalidArgumentException("Division by zero") : $a / $b, 4, 'left', false, 2);
        $this->registerOperator('++', fn(&$a) => ++$a, 5, 'right', true, 1);
        $this->registerOperator('--', fn(&$a) => --$a, 5, 'right', true, 1);
        $this->registerOperator('+=', fn(&$a, $b) => $a += $b, 3, 'left', true, 2);
        $this->registerOperator('-=', fn(&$a, $b) => $a -= $b, 3, 'left', true, 2);

        // default variables:
        $true = true; $false = false; $null = null;
        $this->variables['true'] = new SimpleVarHandler('true', $true);
        $this->variables['false'] = new SimpleVarHandler('false', $false);
        $this->variables['null'] = new SimpleVarHandler('null', $null);;
    }

    public function registerOperator(string $operator, callable $callback, int $precedence, string $associativity = 'left', bool $modifiesVariable = false, int $operandCount = 2): void
    {
        $operator_lower = strtolower($operator);
        $this->operators[$operator_lower] = [
            'callback' => $callback,
            'precedence' => $precedence,
            'associativity' => $associativity,
            'modifiesVariable' => $modifiesVariable,
            'operandCount' => $operandCount,
        ];
    }

    private function executeOperator(string $operator, Token &$a, Token &$b = null)
    {
        $operator_lower = strtolower($operator);
        if (!isset($this->operators[$operator_lower])) {
            throw new InvalidArgumentException("Operator '$operator_lower' is not defined.");
        }

        $operatorConfig = $this->operators[$operator_lower];

        $token_value = $a->getValue();
        $var_a = $this->resolveValue($a);

        $null = null;
        if (isset($var_a)) {
            $var_a_value = &$var_a->get();
        } else {
            $var_a_value = &$null;
        }

        if (isset($b)) {
            $var_b = $this->resolveValue($b);
            $var_b_value = $var_b->get();
        } else {
            $var_b = null;
            $var_b_value = null;
        }

        //     \log::log_message('debug', "call ".$operator_lower." callback_func: ".var_export($operatorConfig['callback'],true));
        //$result = call_user_func($operatorConfig['callback'], $var_a_value, $var_b_value);
        // ебучий call_user_func плюёт на передачу параметра по ссылке и передат копию значения
        $result = ($operatorConfig['callback'])($var_a_value, $var_b_value);
        if ($operatorConfig['modifiesVariable']) {
            $variable = $this->resolveValue($a);  //$this->variables[$token_value];
            $variable->set( '', $result);

            $this->modifiedVariables[$token_value] = $result;
        }

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
        foreach ($variables as $key => &$value) {
            // получаем начальные значения переменных, объектов и т.д.
            $this->variables[$key] = VariableHandlerFactory::createHandler($value, $key, null);
        }

        $this->modifiedVariables = [];
    }

    public function addVariable ($var_name, &$var_value)
    {
        $this->variables[$var_name] = &$var_value;
    }

    public function getModifiedVariables(): array
    {
        return $this->modifiedVariables;
    }

    public function getUsedVariables(): array
    {
        return $this->usedVariables;
    }

    public function getVariables(): array
    {
        return $this->variables;
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

    /* @return Token[][] */
    private function tokenizeWithAutomaton(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $currentToken = null;
        $state = 'default';
        $commands = [];
        $nestedLevel = 0; // Для отслеживания вложенности массивов

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($state == 'string') {
                $currentToken .= $char;
                if ($char === "'") {
                    $tokens[] = new Token('string', $currentToken);
                    $currentToken = '';
                    $state = 'default';
                }
                continue;
            } elseif ($char === ';') {
                // Если символ это `;` и мы не в режиме строки, то заканчиваем текущую команду

                if ($currentToken !== '') {
                    if ($state == 'identifier_or_operator') {
                        if (in_array($currentToken, $this->getOperators())) {
                            $state = 'operator';
                        } else {
                            $state = 'identifier';
                        }
                    }

                    $tokens[] = new Token($state, $currentToken);
                    $currentToken = '';
                    $state = 'default';
                }

                if (!empty($tokens)) {
                    $commands[] = $tokens;
                    $tokens = [];
                    continue;
                }
            } elseif ($state != 'array' && (ctype_space($char) || $char == '(')) {
                // если это пробел, то заканчиваем с токеном
                if ($currentToken !== '') {
                    if ($state == 'identifier_or_operator') {
                        if (in_array(strtolower($currentToken), $this->getOperators())) {
                            $currentToken = strtolower($currentToken);
                            $state = 'operator';
                        } else {
                            $state = 'identifier';
                        }
                    }

                    $tokens[] = new Token($state, $currentToken);
                    $currentToken = '';
                    $state = 'default';
                }
            }


            switch ($state) {
                case 'default':
                    if (ctype_space($char)) {
                        break; //continue;
                    }
                    if ($char === '(' || $char === ')') {
                        $tokens[] = new Token('parenthesis', $char);
                    } elseif (ctype_digit($char)) {
                        $currentToken .= $char;
                        $state = 'number';
                    } elseif ($char === "'") {
                        $currentToken .= $char;
                        $state = 'string';
                    } elseif ($char === '[') {
                        $currentToken .= $char;
                        $state = 'array';
                        $nestedLevel++;
                    } elseif (!ctype_alnum($char) && $char != '_') {
                        $currentToken .= $char;
                        $state = 'operator';
                    } else {
                        $currentToken .= $char;
                        $state = 'identifier_or_operator';
                        //throw new InvalidArgumentException("Unexpected character: $char");
                    }
                    break;
                case 'number':
                    if (ctype_digit($char)) {
                        $currentToken .= $char;
                    } else {
                        $tokens[] = new Token('number', $currentToken);
                        $currentToken = '';
                        $state = 'default';
                        $i--;
                    }
                    break;
                case 'array':
                    if (ctype_space($char)) {
                        break;
                    }

                    $currentToken .= $char;
                    if ($char === ']') {
                        $nestedLevel--;
                        if ($nestedLevel <= 0) {
                            $tokens[] = new Token('array', $currentToken);
                            $currentToken = '';
                            $state = 'default';
                        }
                    } elseif ($char === '[') {
                        $nestedLevel++;
                    }
                    break;
                case 'operator':
                    if (!ctype_alnum($char)) {
                        $currentToken .= $char;
                    } else {
                        $tokens[] = new Token('operator', strtolower($currentToken));
                        $currentToken = '';
                        $state = 'default';
                        $i--;
                    }

                    break;
                case 'identifier':
                    if (ctype_alnum($char) || in_array($char, ['_', '.'])) {
                        $currentToken .= $char;
                    } else {
                        $tokens[] = new Token('identifier', $currentToken);
                        $currentToken = '';
                        $state = 'default';
                        $i--;
                    }
                    break;
                case 'identifier_or_operator':
                    if (ctype_alnum($char)) {
                        $currentToken .= $char;
                    } elseif (in_array($char, ['_', '.'])) {
                        $currentToken .= $char;
                        $state = 'identifier';
                    } else {
                        if (in_array(strtolower($currentToken), $this->getOperators())) {
                            $tokens[] = new Token('operator', strtolower($currentToken));
                        } else {
                            $tokens[] = new Token('identifier', $currentToken);
                        }
                        $currentToken = '';
                        $state = 'default';
                        $i--;
                    }
                    break;
            }
        }

        if ($currentToken !== '') {
            $tokens[] = new Token($state, $currentToken);
        }

        // Добавляем последнюю команду, если есть незавершённые токены
        if (!empty($tokens)) {
            $commands[] = $tokens;
        }

        return $commands;
    }


    private function toReversePolishNotation(array $tokens): array
    {
        $output = [];
        $operators = [];

        foreach ($tokens as $token) {
            /*  @var  Token $token */
            if ($token->getType() !== 'operator' && $token->getType() !== 'parenthesis') {
                $output[] = $token;
            } elseif ($token->getValue() === '(') {
                $operators[] = $token;
            } elseif ($token->getValue() === ')') {
                while (!empty($operators) && end($operators)->getValue() !== '(') {
                    $output[] = array_pop($operators);
                }
                array_pop($operators); // Удалить '('
            } else {
                // Обработка операторов с учётом приоритета и ассоциативности
                while (
                    !empty($operators) &&
                    end($operators)->getType() === 'operator' &&
                    (
                        $this->getPrecedence(end($operators)->getValue()) > $this->getPrecedence($token->getValue()) ||
                        (
                            $this->getPrecedence(end($operators)->getValue()) === $this->getPrecedence($token->getValue()) &&
                            $this->operators[end($operators)->getValue()]['associativity'] === 'left'
                        )
                    )
                ) {
                    $output[] = array_pop($operators);
                }
                $operators[] = $token;
            }
        }

        while (!empty($operators)) {
            $output[] = array_pop($operators);
        }

        return $output;
    }

    private function evaluateRPN(array $rpn)
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
                    $this->variables[$variableName] = $result;
                }

                // Добавляем результат обратно в стек
                $stack[] = new Token('result', $result);
            }
        }

        // Убедимся, что в стеке осталось ровно одно значение (результат вычисления)
        /*
        if (count($stack) !== 1) {
            throw new LogicException("Invalid RPN evaluation. Stack state: " . var_export($stack, true));
        }*/

        if (count($stack) > 0) {
            return array_pop($stack)->getValue();
        } else {
            return null;
        }
    }

    private function &resolveValue(Token $token): ?AbstractVariableHandler
    {
        $null = null;

        if ($token->getType() == 'identifier') {
            // Разделяем ключи на уровне по точке
            $keys = explode('.', $token->getValue());

            $varHandler = $this->variables[$keys[0]];

            // Перебираем вложенные ключи
            for ($i = 1; $i < count($keys); $i++) {
                $key = $keys[$i];

                if (!empty($varHandler->has($key))) {
                    $currentValue = &$varHandler->get($key);
                    $varHandler = VariableHandlerFactory::createHandler($currentValue, $key, $varHandler);
                } else {
                    // TODO: warning: identifier not found
                    return $null;
                }
            }
        } else {
            $varHandler = VariableHandlerFactory::createHandlerByTokenValue($token, $token->getValue(), $token->getValue());
        }

        if ($token->getType() == 'identifier' && $varHandler instanceof VarTypes\SimpleVarHandler) //&& !in_array($varHandler->getType(),['number', 'string'])
        {
            $this->usedVariables[$token->getValue()] = $varHandler->get();
        }

        return $varHandler;
    }

}