<?php
declare(strict_types=1);

// Specs voor wat de client via GET /game (game.json) te zien krijgt.
describe('GameStateWriter', function () {

    beforeEach(function () {
        $this->pdo = TestDb::fresh(10);
    });

    it('toont producten met prijs en bestelbaarheid, zonder de exacte voorraad', function () {
        $products = GameStateWriter::build($this->pdo, 1000)['products'];

        expect($products[0])->toBe(['id' => 1, 'name' => 'Rode Wijn', 'coins' => 1, 'available' => true]);
        expect(count($products))->toBe(3);
    });

    it('zet een product op niet bestelbaar als de voorraad op is of het is uitgezet', function () {
        $this->pdo->exec('UPDATE products SET stock = 0 WHERE id = 1');
        $this->pdo->exec('UPDATE products SET available = 0 WHERE id = 2');

        $products = GameStateWriter::build($this->pdo, 1000)['products'];

        expect($products[0]['available'])->toBe(false);
        expect($products[1]['available'])->toBe(false);
        expect($products[2]['available'])->toBe(true);
    });

    it('toont klare orders, pauze en wachttijd', function () {
        OrderController::handle($this->pdo, [
            'idempotency_key' => 'k1', 'visitor_id' => 'v1', 'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1000);
        $this->pdo->exec("UPDATE orders SET status = 'ready', ready_at = 1000");
        $this->pdo->exec("UPDATE settings SET value = '1' WHERE key = 'ordering_paused'");

        $state = GameStateWriter::build($this->pdo, 1001);

        expect($state['ready_orders'])->toBe([1]);
        expect($state['ordering_paused'])->toBe(true);
        expect($state['estimated_wait_minutes'])->toBe(0);
    });

    it('toont vervallen orders, zodat de bezoeker niet blijft denken dat ze nog gemaakt worden', function () {
        OrderController::handle($this->pdo, [
            'idempotency_key' => 'k1', 'visitor_id' => 'v1', 'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1000);
        $this->pdo->exec("UPDATE orders SET status = 'expired', ready_at = 1000, expired_at = 2000");

        $state = GameStateWriter::build($this->pdo, 2001);

        expect($state['expired_orders'])->toBe([1]);
        expect($state['ready_orders'])->toBe([]);
    });

    it('geeft het juiste antwoord van een open vraag niet prijs', function () {
        $this->pdo->exec("INSERT INTO questions (id, text, options, correct_option, opens_at, closes_at)
                          VALUES (1, 'Vraag?', '[\"a\",\"b\"]', 1, 0, 100)");

        $question = GameStateWriter::build($this->pdo, 50)['question'];

        expect(array_key_exists('correct_option', $question))->toBe(false);
        expect($question['text'])->toBe('Vraag?');
    });
});
