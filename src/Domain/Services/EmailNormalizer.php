<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

/**
 * The form of an address used to tell people apart on the verified public link: trimmed,
 * lowercase and without a "+tag" in its local part, so adding tags never makes a new person.
 */
final class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');

        return ($plus === false ? $local : substr($local, 0, $plus)).substr($email, $at);
    }
}
