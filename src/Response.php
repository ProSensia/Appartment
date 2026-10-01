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

    /**
     * @param bool $log Set false when the caller has already recorded this
     *   failure in detail. api/index.php's catch blocks log the stack trace
     *   before responding, and letting this record too would write every
     *   exception to the ring log twice -- the rich entry, then a near-empty
     *   copy. Two entries per fault halves how far back the log reaches.
     */
    public static function error(
        string $message,
        int $status = 400,
        string $code = 'bad_request',
        array $details = [],
        bool $log = true
    ): never {
        self::$sent = true;
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        // Keep the failures worth remembering. Validation (422) and auth (401,
        // 419) are the user typing or a stale token, not defects, and logging
        // them would bury the real faults in noise.
        if ($log && $status >= 400 && !in_array($status, [401, 419, 422], true)) {
            Diag::record('api', $message, [
                'status'  => $status,
                'code'    => $code,
                'where'   => self::caller(),
                'details' => $details,
            ]);
        }

        echo json_encode(
            ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    /** One frame above Response::error(), so a report names the throwing class. */
    private static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            if (($frame['function'] ?? '') !== 'error') {
                return (string) ($frame['class'] ?? '') . ($frame['type'] ?? '')
                     . (string) ($frame['function'] ?? '');
            }
        }
        return 'unknown';
    }

    public static function sent(): bool
    {
        return self::$sent;
    }
}
