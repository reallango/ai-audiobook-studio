<?php
declare(strict_types=1);
namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['csrf_token'];
    }
    public static function validate(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }
}
