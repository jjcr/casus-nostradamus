<?php
declare(strict_types=1);

final class Http
{
    // Stuurt JSON en stopt het script.
    public static function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(int $status, string $code, string $message): never
    {
        self::json($status, ['error' => $code, 'message' => $message]);
    }

    // Leest de request body als JSON-object. Geeft 400 bij ongeldige JSON.
    public static function readJson(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            self::error(400, 'invalid_json', 'Body moet een JSON-object zijn.');
        }
        return $data;
    }
}
