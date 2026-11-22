<?php

namespace KdrDev\Dissect\Support;

/**
 * Class names as somebody reads them: the short name, with only as much of
 * the namespace as it takes to tell two apart.
 *
 * The same rule the page uses (resources/js/lib/classNames.ts), so a model is
 * called the same thing in the terminal, to an agent and on screen.
 */
final class ClassNames
{
    /**
     * @param  iterable<string>  $ids
     * @return array<string, string> Each full name, keyed to its short one.
     */
    public static function short(iterable $ids): array
    {
        $names = [];
        $unique = [];

        foreach ($ids as $id) {
            $unique[ltrim($id, '\\')] = true;
        }

        self::settle(array_keys($unique), 1, $names);

        return $names;
    }

    public static function base(string $name): string
    {
        $name = ltrim($name, '\\');
        $cut = strrpos($name, '\\');

        return $cut === false ? $name : substr($name, $cut + 1);
    }

    /**
     * @param  array<int, string>  $ids
     * @param  array<string, string>  $names
     */
    protected static function settle(array $ids, int $depth, array &$names): void
    {
        $groups = [];

        foreach ($ids as $id) {
            $tail = implode('\\', array_slice(explode('\\', $id), -$depth));
            $groups[$tail][] = $id;
        }

        foreach ($groups as $tail => $group) {
            // Alone at this depth, or out of segments: two names equal all the
            // way up cannot be told apart by any suffix.
            $exhausted = array_reduce($group, fn (bool $carry, string $id) => $carry && count(explode('\\', $id)) <= $depth, true);

            if (count($group) === 1 || $exhausted) {
                foreach ($group as $id) {
                    $names[$id] = (string) $tail;
                }
            } else {
                self::settle($group, $depth + 1, $names);
            }
        }
    }
}
