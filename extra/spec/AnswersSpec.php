<?php
declare(strict_types=1);

// POST /game is alleen beschreven (docs/implementatie.md), niet gebouwd. Wat wel bestaat is de regel
// "per vraag telt elke bezoeker een keer": die zit in het schema als UNIQUE(question_id, visitor_id).
// Deze spec test die constraint, zodat de beschreven aanpak aantoonbaar werkt.
describe('answers', function () {

    beforeEach(function () {
        $this->pdo = TestDb::fresh();
        $this->pdo->exec("INSERT INTO questions (id, text, options, correct_option, opens_at, closes_at)
                          VALUES (1, 'Vraag?', '[\"a\",\"b\"]', 0, 0, 100)");
        $this->answer = function (int $questionId, string $visitorId, int $option): void {
            $this->pdo->prepare('INSERT INTO answers (question_id, visitor_id, option, created_at) VALUES (?, ?, ?, 0)')
                ->execute([$questionId, $visitorId, $option]);
        };
    });

    it('weigert een tweede antwoord van dezelfde bezoeker op dezelfde vraag', function () {
        ($this->answer)(1, 'visitor-1', 0);

        $error = null;
        try {
            ($this->answer)(1, 'visitor-1', 1);
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }

        expect($error)->toContain('UNIQUE');
        expect(TestDb::count($this->pdo, 'answers'))->toBe(1);
    });

    it('telt het eerste antwoord, niet het tweede', function () {
        ($this->answer)(1, 'visitor-1', 0); // goed
        try {
            ($this->answer)(1, 'visitor-1', 1); // fout, wordt geweigerd
        } catch (PDOException $e) {
        }

        $score = GameStateWriter::build($this->pdo, 200)['scores'];

        expect($score)->toBe([['visitor_id' => 'visitor-1', 'score' => 1]]);
    });

    it('laat verschillende bezoekers en verschillende vragen wel toe', function () {
        $this->pdo->exec("INSERT INTO questions (id, text, options, correct_option, opens_at, closes_at)
                          VALUES (2, 'Nog een?', '[\"a\",\"b\"]', 1, 0, 100)");

        ($this->answer)(1, 'visitor-1', 0);
        ($this->answer)(1, 'visitor-2', 0);
        ($this->answer)(2, 'visitor-1', 1);

        expect(TestDb::count($this->pdo, 'answers'))->toBe(3);
    });
});
