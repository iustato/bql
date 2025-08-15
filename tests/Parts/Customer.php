<?php
namespace TestProj\Tests\Parts;
class Customer
{
    private $Name;
    private $Country;

    private $Age;

    public function __construct($name, $country, $age = 32)
    {
        $this->Name = $name;
        $this->Country = $country;
        $this->Age = $age;
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