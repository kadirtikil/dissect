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

    /**
     * Far more models than the graph draws usefully, and few enough that a
     * request cannot turn the file into something the page chokes on.
     */
    protected const MAX_POSITIONS = 10000;

    /**
     * The canvas is not this big. Past it a coordinate is a bug or junk, and
     * past PHP_INT_MAX the int cast is a warning — which Laravel turns into a
     * 500 — and a garbage value.
     */
    protected const MAX_COORDINATE = 1000000;

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
     * Only model ids and finite, bounded coordinates survive, so neither a
     * malformed file nor a malformed request can put junk into the payload
     * the page consumes.
     *
     * Ids are held to the same rule as ViewRepository's model ids: nodes are
     * keyed by class basename, so anything else is not a node. That also keeps
     * invalid UTF-8 out, which json_encode() would otherwise throw on — a 500
     * from a request that was merely malformed.
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
            if (! is_string($id) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id) || ! is_array($position)) {
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

            if (abs((float) $x) > self::MAX_COORDINATE || abs((float) $y) > self::MAX_COORDINATE) {
                continue;
            }

            $clean[$id] = ['x' => (int) round((float) $x), 'y' => (int) round((float) $y)];

            if (count($clean) >= self::MAX_POSITIONS) {
                break;
            }
        }

        return $clean;
    }
}
