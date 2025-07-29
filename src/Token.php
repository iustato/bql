<?php

namespace iustato\Bql;

use iustato\Bql\VarTypes\AbstractVariableHandler;

class Token
{
    private string $type;
    private mixed $value;

    public function __construct(string $type, mixed $value)
    {
        if ($value instanceof AbstractVariableHandler)
        {
            $this->type = $value->getType();
            $this->value = $value->get();
        }
        else
        {
            $this->type = $type;
            $this->value = $value;
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return json_encode([
            'type' => $this->type,
            'value' => $this->value
        ]);
    }
}
