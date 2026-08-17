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
     * Парсит аргументы функции как выражения.
     *
     * Разделитель — ',' на нулевой глубине: запятая внутри строки, вложенного
     * массива или JSON-объекта аргументы не разделяет, поэтому
     * 'iif(a, [{"x":1,"y":2}], 0)' — это три аргумента, а не четыре.
     */
    private function parseArgumentExpressions(string $argsString): array
    {
        if (trim($argsString) === '') {
            return [];
        }

        $args = [];

        foreach (LiteralScanner::splitTopLevel($argsString, ',') as $rawArg) {
            $arg = trim($rawArg);
            if ($arg !== '') {
                $args[] = $arg; // Сохраняем как строку выражения
            }
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
     * Выполняет каждый параметр как мини-выражение.
     *
     * Разбор и приведение к обработчику значения целиком делегированы
     * интерпретатору — он одинаково разрешает переменные, вложенные вызовы,
     * литералы массивов и switch.
     */
    public function evaluateParameters(ExpressionInterpreter $interpreter): array
    {
        $evaluatedParams = [];

        foreach ($this->parameters as $parameter) {
            $evaluatedParams[] = $interpreter->evaluateExpressionToHandler($parameter);
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