<?php

namespace KdrDev\Dissect\Mcp;

/**
 * Finding what an agent asked for by any name it might use for it.
 *
 * An exact name wins over a partial one, so "Invoice" is the Invoice model
 * and not every model with "invoice" in it. Case never matters: an agent
 * writing `invoice` or `INVOICE` means the same class.
 */
final class Lookup
{
    /**
     * @template T of array
     *
     * @param  iterable<T>  $items
     * @param  callable(T): array<int, ?string>  $names  Every name an item answers to.
     * @return array<int, T>
     */
    public static function find(iterable $items, string $query, callable $names): array
    {
        $query = mb_strtolower(trim($query));
        $exact = [];
        $partial = [];

        if ($query === '') {
            return [];
        }

        foreach ($items as $item) {
            $candidates = array_map(fn (?string $name) => mb_strtolower((string) $name), $names($item));

            if (in_array($query, $candidates, true)) {
                $exact[] = $item;
            } elseif (array_filter($candidates, fn (string $name) => $name !== '' && str_contains($name, $query)) !== []) {
                $partial[] = $item;
            }
        }

        return $exact !== [] ? $exact : $partial;
    }
}
