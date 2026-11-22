<?php

namespace KdrDev\Dissect\Support;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Repository;
use KdrDev\Dissect\Architecture\DomainMap;
use Throwable;

/**
 * Where every source-derived payload is cached, for whoever asks for it.
 *
 * The page, the console and the MCP server all read through this, so a
 * payload built for one is served to the others until the code changes. Each
 * surface's fingerprint says when that is: it is cheap to compute and moves
 * on every edit it covers, so a cached payload is never older than the source
 * it describes.
 */
final class PayloadCache
{
    protected ?string $stamp = null;

    public function __construct(
        protected Repository $cache,
        protected DomainMap $domains,
    ) {}

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function remember(string $surface, string $fingerprint, Closure $build): array
    {
        return $this->cache->remember($this->key($surface, $fingerprint), now()->addHour(), $build);
    }

    /**
     * Where a surface's export is cached.
     *
     * The fingerprint says whether the *source* moved. It cannot say whether
     * the package that reads it changed, or how it was told to read it: a
     * `composer update` that reshapes a payload, or a corrected
     * `DISSECT_MODELS_NAMESPACE`, leaves every file it stats untouched — and
     * the export from before would be served for the rest of the hour. The
     * stamp covers both, and domains.json, which puts a domain on every row
     * of every surface without being a file any of their signals watch.
     */
    public function key(string $surface, string $fingerprint): string
    {
        return 'dissect.'.$surface.'.'.$this->stamp().'.'.$fingerprint;
    }

    /** The installed package version and this package's config, hashed. */
    protected function stamp(): string
    {
        return $this->stamp ??= substr(sha1(json_encode([
            $this->packageVersion(),
            config('dissect'),
            $this->domains->fileSignal(),
        ], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''), 0, 12);
    }

    /**
     * The commit Composer installed, falling back to the version string.
     *
     * A VCS install of a branch keeps the same pretty version across updates
     * (`dev-main`), so the reference is the part that actually moves.
     */
    protected function packageVersion(): string
    {
        try {
            return (InstalledVersions::getReference('kdr-dev/dissect') ?? '')
                .'@'.(InstalledVersions::getPrettyVersion('kdr-dev/dissect') ?? '');
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
