<?php

namespace KdrDev\Dissect\Mcp;

use Dotenv\Dotenv;
use Throwable;

/**
 * What the app's environment file said when this process read it.
 *
 * Laravel copies `.env` into the process environment when it boots, and never
 * overwrites a variable that is already set: that is how a real environment
 * wins over the file. The MCP server boots once and lives for the agent's
 * whole session, and each call reads through a fresh artisan that inherits
 * its environment. So a fresh artisan was handed every `.env` value as it
 * was when the server started, as real environment, and the file it then
 * read was ignored: an edit to DB_DATABASE or a DISSECT_ setting did nothing
 * until the server was restarted.
 *
 * A child is started without the variables this process took from the file,
 * so it reads them from the file again. One whose value is not what the file
 * said came from the real environment, as with DB_HOST set for an MCP server
 * that runs outside Sail's containers, and is passed on as it is.
 */
final class LoadedEnvironment
{
    /** @param  array<string, string|null>  $file  The file's variables, as read at boot. */
    public function __construct(protected array $file) {}

    public static function read(string $path): self
    {
        try {
            // phpdotenv comes with laravel/framework, which is what reads
            // .env at all; without it there is nothing to read again.
            return new self(is_file($path) && class_exists(Dotenv::class) ? Dotenv::parse((string) file_get_contents($path)) : []);
        } catch (Throwable) {
            // A file Laravel could not parse either: nothing was loaded from it.
            return new self([]);
        }
    }

    /**
     * The variables to leave out of a child's environment, in the form
     * Symfony's Process takes: false removes one.
     *
     * @return array<string, false>
     */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->file as $key => $value) {
            $current = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            if ($value !== null && $current !== false && is_scalar($current) && (string) $current === $value) {
                $stale[$key] = false;
            }
        }

        return $stale;
    }
}
