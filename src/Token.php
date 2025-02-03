<?php

namespace iustato\Bql;

class Token
{
    private string $type;
    private mixed $value;

    public function __construct(string $type, mixed $value)
    {
        $this->type = $type;
        $this->value = $value;
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
