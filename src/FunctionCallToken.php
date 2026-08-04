<?php

namespace iustato\Bql;

class FunctionCallToken extends Token
{
    private string $functionName;
    private array $parameters;

    public function __construct(string $functionCallString)
    {
        // Парсим строку вызова функции
        $this->parseFunctionCall($functionCallString);
        parent::__construct('function_call', $this->functionName);
    }

    /**
     * Парсит строку вызова функции и извлекает название и параметры
     */
    private function parseFunctionCall(string $functionCallString): void
    {
        // Парсим вызов функции: functionName(arg1, arg2, ...)
        if (!preg_match('/^(\w+)\((.*)\)$/', $functionCallString, $matches)) {
            throw new \InvalidArgumentException("Invalid function call: $functionCallString");
        }

        $this->functionName = strtolower($matches[1]);
        $argsString = $matches[2];

        // Парсим параметры
        $this->parameters = $this->parseArgumentExpressions($argsString);
    }

    /**
     * Парсит аргументы функции как выражения
     */
    private function parseArgumentExpressions(string $argsString): array
    {
        if (trim($argsString) === '') {
            return [];
        }

        $args = [];
        $currentArg = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < strlen($argsString); $i++) {
            $char = $argsString[$i];

            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $currentArg .= $char;
            } elseif ($inString && $char === $stringChar) {
                $inString = false;
                $currentArg .= $char;
            } elseif (!$inString && $char === '(') {
                $depth++;
                $currentArg .= $char;
            } elseif (!$inString && $char === ')') {
                $depth--;
                $currentArg .= $char;
            } elseif (!$inString && $char === ',' && $depth === 0) {
                // Найден разделитель аргументов на верхнем уровне
                $trimmedArg = trim($currentArg);
                if ($trimmedArg !== '') {
                    $args[] = $trimmedArg; // Сохраняем как строку выражения
                }
                $currentArg = '';
            } else {
                $currentArg .= $char;
            }
        }

        // Добавляем последний аргумент
        $trimmedArg = trim($currentArg);
        if ($trimmedArg !== '') {
            $args[] = $trimmedArg; // Сохраняем как строку выражения
        }

        return $args;
    }

    public function getFunctionName(): string
    {
        return $this->functionName;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameterCount(): int
    {
        return count($this->parameters);
    }

    /**
     * Выполняет каждый параметр как мини-выражение
     */
    public function evaluateParameters(ExpressionInterpreter $interpreter): array
    {
        $evaluatedParams = [];
        $i = 0;

        foreach ($this->parameters as $parameter) {
            // Каждый параметр - строка выражения, вычисляем её рекурсивно
            $argTokens = $interpreter->tokenizeWithAutomaton($parameter);
            
            if (!empty($argTokens[0])) {
                $argRPN = $interpreter->toReversePolishNotation($argTokens[0]);
                $argResult = $interpreter->evaluateRPN($argRPN);
                
                // Преобразуем результат в AbstractVariableHandler если нужно
                if ($argResult instanceof \iustato\Bql\VarTypes\AbstractVariableHandler) {
                    $evaluatedParams[] = $argResult;
                } else {
                    // Создаем временный handler для результата
                    $tempVars[$i] = $argResult;


                    $evaluatedParams[] = \iustato\Bql\VariableHandlerFactory::createHandler(
                        $tempVars[$i],
                        'temp_arg'.$i,
                        null,
                        $interpreter->variableStorage
                    );

                }
            } else {
                $evaluatedParams[] = null;
            }

            $i++;
        }
        
        return $evaluatedParams;
    }

    public function __toString(): string
    {
        return json_encode([
            'type' => 'function_call',
            'function' => $this->functionName,
            'parameters' => $this->parameters
        ]);
    }
}