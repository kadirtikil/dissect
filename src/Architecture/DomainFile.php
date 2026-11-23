<?php

namespace KdrDev\Dissect\Architecture;

use JsonException;
use KdrDev\Dissect\Support\Path;

/**
 * `.dissect/domains.json`: domains written down by hand.
 *
 * For what folders cannot say. An app with no convention to follow names its
 * domains here, and an app that follows one can still move a stray directory
 * (`app/Http/Controllers/Auth`) into the domain it belongs to. Committed and
 * reviewed like the layout and the views.
 *
 *     {
 *         "version": 1,
 *         "conventions": true,
 *         "domains": [
 *             { "name": "Identity", "paths": ["app/Models/User.php", "app/Http/Controllers/Auth"] }
 *         ],
 *         "rules": {
 *             "Sales": { "may_use": ["Billing"], "must_not_use": ["Illuminate\\Http"] }
 *         }
 *     }
 *
 * Paths are relative to the application root, or absolute, and `*` stands for
 * one directory name. A path may name a single file. Rules may name any
 * domain, however it was found: {@see BoundaryRule}.
 *
 * Read only. A file that cannot be used is reported, never fatal: the rest of
 * the page still works and the problem says what to fix.
 */
final class DomainFile
{
    public const VERSION = 1;

    /** @var array<int, Context> */
    protected array $contexts = [];

    /** @var array<string, BoundaryRule> */
    protected array $rules = [];

    /** @var array<int, string> */
    protected array $problems = [];

    protected bool $conventions = true;

    protected bool $loaded = false;

    public function __construct(
        protected string $path,
        protected string $root,
    ) {}

    /** @return array<int, Context> */
    public function contexts(): array
    {
        $this->load();

        return $this->contexts;
    }

    /**
     * Each domain's boundary rule, by domain name. A domain with none is
     * never checked.
     *
     * @return array<string, BoundaryRule>
     */
    public function rules(): array
    {
        $this->load();

        return $this->rules;
    }

    /**
     * What is wrong with the file, in words for the page.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $this->load();

        return $this->problems;
    }

    /** False when the file turns folder detection off. */
    public function conventions(): bool
    {
        $this->load();

        return $this->conventions;
    }

    /** Modification time, for the change signal; 0 when there is no file. */
    public function signal(): int
    {
        return is_file($this->path) ? (int) filemtime($this->path) : 0;
    }

    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (! is_file($this->path)) {
            return;
        }

        try {
            $data = json_decode((string) file_get_contents($this->path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->problems[] = "domains.json is not valid JSON: {$e->getMessage()}.";

            return;
        }

        if (! is_array($data)) {
            $this->problems[] = 'domains.json must hold an object.';

            return;
        }

        $version = $data['version'] ?? self::VERSION;

        if ($version !== self::VERSION) {
            $this->problems[] = "domains.json is version {$this->describe($version)}; this version of dissect reads version ".self::VERSION.'.';

            return;
        }

        if (array_key_exists('conventions', $data)) {
            if (is_bool($data['conventions'])) {
                $this->conventions = $data['conventions'];
            } else {
                $this->problems[] = '"conventions" must be true or false.';
            }
        }

        $domains = $data['domains'] ?? [];

        if (! is_array($domains) || ! array_is_list($domains)) {
            $this->problems[] = '"domains" must be a list.';

            return;
        }

        $byName = [];

        foreach ($domains as $index => $domain) {
            $context = $this->context($domain, $index);

            if ($context === null) {
                continue;
            }

            $byName[$context->name] = isset($byName[$context->name])
                ? $byName[$context->name]->merge($context)
                : $context;
        }

        ksort($byName);
        $this->contexts = array_values($byName);

        if (array_key_exists('rules', $data)) {
            $this->readRules($data['rules']);
        }
    }

    /**
     * A rule that cannot be read is left out and said so: a typo must not
     * quietly turn a check off.
     */
    protected function readRules(mixed $rules): void
    {
        if (! is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            $this->problems[] = '"rules" must be an object keyed by domain name.';

            return;
        }

        foreach ($rules as $domain => $rule) {
            $domain = trim((string) $domain);

            if (! is_array($rule) || ($rule !== [] && array_is_list($rule))) {
                $this->problems[] = "rules.{$domain} must be an object with may_use and/or must_not_use.";

                continue;
            }

            foreach (array_diff(array_keys($rule), ['may_use', 'must_not_use']) as $key) {
                $this->problems[] = "rules.{$domain}: unknown key \"{$key}\"; a rule has may_use and must_not_use.";
            }

            $mayUse = array_key_exists('may_use', $rule) ? $this->names($rule['may_use'], "rules.{$domain}.may_use") : null;
            $mustNotUse = array_key_exists('must_not_use', $rule)
                ? array_map(fn (string $name) => ltrim($name, '\\'), $this->names($rule['must_not_use'], "rules.{$domain}.must_not_use") ?? [])
                : [];

            $this->rules[$domain] = new BoundaryRule($domain, $mayUse, array_values(array_unique($mustNotUse)));
        }

        ksort($this->rules);
    }

    /**
     * A list of names, or null (with a problem) when it is not one. Null
     * leaves `may_use` unrestricted, which is what the rule said before it
     * was mistyped, rather than forbidding every domain on a typo.
     *
     * @return array<int, string>|null
     */
    protected function names(mixed $names, string $where): ?array
    {
        if (! is_array($names) || ! array_is_list($names)) {
            $this->problems[] = "{$where} must be a list of names.";

            return null;
        }

        $valid = array_filter($names, fn (mixed $name) => is_string($name) && trim($name) !== '');

        if (count($valid) !== count($names)) {
            $this->problems[] = "{$where}: every entry must be a non-empty string.";
        }

        return array_values(array_unique(array_map(trim(...), $valid)));
    }

    protected function context(mixed $domain, int $index): ?Context
    {
        $where = 'domains['.$index.']';

        if (! is_array($domain)) {
            $this->problems[] = "{$where} must be an object with a name and paths.";

            return null;
        }

        $name = $domain['name'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 64) {
            $this->problems[] = "{$where} needs a name of 1 to 64 characters.";

            return null;
        }

        $name = trim($name);
        $paths = $domain['paths'] ?? [];

        if (! is_array($paths) || ! array_is_list($paths)) {
            $this->problems[] = "\"{$name}\": paths must be a list.";

            return null;
        }

        $roots = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                $this->problems[] = "\"{$name}\": every path must be a non-empty string.";

                continue;
            }

            $matches = $this->expand($path);

            if ($matches === []) {
                $this->problems[] = "\"{$name}\": {$path} matches nothing.";
            }

            array_push($roots, ...$matches);
        }

        return new Context($name, [ContextSource::Config], array_values(array_unique($roots)));
    }

    /**
     * A configured path as the files and directories it names.
     *
     * @return array<int, string>
     */
    protected function expand(string $path): array
    {
        $absolute = Path::isAbsolute($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.rtrim($path, DIRECTORY_SEPARATOR);

        $matches = str_contains($absolute, '*') ? (glob($absolute) ?: []) : [$absolute];

        return array_values(array_map(
            fn (string $match) => realpath($match) ?: $match,
            array_filter($matches, file_exists(...)),
        ));
    }

    protected function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
