<?php
namespace TestProj\Tests\Parts;

class Order
{
    public Customer $Customer;

    public Goods $Goods;
    public $Qnt;
    private $discount_sum;

    public $CreateTime;
    private bool $allow_order = false;

    /**
     * Номер чека — длинное число (до 20 знаков). Хранится СТРОКОЙ: 20-значное
     * число не помещается в PHP int и было бы превращено во float с потерей
     * точности. Интерпретатор обрабатывает его как decimal/bignum (bcmath).
     */
    public string $Receipt;

    public function __construct(Customer $customer, Goods $goods, int $qnt, string $receipt = '12345678901234567890')
    {
        $this->Customer = $customer;
        $this->Goods = $goods;
        $this->Qnt = $qnt;
        $this->CreateTime = date('Y-m-d H:i:s');
        $this->discount_sum = 0;
        $this->Receipt = $receipt;
    }

    public function getReceipt(): string
    {
        return $this->Receipt;
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

    public function setDeny(bool $value)
    {
        $this->allow_order = !$value;
    }

    public function getDeny(): bool
    {
        return !$this->allow_order;
    }
}