<?php
declare(strict_types=1);

// Instellingen komen uit environment-variabelen (zie docker-compose.yml).
final class Config
{
    public static function dbPath(): string
    {
        return getenv('DB_PATH') ?: '/data/klosterke.sqlite';
    }

    public static function gameJsonPath(): string
    {
        return getenv('GAME_JSON') ?: '/runtime/game.json';
    }

    // Klok eerder dan deze datum = klok is na reboot fout (Pi 3 heeft geen RTC).
    public static function eventDateMin(): string
    {
        return getenv('EVENT_DATE_MIN') ?: '2026-01-01';
    }

    public static function minFreeMb(): int
    {
        return (int) (getenv('MIN_FREE_MB') ?: 500);
    }
}
