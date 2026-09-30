<?php
/**
 * Uniform JSON response envelope for the /api endpoints.
 *
 *   { "ok": true,  "data": {...}, "meta": {...} }
 *   { "ok": false, "error": { "code": "invalid_input", "message": "...", "details": {...} } }
 */

declare(strict_types=1);

final class Response
{
    private static bool $sent = false;

    public static function json(mixed $data, int $status = 200, array $meta = []): never
    {
        self::$sent = true;
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $body = ['ok' => $status < 400, 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        echo json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    public static function error(
        string $message,
        int $status = 400,
        string $code = 'bad_request',
        array $details = []
    ): never {
        self::$sent = true;
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        echo json_encode(
            ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    public static function sent(): bool
    {
        return self::$sent;
    }
}
