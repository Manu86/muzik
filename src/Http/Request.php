<?php

declare(strict_types=1);

/**
 * Lecture sécurisée des entrées de la requête HTTP (GET, corps JSON/POST,
 * paramètres encodés en application/x-www-form-urlencoded).
 */
final class Request
{
    public static function get(string $key, string $default = ''): string
    {
        return self::stringValue($_GET[$key] ?? null, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return self::integerValue($_GET[$key] ?? null, $default);
    }

    /**
     * Corps de la requête décodé en JSON, sinon les champs $_POST.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);

        return self::strings($input) ?: self::strings($_POST);
    }

    /**
     * Paramètres du corps x-www-form-urlencoded, sinon de la query string.
     *
     * @return array<string, mixed>
     */
    public static function bodyOrQuery(): array
    {
        $body = file_get_contents('php://input');
        $query = is_string($_SERVER['QUERY_STRING'] ?? null) ? $_SERVER['QUERY_STRING'] : '';
        $input = [];
        parse_str(is_string($body) && $body !== '' ? $body : $query, $input);

        return self::strings($input) ?: [];
    }

    /**
     * Normalise un tableau en clés de chaînes (JSON/query string).
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    private static function strings(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $values = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    public static function stringValue(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function integerValue(mixed $value, int $default = 0): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : $default;
    }

    public static function boolValue(mixed $value): bool
    {
        return filter_var(self::stringValue($value), FILTER_VALIDATE_BOOLEAN);
    }
}
