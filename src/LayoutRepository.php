<?php

namespace KdrDev\Dissect;

use Closure;
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

    /**
     * Format of the file this version reads and writes. See VersionedStateFile.
     *
     * 2: positions are keyed by full class name. 1 keyed them by short name.
     */
    public const VERSION = 2;

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

    /**
     * @param  (Closure(): iterable<string>)|null  $models  The graph's model ids, for
     *                                                      migrating a version 1 file.
     */
    public function __construct(protected string $path, protected ?Closure $models = null) {}

    /** @return array{version: int, positions: array<string, array{x: int, y: int}>, revision: string|null} */
    public function get(): array
    {
        // A missing or corrupt layout must never blank the page — fall back to
        // the computed grid instead.
        $contents = $this->contents();
        $decoded = $this->decode($contents);

        return [
            'version' => self::VERSION,
            'positions' => $this->sanitise($decoded['positions'] ?? [], $this->versionOf($decoded ?? [])),
            'revision' => $this->revisionOf($contents),
        ];
    }

    /**
     * @param  array<string, mixed>  $positions
     * @return int Number of positions written.
     *
     * @param  string|null  $basedOn  The revision the client last read; see VersionedStateFile::save().
     * @param  int  $format  The format the client sent. A page opened before an
     *                       upgrade still sends version 1 ids.
     *
     * @throws StateFileException when the existing file must not be overwritten.
     */
    public function put(array $positions, ?string $basedOn = self::ANY_REVISION, int $format = self::VERSION): int
    {
        $clean = $this->sanitise($positions, $format);

        $this->save(['version' => self::VERSION, 'positions' => $clean], $basedOn);

        return count($clean);
    }

    /**
     * Only model ids and finite, bounded coordinates survive, so neither a
     * malformed file nor a malformed request can put junk into the payload
     * the page consumes.
     *
     * Ids are held to the same rule as ViewRepository's model ids: nodes are
     * keyed by class name, so anything else is not a node. That also keeps
     * invalid UTF-8 out, which json_encode() would otherwise throw on — a 500
     * from a request that was merely malformed.
     *
     * A version 1 short name moves to the model it names. When two models
     * share it, neither gets the position: there is no telling which one was
     * dragged there, and both on one spot would hide one under the other.
     *
     * @param  mixed  $positions
     * @return array<string, array{x: int, y: int}>
     */
    protected function sanitise(mixed $positions, int $format = self::VERSION): array
    {
        if (! is_array($positions)) {
            return [];
        }

        $clean = [];

        foreach ($positions as $id => $position) {
            $id = $this->modelId($id);

            if ($id === null || ! is_array($position)) {
                continue;
            }

            if ($format < 2) {
                $ids = $this->upgradeModelId($id);

                if (count($ids) !== 1) {
                    continue;
                }

                $id = $ids[0];
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
