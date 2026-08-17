<?php

namespace iustato\Bql;

use InvalidArgumentException;
use iustato\Bql\VarTypes\AbstractVariableHandler;

/**
 * Конструкция switch — выражение, возвращающее значение.
 *
 *   risk = switch {
 *       case amount >= 10000: 'high';
 *       case amount >= 1000:  'medium';
 *       case amount > 0:      'low';
 *       default:              'none';
 *   };
 *
 * Условия проверяются сверху вниз; вычисляется тело только того плеча, которое
 * подошло первым. Плечо 'default' может стоять в любом месте — оно используется
 * лишь если не подошло ни одно условие. Совпадений нет и 'default' не задан —
 * результат null.
 *
 * Тело плеча — обычное выражение BQL, поэтому там допустимы и литералы массивов,
 * и вложенный switch.
 */
class SwitchToken extends Token
{
    /**
     * Плечи в порядке объявления. Ключ 'condition' равен null у плеча 'default'.
     *
     * @var array<int, array{condition: ?string, body: string}>
     */
    private array $arms;

    public function __construct(string $switchBody)
    {
        $this->arms = self::parseArms($switchBody);
        parent::__construct('switch', trim($switchBody));
    }

    /**
     * Разбирает тело '{...}' на плечи.
     *
     * @return array<int, array{condition: ?string, body: string}>
     */
    private static function parseArms(string $switchBody): array
    {
        $trimmed = trim($switchBody);

        if (!preg_match('/^\{(.*)\}$/s', $trimmed, $match)) {
            throw new InvalidArgumentException("Invalid switch block: '$trimmed'");
        }

        $arms = [];
        $hasDefault = false;

        foreach (LiteralScanner::splitTopLevel($match[1], ';') as $rawArm) {
            $arm = trim($rawArm);

            if ($arm === '') {
                continue; // Пустое место между ';' или хвостовая ';' после последнего плеча.
            }

            // Разделитель головы и тела — первое ':' на нулевой глубине. Двоеточие
            // однозначно, потому что тернарного оператора '?:' в языке нет.
            $parts = LiteralScanner::splitTopLevel($arm, ':', 2);

            if (count($parts) < 2) {
                throw new InvalidArgumentException(
                    "Switch arm must be 'case <condition>: <result>' or 'default: <result>', got '$arm'"
                );
            }

            $head = trim($parts[0]);
            $body = trim($parts[1]);

            if ($body === '') {
                throw new InvalidArgumentException("Switch arm '$head' has no result expression");
            }

            if (strcasecmp($head, 'default') === 0) {
                if ($hasDefault) {
                    throw new InvalidArgumentException("Switch block has more than one 'default' arm");
                }
                $hasDefault = true;
                $arms[] = ['condition' => null, 'body' => $body];
                continue;
            }

            if (!preg_match('/^case\s+(.+)$/is', $head, $caseMatch)) {
                throw new InvalidArgumentException(
                    "Switch arm must start with 'case' or 'default', got '$head'"
                );
            }

            $arms[] = ['condition' => trim($caseMatch[1]), 'body' => $body];
        }

        if (empty($arms)) {
            throw new InvalidArgumentException('Switch block has no arms');
        }

        return $arms;
    }

    /**
     * Вычисляет switch: первое истинное условие определяет результат.
     *
     * Вычисление ленивое — тела остальных плеч не считаются вовсе, поэтому плечо
     * с побочным эффектом ('case a: b += 1') сработает только при попадании.
     */
    public function evaluate(ExpressionInterpreter $interpreter): ?AbstractVariableHandler
    {
        $defaultBody = null;

        foreach ($this->arms as $arm) {
            if ($arm['condition'] === null) {
                $defaultBody = $arm['body'];
                continue;
            }

            if (self::isTruthy($interpreter->evaluateExpressionToHandler($arm['condition']))) {
                return $interpreter->evaluateExpressionToHandler($arm['body']);
            }
        }

        return $defaultBody === null ? null : $interpreter->evaluateExpressionToHandler($defaultBody);
    }

    /** Приводит результат условия к bool так же, как это делает функция iif(). */
    private static function isTruthy(?AbstractVariableHandler $handler): bool
    {
        if ($handler === null) {
            return false;
        }

        $value = $handler->get();

        if (is_array($value)) {
            return $value !== [];
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<int, array{condition: ?string, body: string}> */
    public function getArms(): array
    {
        return $this->arms;
    }

    public function __toString(): string
    {
        return json_encode([
            'type' => 'switch',
            'arms' => $this->arms,
        ]);
    }
}
