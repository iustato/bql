<?php
namespace TestProj;
class Customer
{
    public $Name;
    public $Country;

    public function __construct($name, $country)
    {
        $this->Name = $name;
        $this->Country = $country;
    }

}