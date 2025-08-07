<?php

namespace iustato\Bql\VarTypes;

use DateInterval;
use DateTime;
use iustato\Bql\VariableStorage;

class DateTimeIntervalVarHandler extends AbstractVariableHandler
{
    private DateInterval $interval;
    private int $totalSeconds; // Для удобства вычислений

    public function __construct(string $name, &$var, ?AbstractVariableHandler $parent = null, ?VariableStorage $storage = null)
    {
        parent::__construct($name, $var, $parent, $storage);
        
        if ($var instanceof DateInterval) {
            $this->interval = $var;
            $this->totalSeconds = $this->intervalToSeconds($var);
        } elseif (is_string($var)) {
            $this->parseStringInterval($var);
        } elseif (is_numeric($var)) {
            // Если передано число, считаем это секундами
            $this->totalSeconds = (int)$var;
            $this->interval = $this->secondsToInterval($this->totalSeconds);
        } else {
            // По умолчанию - нулевой интервал
            $this->interval = new DateInterval('PT0S');
            $this->totalSeconds = 0;
        }
        
        $this->type = 'interval';
    }

    public static function supports($variable): bool
    {
        if ($variable instanceof DateInterval) {
            return true;
        }
        
        if (is_string($variable)) {
            // Проверяем, соответствует ли строка формату интервала
            return (bool)preg_match('/^\d+\s+(second|minute|hour|day|week|month|year)s?$/i', $variable) ||
                   (bool)preg_match('/^P(\d+Y)?(\d+M)?(\d+D)?(T(\d+H)?(\d+M)?(\d+S)?)?$/', $variable);
        }
        
        return false;
    }

    private function parseStringInterval(string $intervalString): void
    {
        $intervalString = trim($intervalString);
        
        // Проверяем формат "5 days", "10 hours", "30 seconds" и т.д.
        if (preg_match('/^(\d+)\s+(second|minute|hour|day|week|month|year)s?$/i', $intervalString, $matches)) {
            $value = (int)$matches[1];
            $unit = strtolower($matches[2]);
            
            switch ($unit) {
                case 'second':
                    $this->totalSeconds = $value;
                    $this->interval = new DateInterval("PT{$value}S");
                    break;
                case 'minute':
                    $this->totalSeconds = $value * 60;
                    $this->interval = new DateInterval("PT{$value}M");
                    break;
                case 'hour':
                    $this->totalSeconds = $value * 3600;
                    $this->interval = new DateInterval("PT{$value}H");
                    break;
                case 'day':
                    $this->totalSeconds = $value * 86400;
                    $this->interval = new DateInterval("P{$value}D");
                    break;
                case 'week':
                    $days = $value * 7;
                    $this->totalSeconds = $days * 86400;
                    $this->interval = new DateInterval("P{$days}D");
                    break;
                case 'month':
                    $this->totalSeconds = $value * 2629746; // Приблизительно 30.44 дня
                    $this->interval = new DateInterval("P{$value}M");
                    break;
                case 'year':
                    $this->totalSeconds = $value * 31556952; // Приблизительно 365.25 дня
                    $this->interval = new DateInterval("P{$value}Y");
                    break;
            }
        }
        // Проверяем ISO 8601 формат (P1Y2M3DT4H5M6S)
        elseif (preg_match('/^P(\d+Y)?(\d+M)?(\d+D)?(T(\d+H)?(\d+M)?(\d+S)?)?$/', $intervalString)) {
            $this->interval = new DateInterval($intervalString);
            $this->totalSeconds = $this->intervalToSeconds($this->interval);
        }
        else {
            throw new \InvalidArgumentException("Invalid interval format: $intervalString");
        }
    }

    private function intervalToSeconds(DateInterval $interval): int
    {
        $seconds = $interval->s;
        $seconds += $interval->i * 60;
        $seconds += $interval->h * 3600;
        $seconds += $interval->d * 86400;
        $seconds += $interval->m * 2629746; // Приблизительно
        $seconds += $interval->y * 31556952; // Приблизительно
        
        return $seconds;
    }

    private function secondsToInterval(int $seconds): DateInterval
    {
        $years = floor($seconds / 31556952);
        $seconds %= 31556952;
        
        $months = floor($seconds / 2629746);
        $seconds %= 2629746;
        
        $days = floor($seconds / 86400);
        $seconds %= 86400;
        
        $hours = floor($seconds / 3600);
        $seconds %= 3600;
        
        $minutes = floor($seconds / 60);
        $seconds %= 60;
        
        $spec = 'P';
        if ($years > 0) $spec .= $years . 'Y';
        if ($months > 0) $spec .= $months . 'M';
        if ($days > 0) $spec .= $days . 'D';
        
        if ($hours > 0 || $minutes > 0 || $seconds > 0) {
            $spec .= 'T';
            if ($hours > 0) $spec .= $hours . 'H';
            if ($minutes > 0) $spec .= $minutes . 'M';
            if ($seconds > 0) $spec .= $seconds . 'S';
        }
        
        if ($spec === 'P') $spec = 'PT0S'; // Нулевой интервал
        
        return new DateInterval($spec);
    }

    public function &get(string $key = '')
    {
        if (empty($key)) {
            return $this->interval;
        }

        $key = strtolower($key);
        
        switch ($key) {
            case 'seconds':
            case 'totalseconds':
                $value = $this->totalSeconds;
                break;
            case 'minutes':
            case 'totalminutes':
                $value = floor($this->totalSeconds / 60);
                break;
            case 'hours':
            case 'totalhours':
                $value = floor($this->totalSeconds / 3600);
                break;
            case 'days':
            case 'totaldays':
                $value = floor($this->totalSeconds / 86400);
                break;
            case 'weeks':
                $value = floor($this->totalSeconds / (86400 * 7));
                break;
            case 'years':
                $value = $this->interval->y;
                break;
            case 'months':
                $value = $this->interval->m;
                break;
            case 'dayspart':
                $value = $this->interval->d;
                break;
            case 'hourspart':
                $value = $this->interval->h;
                break;
            case 'minutespart':
                $value = $this->interval->i;
                break;
            case 'secondspart':
                $value = $this->interval->s;
                break;
            case 'iso':
            case 'format':
                $value = $this->interval->format('%P');
                break;
            case 'readable':
                $value = $this->toReadableString();
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
            // Устанавливаем весь интервал
            if ($value instanceof DateInterval) {
                $this->interval = $value;
                $this->totalSeconds = $this->intervalToSeconds($value);
            } elseif (is_string($value)) {
                $this->parseStringInterval($value);
            } elseif (is_numeric($value)) {
                $this->totalSeconds = (int)$value;
                $this->interval = $this->secondsToInterval($this->totalSeconds);
            }
        }

        // Если есть родительский объект, обновляем его
        if ($this->parent !== null) {
            $this->parent->set($this->name, $this->interval, true);
        }
    }

    public function has(string $key): string
    {
        if (empty($key)) {
            return 'interval';
        }
        
        $key = strtolower($key);
        $supportedKeys = [
            'seconds', 'totalseconds', 'minutes', 'totalminutes', 'hours', 'totalhours',
            'days', 'totaldays', 'weeks', 'years', 'months', 'dayspart', 'hourspart',
            'minutespart', 'secondspart', 'iso', 'format', 'readable'
        ];
        
        return in_array($key, $supportedKeys) ? 'interval' : '';
    }

    public function operatorCall(string $operator, ?AbstractVariableHandler $varB): ?AbstractVariableHandler
    {
        switch (strtolower($operator)) {
            case '=':
                return $varB;
                
            case '+':
                if ($varB instanceof DateTimeIntervalVarHandler) {
                    // Сложение интервалов
                    $totalSeconds = $this->totalSeconds + $varB->totalSeconds;
                    $newInterval = $this->secondsToInterval($totalSeconds);
                    $anonymousName = $this->registerAnonymous(new DateTimeIntervalVarHandler('temp', $newInterval, null, $this->storage));
                    return new DateTimeIntervalVarHandler($anonymousName, $newInterval, null, $this->storage);
                } elseif ($varB instanceof DateTimeVarHandler) {
                    // Добавление интервала к дате
                    $date = clone $varB->get();
                    $date->add($this->interval);
                    $anonymousName = $this->registerAnonymous(new DateTimeVarHandler('temp', $date, null, $this->storage));
                    return new DateTimeVarHandler($anonymousName, $date, null, $this->storage);
                }
                break;
                
            case '-':
                if ($varB instanceof DateTimeIntervalVarHandler) {
                    // Вычитание интервалов
                    $totalSeconds = max(0, $this->totalSeconds - $varB->totalSeconds);
                    $newInterval = $this->secondsToInterval($totalSeconds);
                    $anonymousName = $this->registerAnonymous(new DateTimeIntervalVarHandler('temp', $newInterval, null, $this->storage));
                    return new DateTimeIntervalVarHandler($anonymousName, $newInterval, null, $this->storage);
                }
                break;
                
            case '*':
                if ($varB instanceof NumVarHandler) {
                    // Умножение интервала на число
                    $multiplier = $varB->toNum();
                    $totalSeconds = (int)($this->totalSeconds * $multiplier);
                    $newInterval = $this->secondsToInterval($totalSeconds);
                    $anonymousName = $this->registerAnonymous(new DateTimeIntervalVarHandler('temp', $newInterval, null, $this->storage));
                    return new DateTimeIntervalVarHandler($anonymousName, $newInterval, null, $this->storage);
                }
                break;
                
            case '/':
                if ($varB instanceof NumVarHandler) {
                    // Деление интервала на число
                    $divisor = $varB->toNum();
                    if ($divisor != 0) {
                        $totalSeconds = (int)($this->totalSeconds / $divisor);
                        $newInterval = $this->secondsToInterval($totalSeconds);
                        $anonymousName = $this->registerAnonymous(new DateTimeIntervalVarHandler('temp', $newInterval, null, $this->storage));
                        return new DateTimeIntervalVarHandler($anonymousName, $newInterval, null, $this->storage);
                    }
                }
                break;
                
            case '>':
            case '<':
            case '>=':
            case '<=':
            case '==':
            case '!=':
            if ($varB instanceof StringVarHandler)
            {
                $varC = new DateTimeIntervalVarHandler('temp', $varB->get());
                $varB = $varC;
            }

                if ($varB instanceof DateTimeIntervalVarHandler) {
                    $result = $this->compareInterval($operator, $varB);
                    $anonymousName = $this->registerAnonymous(new BoolVarHandler('temp', $result, null, $this->storage));
                    return new BoolVarHandler($anonymousName, $result, null, $this->storage);
                }
                break;
                
            default:
                throw new \Exception("Incorrect operator $operator for " . __CLASS__);
        }
        
        throw new \Exception("Incorrect operator $operator for " . __CLASS__);
    }

    private function compareInterval(string $operator, DateTimeIntervalVarHandler $other): bool
    {
        $diff = $this->totalSeconds - $other->totalSeconds;
        
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
        return $this->toReadableString();
    }

    private function toReadableString(): string
    {
        if ($this->totalSeconds == 0) {
            return '0 seconds';
        }
        
        $parts = [];
        
        if ($this->interval->y > 0) {
            $parts[] = $this->interval->y . ' year' . ($this->interval->y > 1 ? 's' : '');
        }
        if ($this->interval->m > 0) {
            $parts[] = $this->interval->m . ' month' . ($this->interval->m > 1 ? 's' : '');
        }
        if ($this->interval->d > 0) {
            $parts[] = $this->interval->d . ' day' . ($this->interval->d > 1 ? 's' : '');
        }
        if ($this->interval->h > 0) {
            $parts[] = $this->interval->h . ' hour' . ($this->interval->h > 1 ? 's' : '');
        }
        if ($this->interval->i > 0) {
            $parts[] = $this->interval->i . ' minute' . ($this->interval->i > 1 ? 's' : '');
        }
        if ($this->interval->s > 0) {
            $parts[] = $this->interval->s . ' second' . ($this->interval->s > 1 ? 's' : '');
        }
        
        return implode(', ', $parts);
    }

    public function toNum()
    {
        return $this->totalSeconds;
    }

    public function convertToMe(AbstractVariableHandler $var)
    {
        if ($var instanceof DateTimeIntervalVarHandler) {
            return $var;
        }
        
        $value = $var->toString();
        return new DateTimeIntervalVarHandler('temp', $value, null, $this->storage);
    }
}