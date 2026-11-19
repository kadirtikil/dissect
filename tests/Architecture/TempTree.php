<?php

namespace KdrDev\Dissect\Tests\Architecture;

/**
 * A throwaway directory tree, built from a list of paths.
 *
 * A path ending in `/` is a directory; anything else is a file, written with
 * the given contents or an empty class.
 */
trait TempTree
{
    protected string $tree;

    /** @param  array<int|string, string>  $paths */
    protected function tree(array $paths): string
    {
        $this->tree = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/dissect-tree-'.uniqid();

        foreach ($paths as $key => $value) {
            [$path, $contents] = is_int($key) ? [$value, ''] : [$key, $value];
            $full = $this->tree.'/'.$path;

            if (str_ends_with($path, '/')) {
                @mkdir($full, 0755, true);

                continue;
            }

            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $contents);
        }

        return $this->tree;
    }

    protected function tearDown(): void
    {
        if (isset($this->tree)) {
            exec('rm -rf '.escapeshellarg($this->tree));
        }

        parent::tearDown();
    }
}
