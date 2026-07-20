<?php

namespace KdrDev\Dissect;

use JsonException;

/**
 * Reads and writes the hand-arranged node positions.
 *
 * The file is plain JSON in the application (not in vendor/, which composer
 * wipes, and not in storage/, which is gitignored) so a team can commit and
 * review a layout the same way they review code.
 */
class LayoutRepository
{
    public function __construct(protected string $path) {}

    /** @return array{version: int, positions: array<string, array{x: int, y: int}>} */
    public function get(): array
    {
        if (! is_file($this->path)) {
            return ['version' => 1, 'positions' => []];
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupt layout must never blank the page — fall back to the
            // computed grid instead.
            return ['version' => 1, 'positions' => []];
        }

        return [
            'version' => 1,
            'positions' => $this->sanitise($decoded['positions'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $positions
     * @return int Number of positions written.
     */
    public function put(array $positions): int
    {
        $clean = $this->sanitise($positions);

        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $payload = json_encode(
            ['version' => 1, 'positions' => $clean],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Write-then-rename: an interrupted write leaves the previous layout
        // intact rather than a truncated file nothing can parse.
        $temp = $this->path.'.tmp';
        file_put_contents($temp, $payload.PHP_EOL);
        rename($temp, $this->path);

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
