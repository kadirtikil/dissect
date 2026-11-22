<?php

namespace KdrDev\Dissect\Mcp\Tools;

use Closure;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * What every dissect tool shares: how many matches are shown in full, and
 * that a failure is an answer rather than a crash.
 *
 * Payloads are taken by `handle()`, which the container calls, not by the
 * constructor: laravel/mcp builds a tool with `new` in places (its test
 * helpers did before 1.2.1), and a tool must survive that.
 *
 * Annotations are read from each tool's own class, not inherited, so every
 * tool carries `#[IsReadOnly]` and `#[IsIdempotent]` itself.
 */
abstract class DissectTool extends Tool
{
    /** Matches shown in full before the rest are listed by name only. */
    public const SHOWN = 5;

    /** Names listed when an index or a list of matches is long. */
    public const LISTED = 100;

    /** @param  Closure(): string  $answer */
    protected function answer(Closure $answer): Response
    {
        try {
            return Response::text($answer());
        } catch (Throwable $e) {
            return Response::error('dissect could not read the app: '.$e->getMessage());
        }
    }

    /**
     * Matches in full up to SHOWN, then by their first line only.
     *
     * @param  array<int, string>  $rendered  Each match, rendered in full.
     */
    protected function matches(string $what, string $query, array $rendered): string
    {
        $count = count($rendered);

        if ($count === 0) {
            return "No {$what} matches \"{$query}\". Call this tool without a name to list them all, or try dissect_search.\n";
        }

        $lines = [$count === 1 ? '' : "{$count} {$what}s match \"{$query}\".".($count > self::SHOWN ? ' The first '.self::SHOWN.' in full, then the rest by name:' : '')];

        foreach (array_slice($rendered, 0, self::SHOWN) as $one) {
            $lines[] = rtrim($one);
        }

        foreach (array_slice($rendered, self::SHOWN, self::LISTED) as $one) {
            $lines[] = strtok($one, "\n");
        }

        if ($count > self::SHOWN + self::LISTED) {
            $lines[] = '+'.($count - self::SHOWN - self::LISTED).' more. Narrow the name.';
        }

        return ltrim(implode("\n", $lines))."\n";
    }

    /** @param  array<int, string>  $lines */
    protected function index(string $heading, array $lines, string $hint): string
    {
        $shown = array_slice($lines, 0, self::LISTED * 5);
        $rest = count($lines) - count($shown);

        return implode("\n", [
            "# {$heading}",
            '',
            count($lines).' in all. '.$hint,
            '',
            ...$shown,
            ...($rest > 0 ? ["+{$rest} more. Ask for one by name, or use dissect_search."] : []),
        ])."\n";
    }
}
