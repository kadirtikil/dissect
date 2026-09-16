<?php

namespace KdrDev\Dissect\Support;

use Throwable;

/**
 * The class a file declares, read from the file itself.
 *
 * Two scans need this and neither can infer it: {@see \KdrDev\Dissect\Jobs\JobDiscovery}
 * walks four configured directories looking for queueables, and the provider
 * scan walks however many an application keeps its providers in. Inferring a
 * namespace from a directory works when there is one directory and a setting
 * naming it; it stops working the moment a path is configurable, and every one
 * of these is.
 *
 * The answer is already written at the top of every PHP file, so it is read
 * from there rather than guessed at.
 */
final class ClassFile
{
    /**
     * The first class declared in a file, fully qualified, or null.
     *
     * Tokens rather than a regular expression: `class` is a word that appears
     * in `Foo::class`, in `new class`, and in every doc block and string
     * literal in the file. The tokeniser already knows which of those is a
     * declaration, and asking it costs less than being wrong.
     */
    public static function classIn(string $file): ?string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        try {
            $tokens = @token_get_all($contents);
        } catch (Throwable) {
            // A file this PHP version cannot tokenise is a file this package
            // has no opinion about.
            return null;
        }

        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::nameAfter($tokens, $i);

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` is a constant fetch and `new class` is an anonymous
            // declaration; neither names a class this can address.
            $previous = self::significantBefore($tokens, $i);

            if ($previous === T_DOUBLE_COLON || $previous === T_NEW) {
                continue;
            }

            $name = self::nameAfter($tokens, $i);

            if ($name === '') {
                return null;
            }

            return $namespace === '' ? $name : $namespace.'\\'.$name;
        }

        return null;
    }

    /**
     * The name token following position `$from`, joined.
     *
     * PHP 8 hands back a qualified name as a single token, but a namespace can
     * still arrive in pieces, so both forms are accumulated.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected static function nameAfter(array $tokens, int $from): string
    {
        $name = '';

        for ($i = $from + 1, $length = count($tokens); $i < $length; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                // Leading whitespace, but once a name has started a gap ends it:
                // `namespace App; class Foo` must not run together.
                if ($name === '') {
                    continue;
                }

                break;
            }

            if (! is_array($token)) {
                break;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];

                continue;
            }

            break;
        }

        return trim($name, '\\');
    }

    /**
     * The kind of the last token before `$from` that carries meaning, or null.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected static function significantBefore(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }
}
