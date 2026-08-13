<?php

namespace KdrDev\Dissect;

use KdrDev\Dissect\Concerns\VersionedStateFile;
use KdrDev\Dissect\Exceptions\StateFileException;

/**
 * Reads and writes the hand-arranged node positions.
 *
 * The file is plain JSON in the application (not in vendor/, which composer
 * wipes, and not in storage/, which is gitignored) so a team can commit and
 * review a layout the same way they review code. Living outside vendor/ is also
 * what makes it survive `composer update`.
 */
class LayoutRepository
{
    use VersionedStateFile;

    /** Format of the file this version reads and writes. See VersionedStateFile. */
    public const VERSION = 1;

    public function __construct(protected string $path) {}

    /** @return array{version: int, positions: array<string, array{x: int, y: int}>} */
    public function get(): array
    {
        // A missing or corrupt layout must never blank the page — fall back to
        // the computed grid instead.
        $decoded = $this->decode();

        return [
            'version' => self::VERSION,
            'positions' => $this->sanitise($decoded['positions'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $positions
     * @return int Number of positions written.
     *
     * @throws StateFileException when the existing file must not be overwritten.
     */
    public function put(array $positions): int
    {
        $this->guardWrite();

        $clean = $this->sanitise($positions);

        $this->writeAtomically(['version' => self::VERSION, 'positions' => $clean]);

        return count($clean);
    }

    /**
     * Only finite numeric coordinates survive, so neither a malformed file nor
     * a malformed request can put junk into the payload the page consumes.
     *
     * @param  mixed  $positions
     * @return array<string, array{x: int, y: int}>
     */
    protected function sanitise(mixed $positions): array
    {
        if (! is_array($positions)) {
            return [];
        }

        $clean = [];

        foreach ($positions as $id => $position) {
            if (! is_string($id) || ! is_array($position)) {
                continue;
            }

            $x = $position['x'] ?? null;
            $y = $position['y'] ?? null;

            if (! is_numeric($x) || ! is_numeric($y)) {
                continue;
            }

            if (! is_finite((float) $x) || ! is_finite((float) $y)) {
                continue;
            }

            $clean[$id] = ['x' => (int) round((float) $x), 'y' => (int) round((float) $y)];
        }

        return $clean;
    }
}
