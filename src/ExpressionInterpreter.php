<?php

namespace iustato\Bql;

use InvalidArgumentException;
use LogicException;
use iustato\Bql\VarTypes\AbstractVariableHandler;

class ExpressionInterpreter
{
    public VariableStorage $variableStorage;
    private array $operators = [];

    public function __construct()
    {
        $this->variableStorage = new VariableStorage();

        // Регистрация операторов
        $this->registerOperator('=', 1, 'left', true, 2);
        $this->registerOperator('&&', 3, 'right');
        $this->registerOperator('AND', 3, 'right');
        $this->registerOperator('||', 2);
        $this->registerOperator('OR', 2);
        $this->registerOperator('!', 4, 'right', false, 1);
        $this->registerOperator('<', 3);
        $this->registerOperator('>', 3);
        $this->registerOperator('<=', 3);
        $this->registerOperator('>=', 3);
        $this->registerOperator('==', 3);
        $this->registerOperator('!=', 3);
        $this->registerOperator('??', 3);
        $this->registerOperator('in', 3);
        $this->registerOperator('like', 3);

        $this->registerOperator('+', 3, 'left', false, 2);
        $this->registerOperator('.', 3, 'left', false, 2);
        $this->registerOperator('-', 3, 'left', false, 2);
        $this->registerOperator('*', 4, 'left', false, 2);
        $this->registerOperator('/', 4, 'left', false, 2);
        $this->registerOperator('++', 5, 'right', true, 1);
        $this->registerOperator('--', 5, 'right', true, 1);
        $this->registerOperator('+=', 3, 'left', true, 2);
        $this->registerOperator('-=', 3, 'left', true, 2);

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

    /* @return Token[][] */
    private function tokenizeWithAutomaton(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $currentToken = '';
        $state = 'default';
        $commands = [];
        $nestedLevel = 0; // Для отслеживания вложенности массивов

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            // Обработка строк отдельно
            if ($state == 'string') {
                if ($char === "'") {
                    $tokens[] = new Token('string', $currentToken);
                    $currentToken = '';
                    $state = 'default';
                } else {
                    $currentToken .= $char;
                }
                continue;
            }

            // Обработка параметров методов отдельно
            if ($state == 'method_params') {
                $currentToken .= $char;
                if ($char === ')') {
                    $tokens[] = new Token('method_call', $currentToken);
                    $currentToken = '';
                    $state = 'default';
                }
                continue;
            }

            // Обработка конца команды
            if ($char === ';') {
                if ($currentToken !== '') {
                    $this->finalizeCurrentToken($tokens, $currentToken, $state);
                    $currentToken = '';
                    $state = 'default';
                }

                if (!empty($tokens)) {
                    $commands[] = $tokens;
                    $tokens = [];
                }
                continue;
            }

            // Обработка пробелов (кроме состояния array)
            if ($state != 'array' && ctype_space($char)) {
                if ($currentToken !== '') {
                    $this->finalizeCurrentToken($tokens, $currentToken, $state);
                    $currentToken = '';
                    $state = 'default';
                }
                continue;
            }

        // Обработка скобок (кроме состояния array)
        if ($state != 'array' && ($char === '(' || $char === ')')) {
            if ($currentToken !== '') {
                // Специальная обработка для вызовов методов
                if ($state == 'sausage' && $char === '(' && preg_match('/\.(toString|toNum)$/', $currentToken)) {
                    $currentToken .= $char;
                    $state = 'method_params';
                    continue;
                } else {
                    $this->finalizeCurrentToken($tokens, $currentToken, $state);
                    $currentToken = '';
                    $state = 'default';
                }
            }
            // Добавляем скобку как отдельный токен parenthesis
            $tokens[] = new Token('parenthesis', $char);
            continue;
        }

            switch ($state) {
                case 'default':
                    if ($char === "'") {
                        $state = 'string';
                    } elseif ($char === '[') {
                        $currentToken = $char;
                        $state = 'array';
                        $nestedLevel = 1;
                    } elseif (ctype_digit($char)) {
                        $currentToken = $char;
                        $state = 'number';
                    } elseif (ctype_alpha($char) || $char === '_') {
                        $currentToken = $char;
                        $state = 'identifier';
                    } elseif (!ctype_space($char)) {
                        $currentToken = $char;
                        $state = 'operator';
                    }
                    break;

                case 'number':
                    if (ctype_digit($char) || $char === '.') {
                        $currentToken .= $char;
                    } else {
                        $tokens[] = new Token('number', $currentToken);
                        $currentToken = '';
                        $state = 'default';
                        $i--; // Пересмотреть текущий символ
                    }
                    break;

                case 'array':
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

                case 'identifier':
                    if (ctype_alnum($char) || $char === '_') {
                        $currentToken .= $char;
                    } elseif ($char === '.') {
                        $currentToken .= $char;
                        $state = 'sausage';
                    } else {
                        $tokens[] = new Token('identifier', $currentToken);
                        $currentToken = '';
                        $state = 'default';
                        $i--; // Пересмотреть текущий символ
                    }
                    break;

                case 'sausage':
                    if (ctype_alnum($char) || $char === '_' || $char === '.') {
                        $currentToken .= $char;
                    } else {
                        // Проверяем, не является ли это вызовом метода
                        if ($char === '(' && preg_match('/\.(toString|toNum)$/', $currentToken)) {
                            $currentToken .= $char;
                            $state = 'method_params';
                        } else {
                            $tokens[] = new Token('sausage', $currentToken);
                            $currentToken = '';
                            $state = 'default';
                            $i--; // Пересмотреть текущий символ
                        }
                    }
                    break;

                case 'operator':
                    if (!ctype_alnum($char) && $char !== '_' && !ctype_space($char) && $char !== '(' && $char !== ')') {
                        $currentToken .= $char;
                    } else {
                        $tokens[] = new Token('operator', strtolower($currentToken));
                        $currentToken = '';
                        $state = 'default';
                        $i--; // Пересмотреть текущий символ
                    }
                    break;
        }
    }

    // Завершаем последний токен
    if ($currentToken !== '') {
        $this->finalizeCurrentToken($tokens, $currentToken, $state);
    }

    // Добавляем последнюю команду
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
            return array_pop($stack)->getValue();
        } else {
            return null;
        }
    }

    private function &resolveValue(Token $token): ?AbstractVariableHandler
    {
        $null = null;

        if ($token->getType() == 'variable') {
            $varr = $token->getValue();
            return $varr;
        }

        if ($token->getType() == 'method_call') {
            // Обработка вызовов методов .toString() и .toNum()
            $methodCall = $token->getValue();
            
            // Извлекаем переменную и метод
            if (preg_match('/^(.+)\.(toString|toNum)\(\)$/', $methodCall, $matches)) {
                $variableName = $matches[1];
                $methodName = $matches[2];
                
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
                        $result = $baseHandler->toString()->get();
                        return $result;
                    } elseif ($methodName === 'toNum') {
                        $result = $baseHandler->toNum()->get();
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
            default:
                if ($currentToken !== '') {
                    $tokens[] = new Token($state, $currentToken);
                }
                break;
        }
    }
}