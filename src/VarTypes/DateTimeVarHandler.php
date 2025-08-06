<?php

namespace iustato\Bql\VarTypes;

use DateTime;
use iustato\Bql\VariableStorage;

class DateTimeVarHandler extends AbstractVariableHandler
{
    private DateTime $datetime;

    public function __construct(string $name, &$var, ?AbstractVariableHandler $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        
        if ($var instanceof DateTime) {
            $this->datetime = $var;
        } elseif (is_string($var)) {
            $this->datetime = new DateTime($var);
        } elseif (is_numeric($var)) {
            $this->datetime = new DateTime();
            $this->datetime->setTimestamp($var);
        } else {
            $this->datetime = new DateTime();
        }
        
        $this->type = 'datetime';
    }

    public static function supports($variable): bool
    {
        return $variable instanceof DateTime || 
               (is_string($variable) && strtotime($variable) !== false);
    }

    public function &get(string $key = '')
    {
        if (empty($key)) {
            return $this->datetime;
        }

        // Поддерживаем различные форматы ключей для получения компонентов даты
        $key = strtolower($key);
        
        switch ($key) {
            case 'year':
                $value = (int)$this->datetime->format('Y');
                break;
            case 'month':
                $value = (int)$this->datetime->format('m');
                break;
            case 'day':
                $value = (int)$this->datetime->format('d');
                break;
            case 'hour':
                $value = (int)$this->datetime->format('H');
                break;
            case 'minute':
                $value = (int)$this->datetime->format('i');
                break;
            case 'second':
                $value = (int)$this->datetime->format('s');
                break;
            case 'timestamp':
                $value = $this->datetime->getTimestamp();
                break;
            case 'weekday':
                $value = (int)$this->datetime->format('w'); // 0 (Sunday) to 6 (Saturday)
                break;
            case 'dayofyear':
                $value = (int)$this->datetime->format('z'); // 0 to 365
                break;
            case 'weekofyear':
                $value = (int)$this->datetime->format('W'); // ISO-8601 week number
                break;
            case 'quarter':
                $month = (int)$this->datetime->format('m');
                $value = (int)ceil($month / 3);
                break;
            case 'dayname':
                $value = $this->datetime->format('l'); // Full textual representation of the day of the week
                break;
            case 'monthname':
                $value = $this->datetime->format('F'); // Full textual representation of a month
                break;
            case 'iso':
                $value = $this->datetime->format('c'); // ISO 8601 date
                break;
            case 'date':
                $value = $this->datetime->format('Y-m-d'); // Date only
                break;
            case 'time':
                $value = $this->datetime->format('H:i:s'); // Time only
                break;
            default:
                $null = null;
                return $null;
        }
        
        return $value;
    }

    public function set(string $key, &$value, bool $setCurrent = false): void
    {
        if (empty($key) || $setCurrent) {
            // Устанавливаем всю дату
            if ($value instanceof DateTime) {
                $this->datetime = $value;
            } elseif (is_string($value)) {
                $this->datetime = new DateTime($value);
            } elseif (is_numeric($value)) {
                $this->datetime = new DateTime();
                $this->datetime->setTimestamp($value);
            }
        } else {
            // Устанавливаем компонент даты
            $key = strtolower($key);
            $clonedDate = clone $this->datetime;
            
            switch ($key) {
                case 'year':
                    $clonedDate->setDate($value, $clonedDate->format('m'), $clonedDate->format('d'));
                    break;
                case 'month':
                    $clonedDate->setDate($clonedDate->format('Y'), $value, $clonedDate->format('d'));
                    break;
                case 'day':
                    $clonedDate->setDate($clonedDate->format('Y'), $clonedDate->format('m'), $value);
                    break;
                case 'hour':
                    $clonedDate->setTime($value, $clonedDate->format('i'), $clonedDate->format('s'));
                    break;
                case 'minute':
                    $clonedDate->setTime($clonedDate->format('H'), $value, $clonedDate->format('s'));
                    break;
                case 'second':
                    $clonedDate->setTime($clonedDate->format('H'), $clonedDate->format('i'), $value);
                    break;
                case 'timestamp':
                    $clonedDate->setTimestamp($value);
                    break;
                default:
                    return; // Неподдерживаемый ключ
            }
            
            $this->datetime = $clonedDate;
        }

        // Если есть родительский объект, обновляем его
        if ($this->parent !== null) {
            $this->parent->set($this->name, $this->datetime, true);
        }
    }

    public function has(string $key): string
    {
        if (empty($key)) {
            return 'datetime';
        }
        
        $key = strtolower($key);
        $supportedKeys = [
            'year', 'month', 'day', 'hour', 'minute', 'second', 
            'timestamp', 'weekday', 'dayofyear', 'weekofyear', 
            'quarter', 'dayname', 'monthname', 'iso', 'date', 'time'
        ];
        
        return in_array($key, $supportedKeys) ? 'datetime' : '';
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch (strtolower($operator)) {
            case '=':
                return $varB;
            case '>':
            case '<':
            case '>=':
            case '<=':
            case '==':
            case '!=':
                if ($varB instanceof DateTimeVarHandler) {
                    $result = $this->compareDateTime($operator, $varB->datetime);
                } else {
                    try {
                        $otherDateTime = new DateTime($varB->toString());
                        $result = $this->compareDateTime($operator, $otherDateTime);
                    } catch (\Exception $e) {
                        $result = false;
                    }
                }
                
                $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $result, null, $this->storage));
                return new BoolVarHandler($anonymousName, $result, null, $this->storage);
            
        case '+':
            if ($varB instanceof StringVarHandler)
            {
                $varC = new DateTimeIntervalVarHandler('temp', $varB->get());
                $varB = $varC;
            }

            if ($varB instanceof DateTimeIntervalVarHandler) {
                // Добавление интервала к дате
                $newDate = clone $this->datetime;
                $newDate->add($varB->get());
                $anonymousName = $this->registerAnonymous(new DateTimeVarHandler('temp', $newDate, null, $this->storage));
                return new DateTimeVarHandler($anonymousName, $newDate, null, $this->storage);

            } elseif ($varB instanceof NumVarHandler) {
                // Добавление дней к дате (для обратной совместимости)
                $days = (int)$varB->toNum();
                $newDate = clone $this->datetime;
                $newDate->modify("+{$days} days");
                $anonymousName = $this->registerAnonymous(new DateTimeVarHandler('temp', $newDate, null, $this->storage));
                return new DateTimeVarHandler($anonymousName, $newDate, null, $this->storage);
            }
            break;
            
        case '-':
            if ($varB instanceof DateTimeIntervalVarHandler) {
                // Вычитание интервала из даты
                $newDate = clone $this->datetime;
                $newDate->sub($varB->get());
                $anonymousName = $this->registerAnonymous(new DateTimeVarHandler('temp', $newDate, null, $this->storage));
                return new DateTimeVarHandler($anonymousName, $newDate, null, $this->storage);
            } elseif ($varB instanceof NumVarHandler) {
                // Вычитание дней из даты (для обратной совместимости)
                $days = (int)$varB->toNum();
                $newDate = clone $this->datetime;
                $newDate->modify("-{$days} days");
                $anonymousName = $this->registerAnonymous(new DateTimeVarHandler('temp', $newDate, null, $this->storage));
                return new DateTimeVarHandler($anonymousName, $newDate, null, $this->storage);
            } elseif ($varB instanceof DateTimeVarHandler) {
                // Разность между датами возвращает интервал
                $diff = $this->datetime->diff($varB->datetime);
                $anonymousName = $this->registerAnonymous(new DateTimeIntervalVarHandler('temp', $diff, null, $this->storage));
                return new DateTimeIntervalVarHandler($anonymousName, $diff, null, $this->storage);
            }
            break;
            
        default:
            throw new \Exception("Incorrect operator $operator for " . __CLASS__);
    }
    
    throw new \Exception("Incorrect operator $operator for " . __CLASS__);
}

    private function compareDateTime(string $operator, DateTime $other): bool
    {
        $diff = $this->datetime->getTimestamp() - $other->getTimestamp();
        
        switch ($operator) {
            case '>': return $diff > 0;
            case '<': return $diff < 0;
            case '>=': return $diff >= 0;
            case '<=': return $diff <= 0;
            case '==': return $diff == 0;
            case '!=': return $diff != 0;
            default: return false;
        }
    }

    public function operatorUnaryCall(string $operator): ?AbstractVariableHandler
    {
        throw new \Exception("No unary operators supported for " . __CLASS__);
    }

    public function toString()
    {
        return $this->datetime->format('Y-m-d H:i:s');
    }

    public function toNum()
    {
        return $this->datetime->getTimestamp();
    }

    public function convertToMe(AbstractVariableHandler $var)
    {
        if ($var instanceof DateTimeVarHandler) {
            return $var;
        }
        
        $value = $var->toString();
        $newDateTime = new DateTime($value);
        return new DateTimeVarHandler('temp', $newDateTime, null, $this->storage);
    }
}