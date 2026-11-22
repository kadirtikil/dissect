<?php

namespace KdrDev\Dissect\Architecture;

/**
 * What one domain is allowed to name, as written in `.dissect/domains.json`:
 *
 *     "rules": {
 *         "Sales": { "may_use": ["Billing"], "must_not_use": ["Illuminate\\Http"] }
 *     }
 *
 * `may_use` is an allow-list of other domains. Leaving it out leaves the
 * domain free to use any; an empty list means it may use none. A domain with
 * no rule at all is never checked.
 *
 * `must_not_use` is a list of namespaces (or single classes) none of the
 * domain's classes may name, the framework's and packages' included:
 * `Illuminate\Http` in a domain layer that must not know about requests.
 */
final class BoundaryRule
{
    /**
     * @param  array<int, string>|null  $mayUse  Null when every domain is allowed.
     * @param  array<int, string>  $mustNotUse
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?array $mayUse = null,
        public readonly array $mustNotUse = [],
    ) {}

    /** Whether a class in this domain may name one in the given domain. */
    public function allows(string $domain): bool
    {
        return $this->mayUse === null || $domain === $this->domain || in_array($domain, $this->mayUse, true);
    }

    /** The `must_not_use` entry a class name falls under, or null. */
    public function forbids(string $class): ?string
    {
        foreach ($this->mustNotUse as $namespace) {
            if ($class === $namespace || str_starts_with($class, $namespace.'\\')) {
                return $namespace;
            }
        }

        return null;
    }

    /** @return array{domain: string, may_use: ?array<int, string>, must_not_use: array<int, string>} */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'may_use' => $this->mayUse, 'must_not_use' => $this->mustNotUse];
    }
}
