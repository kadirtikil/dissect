<?php

namespace KdrDev\Dissect\OpenApi;

use KdrDev\Dissect\Support\Surfaces;

/**
 * This app's OpenAPI document: its routes payload, with the settings in
 * `config/dissect.php` and what `config/auth.php` says about each guard.
 *
 * One place for the terminal and the page to get it from, so the file a
 * team commits and the one the page offers are the same file.
 */
final class Generator
{
    public const DESCRIPTION = 'Read from this app\'s routes, form requests and API resources by dissect. '
        .'Nothing here is written by hand: where the code does not say something, neither does this document.';

    public function __construct(protected Surfaces $surfaces) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        return Document::of($this->surfaces->export('routes')['routes'] ?? [], $this->options());
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        $config = (array) config('dissect.openapi', []);
        $guards = [];

        foreach ((array) config('auth.guards', []) as $name => $guard) {
            if (is_array($guard) && is_string($guard['driver'] ?? null)) {
                $guards[(string) $name] = $guard['driver'];
            }
        }

        return [
            'title' => (string) ($config['title'] ?? config('app.name') ?? 'API'),
            'version' => (string) ($config['version'] ?? '1.0.0'),
            'description' => $config['description'] ?? self::DESCRIPTION,
            // None configured is `/`, wherever the document is served from:
            // OpenAPI's own default, written out because linters ask for it.
            'servers' => (array) ($config['servers'] ?? []) ?: ['/'],
            'stacks' => (array) ($config['stacks'] ?? ['api']),
            'guards' => $guards,
            'default_guard' => (string) config('auth.defaults.guard', 'web'),
            'session_cookie' => (string) config('session.cookie', 'laravel_session'),
        ];
    }
}
