<?php

namespace KdrDev\Dissect\Architecture;

use Throwable;

/**
 * The domain a `#[Domain('…')]` attribute puts a class in, read from its file.
 *
 * The exception, not the rule: folders say where a class belongs, and this
 * moves the odd one that sits in the wrong place — a shared `app/Models/User`
 * that is really Identity's. It is checked against the folder, so an override
 * that has gone stale is reported rather than believed.
 *
 * Matched by short name. dissect ships no attribute class for an application to
 * depend on in production; the application declares its own `Domain`
 * attribute, in any namespace, and this recognises it.
 *
 * Read from tokens rather than by reflection, so nothing is loaded to answer:
 * the domain list walks every class in the application, and including all of
 * them would run whatever their files do at the top level.
 */
final class DomainAttribute
{
    /** The domain the file's class-level attribute names, or null. */
    public static function in(string $file): ?string
    {
        $contents = @file_get_contents($file);

        // Most files have no attribute at all; most of the rest have one that
        // is not this. Neither needs tokenising.
        if ($contents === false || ! str_contains($contents, '#[') || ! str_contains($contents, 'Domain')) {
            return null;
        }

        try {
            $tokens = @token_get_all($contents);
        } catch (Throwable) {
            return null;
        }

        $count = count($tokens);
        $brackets = 0;
        $parens = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($brackets === 0) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_ATTRIBUTE) {
                    $brackets = 1;

                    continue;
                }

                // Past the declaration, attributes belong to members.
                if (self::declares($tokens, $i)) {
                    return null;
                }

                continue;
            }

            // Inside an attribute group: `#[A, Domain('X'), B(['y'])]`.
            if (! is_array($token)) {
                match ($token) {
                    '[' => $brackets++,
                    ']' => $brackets--,
                    '(' => $parens++,
                    ')' => $parens--,
                    default => null,
                };

                continue;
            }

            if ($brackets !== 1 || $parens !== 0 || ! self::isName($token)) {
                continue;
            }

            $name = ltrim($token[1], '\\');
            $short = substr($name, (int) strrpos('\\'.$name, '\\'));

            if ($short === 'Domain') {
                $value = self::argument($tokens, $i + 1);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The first argument when it is a plain string: `('Billing')` or
     * `(name: 'Billing')`. Anything computed is not something a file can say.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected static function argument(array $tokens, int $from): ?string
    {
        $significant = [];

        for ($i = $from, $count = count($tokens); $i < $count && count($significant) < 5; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        if (($significant[0] ?? null) !== '(') {
            return null;
        }

        $value = $significant[1] ?? null;

        // A named argument.
        if (is_array($value) && $value[0] === T_STRING && ($significant[2] ?? null) === ':') {
            $value = $significant[3] ?? null;
        }

        if (! is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $quote = $value[1][0];
        $inner = substr($value[1], 1, -1);
        $string = $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
        $string = trim($string);

        return $string === '' ? null : $string;
    }

    /**
     * Whether position `$i` starts the file's class, interface, trait or enum.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected static function declares(array $tokens, int $i): bool
    {
        $token = $tokens[$i];

        if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            return false;
        }

        // `Foo::class` and `new class` are not declarations.
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return ! (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true));
        }

        return true;
    }

    /** @param  array{0: int, 1: string}  $token */
    protected static function isName(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }
}
