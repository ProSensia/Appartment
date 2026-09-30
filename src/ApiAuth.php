<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  API authentication
 * ---------------------------------------------------------------------------
 * The web UI uses a session cookie. Integrations and mobile clients send
 *   Authorization: Bearer <token>
 * where the token is a row in `sessions` (same table as "remember me").
 *
 * Bearer-authenticated calls skip the CSRF check, because they are not
 * browser-ambient credentials.
 */

declare(strict_types=1);

final class ApiAuth
{
    private static bool $usedBearer = false;

    public static function usedBearerToken(): bool
    {
        return self::$usedBearer;
    }

    /**
     * Resolve the caller. Accepts a session cookie or a bearer token.
     * @return bool true when a usable, active user was found
     */
    public static function resolve(): bool
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return Auth::check();
        }

        $row = Database::one(
            'SELECT id, user_id FROM sessions
              WHERE token_hash = :h AND expires_at > UTC_TIMESTAMP()',
            ['h' => hash('sha256', $m[1])]
        );
        if ($row === null) {
            // Fall back to a cookie if one happens to be present.
            return Auth::check();
        }

        $user = Database::one(
            "SELECT * FROM users WHERE id = :id AND status = 'active'",
            ['id' => (int) $row['user_id']]
        );
        if ($user === null) {
            return false;
        }

        self::$usedBearer = true;
        Database::update('sessions', ['last_used_at' => gmdate('Y-m-d H:i:s')], 'id', (int) $row['id']);
        Auth::primeFromUser($user);

        return true;
    }

    /** Mint a long-lived API token for integrations. Returns the raw value once. */
    public static function issueToken(int $userId, int $days = 90): string
    {
        $raw = bin2hex(random_bytes(32));
        Database::insert('sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $raw),
            'user_agent' => 'api-token',
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime("+$days days")),
        ]);
        return $raw;
    }

    public static function revokeAll(int $userId): int
    {
        return Database::delete('sessions', 'user_id', $userId);
    }
}
