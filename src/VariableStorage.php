<?php
namespace iustato\Bql;

use iustato\Bql\VarTypes\AbstractVariableHandler;
use iustato\Bql\VarTypes\BoolVarHandler;
use iustato\Bql\VarTypes\SimpleVarHandler;

class VariableStorage {
    /* @var AbstractVariableHandler[] */
    private array $variables = [];
    private int $anonymousCounter = 0;
    private array $modifiedVariables = [];
    private array $usedVariables = [];

    public function __construct() {
        // Инициализация базовых переменных
        $true = true; $false = false; $null = null;
        $this->variables['true'] = new BoolVarHandler('true', $true, null, $this);
        $this->variables['false'] = new BoolVarHandler('false', $false, null, $this);
        $this->variables['null'] = new SimpleVarHandler('null', $null, null, $this);
    }

    public function addVariable(string $name, &$value): void {
        $this->variables[$name] = VariableHandlerFactory::createHandler($value, $name, null, $this);
    }

    public function modifyVariable(string $name, $value): void {
        if (!isset($this->variables[$name])) {
            return; // Переменная не найдена
        }
        
        // Пустой ключ — замена значения переменной целиком. Раньше здесь передавалось
        // само имя, и обработчики понимали его как ключ внутри значения: присваивание
        // массива уходило в $arr['arr'], а для скалярной переменной терялось совсем.
        $this->variables[$name]->set('', $value, true);

        $this->refreshHandler($name);

        if ($value instanceof AbstractVariableHandler) {
            $this->modifiedVariables[$name] = $value->get();
        } else {
            $this->modifiedVariables[$name] = $value;
        }
    }

    /**
     * Пересоздаёт обработчик, если присваивание сменило тип значения.
     *
     * Обработчик выбирается по типу в момент setVariables(), но выражение может
     * заменить тип: 'cfg = [{"a":1}]' превращает null в массив. Без пересоздания
     * у переменной остался бы SimpleVarHandler, и обращение 'cfg.a' ничего бы не
     * нашло. Ссылка на переменную хост-проекта при этом сохраняется.
     */
    private function refreshHandler(string $name): void {
        $handler = $this->variables[$name];
        $current = &$handler->get();

        if ($handler::supports($current)) {
            return;
        }

        $refreshed = VariableHandlerFactory::createHandler($current, $name, null, $this);

        if ($refreshed) {
            $this->variables[$name] = $refreshed;
        }
    }

    public function setVariables(array $variables): void {
        foreach ($variables as $key => &$value) {
            $this->addVariable($key, $value);
        }
        $this->modifiedVariables = [];
    }

    public function getVariable(string $name): ?AbstractVariableHandler {
        return $this->variables[$name] ?? null;
    }

    public function addAnonymousVariableHandler(AbstractVariableHandler $handler): string {
        $name = 'anonymous_' . (++$this->anonymousCounter);
        $this->variables[$name] = $handler;
        return $name;
    }

    public function markModified(string $name, $value): void {
        $this->modifiedVariables[$name] = $value;
        $this->markUsed($name, $value);
    }

    public function markUsed(string $name, $value = null): void {
        // Если значение не передано, получаем его из переменной
        if ($value === null && isset($this->variables[$name])) {
            $value = $this->variables[$name]->get();
        }
        
        // Сохраняем имя переменной и её значение в момент использования
        $this->usedVariables[$name] = $value;
    }

    public function getModifiedVariables(): array {
        return $this->modifiedVariables;
    }

    public function getUsedVariables(): array {
        return $this->usedVariables;
    }

    public function getAllVariables(): array {
        return $this->variables;
    }
}