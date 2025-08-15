<?php
namespace TestProj\Tests\Parts;
class Customer
{
    private $Name;
    private $Country;

    public function __construct($name, $country)
    {
        $this->Name = $name;
        $this->Country = $country;
    }

    public function __get(string $name)
    {
        return $this->{$name};
    }

    public function __set(string $name, $value)
    {
        $this->{$name} = $value;
    }

}