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
        
        $this->variables[$name]->set($name, $value, true);

        if ($value instanceof AbstractVariableHandler) {
            $this->modifiedVariables[$name] = $value->get();
        } else {
            $this->modifiedVariables[$name] = $value;
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
    }

    public function markUsed(string $name, $value): void {
        $this->usedVariables[] = $name;
    }

    public function getModifiedVariables(): array {
        return $this->modifiedVariables;
    }

    public function getUsedVariables(): array {
        $resp_arr = [];
        if (is_array($this->usedVariables))
        {
            foreach ($this->usedVariables  as $key) {
                if (isset($this->variables[$key]))
                {
                    $resp_arr[$key] = $this->variables[$key]->get();
                }
            }
        }
        return $resp_arr;
    }

    public function getAllVariables(): array {
        return $this->variables;
    }
}