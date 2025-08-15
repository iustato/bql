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
            return $this->storage->getVariable('null');
        }

        // Проходим по всем ключам, кроме первого (корневого)
        for ($i = 1; $i < count($this->keys); $i++) {
            $currentKey = $this->keys[$i];
            
            if (!$currentHandler || !$currentHandler->has($currentKey)) {
                return $this->storage->getVariable('null');
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
        $handler = $this->resolvePath();
        
        if (!$handler) {
            $null = null;
            return $null;
        }

        // Отмечаем переменную как использованную при чтении
        if ($this->storage) {
            $actualValue = $handler->get($key);
            $actualValue = ($actualValue instanceof AbstractVariableHandler) ? $actualValue->get() : $actualValue;
            $this->storage->markUsed($this->originalIdentifier, $actualValue);
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

        // Если у нас только один уровень вложенности (например, Order.Test), работаем напрямую
        if (count($this->keys) === 2) {
            $targetKey = $this->keys[1];
            
            if (empty($key) || $setCurrent) {
                // Записываем значение напрямую в свойство корневого объекта
                if (is_scalar($value) || is_null($value)) {
                    $scalarValue = $value;
                    $currentHandler->set($targetKey, $scalarValue);
                } else {
                    $currentHandler->set($targetKey, $value);
                }
            } else {
                // Если указан дополнительный ключ, получаем целевой объект и записываем в него
                $targetValue = &$currentHandler->get($targetKey);
                $targetHandler = VariableHandlerFactory::createHandler(
                    $targetValue,
                    $targetKey,
                    $currentHandler,
                    $this->storage
                );
                
                if ($targetHandler) {
                    if (is_scalar($value) || is_null($value)) {
                        $scalarValue = $value;
                        $targetHandler->set($key, $scalarValue);
                    } else {
                        $targetHandler->set($key, $value);
                    }
                }
            }
            
            // Сбрасываем кэш разрешенного обработчика
            $this->resolvedHandler = null;
            
            // Отмечаем переменную как измененную через VariableStorage
            if ($this->storage) {
                $finalValue = is_scalar($value) ? $value : (($value instanceof AbstractVariableHandler) ? $value->get() : $value);
                $this->storage->markModified($this->originalIdentifier, $finalValue);
            }
            return;
        }

        // Для многоуровневой вложенности (Order.Customer.Age)
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
            // Записываем значение в конечное свойство
            $currentHandler->set($lastKey, $value, true);
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
                if (is_scalar($value) || is_null($value)) {
                    $scalarValue = $value;
                    $targetHandler->set($key, $scalarValue);
                } else {
                    $targetHandler->set($key, $value);
                }
            }
        }
        
        // Сбрасываем кэш разрешенного обработчика
        $this->resolvedHandler = null;
        
        // Отмечаем переменную как измененную через VariableStorage
        if ($this->storage) {
            $finalValue = is_scalar($value) ? $value : (($value instanceof AbstractVariableHandler) ? $value->get() : $value);
            $this->storage->markModified($this->originalIdentifier, $finalValue);
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
        if (in_array($operator, ['=', '+=', '-=', '*=', '/=', '%=']) && $result && $varB) {
            $newValue = $result->get();
            

            $this->set('', $newValue, true);

            
            // Сбрасываем кэш разрешенного обработчика  
            $this->resolvedHandler = null;
            
            // Отмечаем как измененную
            if ($this->storage) {
                $this->storage->markModified($this->originalIdentifier, $newValue);
            }
        }

        return $result;
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler {
        $handler = $this->resolvePath();
        
        if (!$handler) {
            return null;
        }

        // Для унарных операторов, изменяющих значение (++, --),
        // нужно специально обработать запись обратно в объект
        if (in_array($operator, ['++', '--'])) {
            // Выполняем операцию над конечным значением
            $result = $handler->operatorUnaryCall($operator);
            
            if ($result) {
                $newValue = $result->get();
                
                // Отладочная информация
                error_log("SausageVarHandler: operatorUnaryCall($operator) on {$this->originalIdentifier}");
                error_log("Old value: " . var_export($handler->get(), true));
                error_log("New value: " . var_export($newValue, true));
                
                // Записываем новое значение обратно по полному пути
                // Важно: записываем именно в последний элемент пути
                $this->writeValueToPath($newValue);
                
                // Сбрасываем кэш разрешенного обработчика
                $this->resolvedHandler = null;
                
                // Отмечаем как измененную
                if ($this->storage) {
                    $this->storage->markModified($this->originalIdentifier, $newValue);
                }
            }
        
            return $result;
        }
        
        // Для других унарных операторов
        return $handler->operatorUnaryCall($operator);
    }

    /**
     * Записывает значение в конкретное место по пути
     */
    private function writeValueToPath($value): void {
        // Получаем корневую переменную
        $currentHandler = $this->storage->getVariable($this->rootVariableName);
        
        if (!$currentHandler) {
            return;
        }

        error_log("writeValueToPath: writing $value to " . implode('.', $this->keys));

        // Если у нас только 2 ключа (например, class.counter), записываем напрямую в корневой объект
        if (count($this->keys) === 2) {
            $targetKey = $this->keys[1];
            error_log("writeValueToPath: writing to root object property $targetKey");
            $currentHandler->set($targetKey, $value, true);
            return;
        }

        // Для многоуровневой вложенности проходим до предпоследнего элемента
        for ($i = 1; $i < count($this->keys) - 1; $i++) {
            $currentKey = $this->keys[$i];
            
            if (!$currentHandler->has($currentKey)) {
                return; // Путь не существует
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

        // Записываем значение в последний элемент пути
        $lastKey = $this->keys[count($this->keys) - 1];
        error_log("writeValueToPath: writing to final property $lastKey");
        $currentHandler->set($lastKey, $value, true);
    }


    public function toString() : ?StringVarHandler {
        $handler = $this->resolvePath();
        return $handler ? $handler->toString() : null;
    }

    public function toNum() : ?NumVarHandler{
        $handler = $this->resolvePath();
        return $handler ? $handler->toNum() : null;
    }

    public function convertToMe(AbstractVariableHandler $var) {
        $handler = $this->resolvePath();
        return $handler ? $handler->convertToMe($var) : $var;
    }
}