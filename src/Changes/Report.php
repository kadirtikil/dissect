<?php

namespace KdrDev\Dissect\Changes;

use KdrDev\Dissect\Support\ClassNames;

/**
 * The diff between two snapshots, judged and worded: what `dissect:diff`
 * prints, what an agent is told, and what the page will show.
 *
 * Each change is an item new, gone or changed, with its severity (the worst
 * of its facts) and a line of text; each fact of a changed item has the same.
 * The summary counts items, not facts: "2 breaking" is two things somebody
 * has to look at, however many columns each lost.
 */
final class Report
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{summary: array<string, int>, changes: array<int, array<string, mixed>>}
     */
    public static function between(array $before, array $after): array
    {
        $names = self::names($before, $after);
        $changes = [];

        foreach (Diff::between($before, $after) as $change) {
            $facts = array_map(fn (array $fact) => [
                ...$fact,
                'severity' => Severity::ofFact($change['part'], $fact),
                'text' => Wording::fact($fact),
            ], self::withoutEchoes($change['facts']));

            $name = $names[$change['part']][$change['id']] ?? $change['id'];

            $changes[] = [
                'part' => $change['part'],
                'id' => $change['id'],
                'name' => $name,
                'change' => $change['change'],
                'severity' => $facts === [] ? Severity::ofItem($change['change']) : Severity::worst(array_column($facts, 'severity')),
                'text' => Wording::item($change['part'], $name, $change['change']),
                'facts' => $facts,
            ];
        }

        // Part by part, as Diff gives them; within a part, worst first.
        $part = array_flip(array_keys(Snapshot::PARTS));
        $rank = array_flip(Severity::ORDER);
        usort($changes, fn (array $a, array $b) => [$part[$a['part']], $rank[$a['severity']], $a['id']] <=> [$part[$b['part']], $rank[$b['severity']], $b['id']]);

        $summary = array_fill_keys(Severity::ORDER, 0);

        foreach ($changes as $change) {
            $summary[$change['severity']]++;
        }

        return ['summary' => $summary, 'changes' => $changes];
    }

    /**
     * Facts that only repeat another: a field that is "now required" also
     * gained the rule `required`, and saying both is saying it twice.
     *
     * @param  array<int, array{path: array<int, string>, change: string, before: mixed, after: mixed}>  $facts
     * @return array<int, array{path: array<int, string>, change: string, before: mixed, after: mixed}>
     */
    protected static function withoutEchoes(array $facts): array
    {
        $required = [];

        foreach ($facts as $fact) {
            if (end($fact['path']) === 'required') {
                $required[implode("\0", array_slice($fact['path'], 0, -1))] = true;
            }
        }

        return array_values(array_filter($facts, fn (array $fact) => ! (
            end($fact['path']) === 'rules'
            && in_array($fact['before'] ?? $fact['after'], ['required'], true)
            && isset($required[implode("\0", array_slice($fact['path'], 0, -1))])
        )));
    }

    /**
     * "3 changes: 1 breaking, 2 additive", or with $since,
     * "3 changes since .dissect/snapshot.json: 1 breaking, 2 additive".
     *
     * @param  array<string, int>  $summary
     */
    public static function summary(array $summary, ?string $since = null): string
    {
        $total = array_sum($summary);
        $counted = array_map(fn (string $severity, int $count) => "{$count} {$severity}", array_keys(array_filter($summary)), array_filter($summary));

        return $total.' '.($total === 1 ? 'change' : 'changes').($since === null ? '' : " since {$since}").($counted === [] ? '' : ': '.implode(', ', $counted));
    }

    /**
     * What each item is called: a class by its short name, as on the page;
     * a route as `GET api/x`.
     *
     * @return array<string, array<string, string>>
     */
    protected static function names(array $before, array $after): array
    {
        $names = [];

        foreach (array_keys(Snapshot::PARTS) as $part) {
            $ids = array_unique([...array_map('strval', array_keys((array) ($before[$part] ?? []))), ...array_map('strval', array_keys((array) ($after[$part] ?? [])))]);

            $names[$part] = $part === 'routes'
                ? array_combine($ids, array_map(Wording::route(...), $ids)) ?: []
                : ClassNames::short($ids);
        }

        return $names;
    }
}
