<?php
namespace TestProj;
class Order
{
    public Customer $Customer;

    public Goods $Goods;
    public $Qnt;
    private $discount_sum;

    private bool $allow_order = false;

    public function __construct(Customer $customer, Goods $goods, int $qnt)
    {
        $this->Customer = $customer;
        $this->Goods = $goods;
        $this->Qnt = $qnt;
    }

    public function setDiscount($value): void
    {
        $this->discount_sum = $value;
    }

    public function getTest() {
        return 150;
    }
    public function getTotalwithoutdiscount(): float
    {
        return round($this->Goods->Price * $this->Qnt,2);
    }
    public function getTotal()
    {
        return round($this->getTotalwithoutdiscount() - $this->discount_sum,2);
    }

    public function setAllow(bool $value)
    {
        $this->allow_order = $value;
    }

    public function getAllow(): bool
    {
        return $this->allow_order;
    }
}