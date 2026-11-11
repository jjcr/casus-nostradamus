<?php
declare(strict_types=1);

// Specs voor het verlopen van klare orders die niet worden opgehaald.
describe('OrderExpiry', function () {

    beforeEach(function () {
        $this->pdo = TestDb::fresh(10);
        OrderController::handle($this->pdo, [
            'idempotency_key' => 'k1',
            'visitor_id' => 'v1',
            'items' => [['product_id' => 1, 'quantity' => 3]],
        ], 1000);
    });

    it('laat een klare order staan zolang de ophaaltijd niet verstreken is', function () {
        $this->pdo->exec("UPDATE orders SET status = 'ready', ready_at = 1000");

        $expired = OrderExpiry::run($this->pdo, 1000 + 599);

        expect($expired)->toBe(0);
        expect($this->pdo->query('SELECT status FROM orders')->fetchColumn())->toBe('ready');
    });

    it('laat een niet-opgehaalde order verlopen en geeft de voorraad terug', function () {
        $this->pdo->exec("UPDATE orders SET status = 'ready', ready_at = 1000");
        expect(TestDb::stock($this->pdo, 1))->toBe(7);

        $expired = OrderExpiry::run($this->pdo, 1000 + 600);

        expect($expired)->toBe(1);
        expect($this->pdo->query('SELECT status FROM orders')->fetchColumn())->toBe('expired');
        expect(TestDb::stock($this->pdo, 1))->toBe(10);
    });

    it('raakt orders die nog niet klaar zijn niet aan', function () {
        $expired = OrderExpiry::run($this->pdo, 1000 + 99999);

        expect($expired)->toBe(0);
        expect(TestDb::stock($this->pdo, 1))->toBe(7);
    });
});
