<?php
namespace TestProj;
class Goods
{
    public $Producer_name;
    public $Producer_country;

    public $Price;

    public $Name;

    public function __construct(string $name, float $price, string $producer_name, string $producer_country)
    {
        $this->Name = $name;
        $this->Price = $price;
        $this->Producer_name = $producer_name;
        $this->Producer_country = $producer_country;
    }
}