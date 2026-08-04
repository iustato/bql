<?php

namespace iustato\Bql;

/**
 * Изменяемое состояние автомата-токенизатора {@see ExpressionInterpreter::tokenizeWithAutomaton()}.
 *
 * Вынесено в отдельный объект, чтобы обработчики отдельных состояний могли
 * читать/менять общее состояние без длинного списка параметров, передаваемых по ссылке.
 */
class TokenizerState
{
    /** @var Token[][] Завершённые команды (разделённые ';') */
    public array $commands = [];

    /** @var Token[] Токены текущей команды */
    public array $tokens = [];

    /** Накапливаемый текст текущего токена */
    public string $buffer = '';

    /** Имя текущего состояния автомата */
    public string $name = 'default';

    /** Глубина вложенности '[]' / '()' для литералов массивов и вызовов функций */
    public int $depth = 0;

    /** Нужно ли повторно рассмотреть текущий символ в состоянии 'default' */
    public bool $reconsider = false;
}
