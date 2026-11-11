<?php
declare(strict_types=1);

// Specs voor POST /order/new (OrderController::handle).
describe('OrderController', function () {

    // Een geldige bestelling; velden overschrijven per spec.
    $this->order = function (array $overrides = []): array {
        return $overrides + [
            'idempotency_key' => 'key-' . bin2hex(random_bytes(4)),
            'visitor_id' => 'visitor-1',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ];
    };

    beforeEach(function () {
        $this->pdo = TestDb::fresh(10);
    });

    describe('idempotency', function () {
        it('geeft bij dezelfde idempotency_key de bestaande bestelling terug en maakt geen tweede', function () {
            $order = ($this->order)(['items' => [['product_id' => 1, 'quantity' => 2]]]);

            [$firstStatus, $first] = OrderController::handle($this->pdo, $order, time());
            [$secondStatus, $second] = OrderController::handle($this->pdo, $order, time());

            expect($firstStatus)->toBe(201);
            expect($secondStatus)->toBe(200);
            expect($second['order_id'])->toBe($first['order_id']);
            expect(TestDb::count($this->pdo, 'orders'))->toBe(1);
            expect(TestDb::stock($this->pdo, 1))->toBe(8); // maar een keer afgeboekt
        });

        it('maakt precies een bestelling bij gelijktijdige requests met dezelfde key', function () {
            $orders = array_fill(0, 8, ['same-key', 'visitor-1', 1, 1]);

            $statuses = TestDb::placeInParallel($orders);

            expect(TestDb::count($this->pdo, 'orders'))->toBe(1);
            $counts = array_count_values($statuses);
            ksort($counts); // volgorde van de sleutels is niet belangrijk
            expect($counts)->toBe([200 => 7, 201 => 1]);
            expect(TestDb::stock($this->pdo, 1))->toBe(9);
        });

        it('geeft de bestaande bestelling ook terug als bestellen op pauze staat', function () {
            $order = ($this->order)();
            OrderController::handle($this->pdo, $order, time());
            $this->pdo->exec("UPDATE settings SET value = '1' WHERE key = 'ordering_paused'");

            [$status] = OrderController::handle($this->pdo, $order, time());

            expect($status)->toBe(200);
        });
    });

    describe('voorraad', function () {
        it('wordt nooit negatief, ook niet bij gelijktijdige bestellingen', function () {
            $this->pdo->exec('UPDATE products SET stock = 5 WHERE id = 1');
            $orders = [];
            for ($i = 1; $i <= 12; $i++) {
                $orders[] = ["race-$i", "visitor-$i", 1, 1];
            }

            $statuses = TestDb::placeInParallel($orders);

            $counts = array_count_values($statuses);
            ksort($counts);
            expect($counts)->toBe([201 => 5, 409 => 7]);
            expect(TestDb::stock($this->pdo, 1))->toBe(0);
            expect(TestDb::count($this->pdo, 'orders'))->toBe(5);
        });

        it('draait een bestelling helemaal terug als een van de producten uitverkocht is', function () {
            $this->pdo->exec('UPDATE products SET stock = 1 WHERE id = 2');
            $order = ($this->order)(['items' => [['product_id' => 1, 'quantity' => 2], ['product_id' => 2, 'quantity' => 2]]]);

            [$status, $body] = OrderController::handle($this->pdo, $order, time());

            expect($status)->toBe(409);
            expect($body['error'])->toBe('sold_out');
            expect(TestDb::stock($this->pdo, 1))->toBe(10); // product 1 is niet afgeboekt
            expect(TestDb::stock($this->pdo, 2))->toBe(1);
            expect(TestDb::count($this->pdo, 'orders'))->toBe(0);
        });

        it('weigert een product dat niet beschikbaar is', function () {
            $this->pdo->exec('UPDATE products SET available = 0 WHERE id = 1');

            [$status] = OrderController::handle($this->pdo, ($this->order)(), time());

            expect($status)->toBe(409);
        });
    });

    describe('prijs en afkoopgroep', function () {
        it('berekent total_coins server-side en negeert een prijs van de client', function () {
            $order = ($this->order)(['items' => [['product_id' => 1, 'quantity' => 2, 'coins' => 0]]]);

            [$status, $body] = OrderController::handle($this->pdo, $order, time());

            expect($status)->toBe(201);
            expect($body['total_coins'])->toBe(2); // 2 x 1 muntje, ondanks coins = 0 van de client
        });

        it('rekent 0 bij een geldige group_code', function () {
            $order = ($this->order)(['group_code' => 'KLOOSTER-001']);

            [$status, $body] = OrderController::handle($this->pdo, $order, time());

            expect($status)->toBe(201);
            expect($body['total_coins'])->toBe(0);
        });

        it('geeft 422 bij een onbekende group_code', function () {
            [$status, $body] = OrderController::handle($this->pdo, ($this->order)(['group_code' => 'ONBEKEND']), time());

            expect($status)->toBe(422);
            expect($body['error'])->toBe('unknown_group');
            expect(TestDb::count($this->pdo, 'orders'))->toBe(0);
        });

        it('legt de muntjes vast op het moment van bestellen', function () {
            OrderController::handle($this->pdo, ($this->order)(), time());
            $this->pdo->exec('UPDATE products SET coins = 5 WHERE id = 1');

            $price = $this->pdo->query('SELECT coins FROM order_items')->fetchColumn();

            expect((int) $price)->toBe(1);
        });
    });

    describe('bescherming van de bar', function () {
        it('weigert een derde openstaande bestelling van dezelfde bezoeker met 429', function () {
            OrderController::handle($this->pdo, ($this->order)(), time());
            OrderController::handle($this->pdo, ($this->order)(), time());

            [$status, $body] = OrderController::handle($this->pdo, ($this->order)(), time());

            expect($status)->toBe(429);
            expect($body['error'])->toBe('too_many_open_orders');
            expect(TestDb::stock($this->pdo, 1))->toBe(8);
        });

        it('geeft 503 met retry_after als bestellen op pauze staat', function () {
            $this->pdo->exec("UPDATE settings SET value = '1' WHERE key = 'ordering_paused'");

            [$status, $body] = OrderController::handle($this->pdo, ($this->order)(), time());

            expect($status)->toBe(503);
            expect($body['retry_after'])->toBeA('integer');
        });

        it('geeft 503 als het maximum aantal openstaande orders bereikt is', function () {
            $this->pdo->exec("UPDATE settings SET value = '1' WHERE key = 'max_open_orders'");
            OrderController::handle($this->pdo, ($this->order)(), time());

            [$status, $body] = OrderController::handle($this->pdo, ($this->order)(['visitor_id' => 'visitor-2']), time());

            expect($status)->toBe(503);
            expect($body['error'])->toBe('bar_busy');
        });

        it('geeft de verwachte wachttijd terug (openstaande orders x gemiddelde tijd)', function () {
            [, $body] = OrderController::handle($this->pdo, ($this->order)(), time());
            expect($body['estimated_wait_minutes'])->toBe(1); // 1 order x 30 s = 0,5 min, naar boven afgerond
        });

        it('weigert meer dan 4 stuks in een bestelling', function () {
            $order = ($this->order)(['items' => [['product_id' => 1, 'quantity' => 3], ['product_id' => 2, 'quantity' => 2]]]);

            [$status, $body] = OrderController::handle($this->pdo, $order, time());

            expect($status)->toBe(400);
            expect($body['error'])->toBe('invalid_items');
        });
    });

    describe('invoer', function () {
        it('eist een idempotency_key', function () {
            [$status] = OrderController::handle($this->pdo, ['visitor_id' => 'v', 'items' => [['product_id' => 1, 'quantity' => 1]]], time());
            expect($status)->toBe(400);
        });

        it('weigert lege items en quantity 0', function () {
            [$empty] = OrderController::handle($this->pdo, ($this->order)(['items' => []]), time());
            [$zero] = OrderController::handle($this->pdo, ($this->order)(['items' => [['product_id' => 1, 'quantity' => 0]]]), time());
            expect($empty)->toBe(400);
            expect($zero)->toBe(400);
        });

        it('geeft 422 bij een onbekend product', function () {
            [$status] = OrderController::handle($this->pdo, ($this->order)(['items' => [['product_id' => 99, 'quantity' => 1]]]), time());
            expect($status)->toBe(422);
        });
    });
});
