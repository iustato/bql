<?php

namespace iustato\Bql\VarTypes;

use iustato\Bql\VariableStorage;
use iustato\Bql\VariableHandlerFactory;
use InvalidArgumentException;

class SausageVarHandler extends AbstractVariableHandler
{
    private array $keys;
    private string $originalIdentifier;
    private string $rootVariableName;
    private ?AbstractVariableHandler $resolvedHandler = null;

    public function __construct(string $name, &$var, ?AbstractVariableHandler $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        $this->type = 'sausage';
        $this->originalIdentifier = $name;
        
        // Разбираем вложенную структуру через точку
        $this->keys = explode('.', $this->originalIdentifier);
        
        if (count($this->keys) <= 1) {
            throw new InvalidArgumentException("SausageVarHandler requires nested identifier with dots");
        }
        
        // Корневое имя переменной - первый элемент до точки
        $this->rootVariableName = $this->keys[0];
    }

    public static function supports($variable): bool {
        // SausageVarHandler создается только для токенов типа 'sausage'
        return false;
    }

    /**
     * Создает SausageVarHandler для вложенных переменных через точку
     */
    public static function createForNestedVariable(string $identifier, VariableStorage $storage): ?SausageVarHandler {
        $keys = explode('.', $identifier);
        
        if (count($keys) <= 1) {
            return null; // Не вложенная переменная
        }

        $rootHandler = $storage->getVariable($keys[0]);
        
        if (!$rootHandler) {
            return null; // Корневая переменная не найдена
        }

        // Создаем dummy переменную для конструктора
        $dummyVar = null;
        return new self($identifier, $dummyVar, null, $storage);
    }

    /**
     * Разрешает путь через вложенные свойства/ключи
     */
    private function resolvePath(): ?AbstractVariableHandler {
        if ($this->resolvedHandler !== null) {
            return $this->resolvedHandler;
        }

        // Получаем корневую переменную из storage
        $currentHandler = $this->storage->getVariable($this->rootVariableName);
        
        if (!$currentHandler) {
            return null;
        }

        // Проходим по всем ключам, кроме первого (корневого)
        for ($i = 1; $i < count($this->keys); $i++) {
            $currentKey = $this->keys[$i];
            
            if (!$currentHandler || !$currentHandler->has($currentKey)) {
                return null;
            }

            $currentValue = &$currentHandler->get($currentKey);
            
            // Если это последний ключ, сохраняем обработчик для конечного значения
            if ($i === count($this->keys) - 1) {
                $this->resolvedHandler = VariableHandlerFactory::createHandler(
                    $currentValue,
                    $currentKey,
                    $currentHandler,
                    $this->storage
                );
                return $this->resolvedHandler;
            }
            
            // Иначе создаем новый обработчик для следующего уровня
            $currentHandler = VariableHandlerFactory::createHandler(
                $currentValue,
                $currentKey,
                $currentHandler,
                $this->storage
            );
            
            if (!$currentHandler) {
                return null;
            }
        }

        return null;
    }

    public function &get(string $key = '') {
        $null = null;
        $handler = $this->resolvePath();
        
        if (!$handler) {
            return $null;
        }

        if (empty($key)) {
            return $handler->get();
        }
        
        return $handler->get($key);
    }

    public function set(string $key, &$value, bool $setCurrent = false): void {
        // Получаем корневую переменную
        $currentHandler = $this->storage->getVariable($this->rootVariableName);
        
        if (!$currentHandler) {
            return;
        }

        // Проходим до предпоследнего элемента
        for ($i = 1; $i < count($this->keys) - 1; $i++) {
            $currentKey = $this->keys[$i];
            
            if (!$currentHandler->has($currentKey)) {
                // Создаем промежуточный элемент, если его нет
                $emptyArray = [];
                $currentHandler->set($currentKey, $emptyArray);
            }

            $currentValue = &$currentHandler->get($currentKey);
            $currentHandler = VariableHandlerFactory::createHandler(
                $currentValue,
                $currentKey,
                $currentHandler,
                $this->storage
            );
            
            if (!$currentHandler) {
                return;
            }
        }

        // Устанавливаем значение для последнего ключа
        $lastKey = end($this->keys);
        
        if ($setCurrent || empty($key)) {
            $currentHandler->set($lastKey, $value);
        } else {
            // Если указан дополнительный ключ
            $targetValue = &$currentHandler->get($lastKey);
            $targetHandler = VariableHandlerFactory::createHandler(
                $targetValue,
                $lastKey,
                $currentHandler,
                $this->storage
            );
            
            if ($targetHandler) {
                $targetHandler->set($key, $value);
            }
        }
        
        // Сбрасываем кэш разрешенного обработчика
        $this->resolvedHandler = null;
        
        // Отмечаем корневую переменную как измененную через VariableStorage
        if ($this->storage) {
            $actualValue = ($value instanceof AbstractVariableHandler) ? $value->get() : $value;
            $this->storage->markModified($this->originalIdentifier, $actualValue);
        }
    }

    public function has(string $key): string {
        $handler = $this->resolvePath();
        
        if (!$handler) {
            return '';
        }

        if (empty($key)) {
            return 'exists'; // Сам элемент существует
        }

        return $handler->has($key);
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler {
        $handler = $this->resolvePath();
        
        if (!$handler) {
            return null;
        }

        $result = $handler->operatorCall($operator, $varB);
        
        // Если оператор изменяет значение (например, =, +=, -=)
        if (in_array($operator, ['=', '+=', '-=', '*=', '/=', '%='])) {
            if ($result && $varB) {
                $newValue = $varB->get();
                $this->set('', $newValue, true);
            }
        }

        return $result;
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler {
        $handler = $this->resolvePath();
        
        if (!$handler) {
            return null;
        }

        $result = $handler->operatorUnaryCall($operator);
        
        // Если оператор изменяет значение (например, ++, --)
        if (in_array($operator, ['++', '--'])) {
            if ($result) {
                $newValue = $result->get();
                $this->set('', $newValue, true);
            }
        }

        return $result;
    }

    public function toString() {
        $handler = $this->resolvePath();
        return $handler ? $handler->toString() : '';
    }

    public function toNum() {
        $handler = $this->resolvePath();
        return $handler ? $handler->toNum() : 0;
    }

    public function convertToMe(AbstractVariableHandler $var) {
        $handler = $this->resolvePath();
        return $handler ? $handler->convertToMe($var) : $var;
    }
}