<?php
namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;

abstract class AbstractVariableHandler
{
    protected string $name;
    protected ?AbstractVariableHandler $parent;
    protected string $type;
    protected ?VariableStorage $storage;

    public function __construct(string $name, &$var, ?AbstractVariableHandler $parent = null, ?VariableStorage $storage = null)
    {
        $this->name = $name;
        $this->parent = $parent;
        $this->storage = $storage;
    }

    protected function registerAnonymous(AbstractVariableHandler $handler): string {
        return $this->storage ? $this->storage->addAnonymousVariableHandler($handler) : 'anonymous_' . rand(0, 9999);
    }

    /**
     * Проверяет, поддерживает ли обработчик данный тип переменной.
     */
    abstract public static function supports($variable): bool;

    /**
     * Получение значения по ключу.
     */
    abstract public function &get(string $key = '');

    /**
     * Сохранение значения по ключу.
     *
     * Пустой ключ означает «заменить значение целиком» — так же, как в get().
     * Именно так приходит присваивание всей переменной из VariableStorage.
     */
    abstract public function set(string $key, &$value, bool $setCurrent = false): void;

    /**
     * Проверка существования ключа.
     */
    abstract public function has(string $key): string;

    /**
     * Обработка бинарных операторов.
     */
    abstract public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler;

    /**
     * Обработка унарных операторов.
     */
    abstract public function operatorUnaryCall(string $operator): ?AbstractVariableHandler;

    abstract public function toString() : ?StringVarHandler;

    abstract public function toNum() : ?NumVarHandler;

    /**
     * JSON-представление значения.
     *
     * Реализация по умолчанию годится для всех типов, потому что json_encode()
     * одинаково справляется и со скаляром, и с (вложенным) массивом. Переопределяй
     * только там, где нужно другое представление.
     */
    public function toJSON() : ?StringVarHandler
    {
        $value = json_encode($this->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new StringVarHandler('temp', $value, null, $this->storage);
    }

    abstract public function convertToMe (AbstractVariableHandler $var);

    public function getType()
    {
        if (isset($this->type))
            return $this->type;
        else
            return '';
    }
}