<?php
// tests/OrderExpressionTest.php
namespace TestProj\Tests;

use iustato\Bql\ExpressionInterpreter;
use PHPUnit\Framework\TestCase;
use TestProj\Tests\Parts\Customer;
use TestProj\Tests\Parts\Goods;
use TestProj\Tests\Parts\Order;

class OrderExpressionTest extends TestCase
{
    private ExpressionInterpreter $interpreter;
    private Order $order;
    private array $results;

    protected function setUp(): void
    {
        $customer = new Customer("John Doe", "USA");
        $goods = new Goods("iPhone 14 Pro", 999.99, "Apple Inc", "USA");
        $this->order = new Order($customer, $goods, 5);

        $this->results = [
            'AllowPay' => false,
            'HaveDiscount' => false,
            'IsApple' => false,
        ];

        $this->interpreter = new ExpressionInterpreter();
        $this->interpreter->setVariables([
            'Order' => $this->order,
            'Result' => &$this->results,
            'producer_countries_with_discounts' => ['FIN', 'USA', 'GEO', 'ITA', 'DEU'],
            'prohibited_countries' => ['IRN', 'AFG']
        ]);
    }

    public function testOrderAllowPayment(): void
    {
        $this->interpreter->evaluate(
            "Result.AllowPay = !(Order.Customer.Country in prohibited_countries)"
        );

        $this->assertTrue($this->results['AllowPay']);
    }

    public function testOrderDiscountLogic(): void
    {
        $this->interpreter->evaluate(
            "Result.HaveDiscount = (Order.Totalwithoutdiscount > 5000 && Order.Goods.Producer_country in producer_countries_with_discounts)"
        );

        $this->assertFalse($this->results['HaveDiscount']); // 999.99 * 5 = 4999.95 < 5000
    }

    public function testAppleDetection(): void
    {
        $this->interpreter->evaluate(
            "Result.IsApple = Order.Goods.Producer_name like '%apple%'"
        );

        $this->assertTrue($this->results['IsApple']);
    }

    public function testProhibitedCountries(): void
    {
        $iranCustomer = new Customer("Ahmad Khan", "AFG");
        $iranOrder = new Order($iranCustomer, new Goods("Test", 100, "Test", "AFG"), 1);

        $this->interpreter->setVariables([
            'Order' => $iranOrder,
            'Result' => &$this->results,
            'prohibited_countries' => ['IRN', 'AFG']
        ]);

        $this->interpreter->evaluate("Result.AllowPay = !(Order.Customer.Country in prohibited_countries)");

        $this->assertFalse($this->results['AllowPay']);
    }
}
