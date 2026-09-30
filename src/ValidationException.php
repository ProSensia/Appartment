<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Validation failure
 * ---------------------------------------------------------------------------
 * Thrown by Validator::check() so a bad request can never reach a service.
 *
 * Lives in its own file so the autoloader can resolve the type by name:
 * services reference ValidationException without Validator ever being loaded.
 *
 * The API layer turns $errors into {ok:false, error:{code, details}} with a
 * 422, so the client can highlight the offending inputs directly.
 */

declare(strict_types=1);

final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors field => human-readable reason */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_values($errors)));
    }

    /** @param array<string,string> $errors */
    public static function field(string $field, string $reason): self
    {
        return new self([$field => $reason]);
    }
}