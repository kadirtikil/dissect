<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Exceptions\StateFileException;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\ViewRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * The upgrade contract for the two files dissect keeps in the application.
 *
 * Both are hand-authored and both are rewritten in full on every save, so the
 * failure that matters is not a crash — it is a silent, successful write that
 * replaces somebody's layout with the subset the running version understood.
 * These tests pin the behaviour that prevents it. Neither repository touches
 * the framework, so they run without booting Laravel.
 */
class StateVersioningTest extends PhpUnitTestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-state-'.uniqid();
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    protected function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    protected function write(string $name, string $json): string
    {
        file_put_contents($path = $this->path($name), $json);

        return $path;
    }

    // A file from the future — the case that would otherwise lose data.

    #[Test]
    public function it_refuses_to_overwrite_a_layout_written_by_a_newer_version(): void
    {
        $path = $this->write('layout.json', json_encode([
            'version' => 2,
            'positions' => ['Post' => ['x' => 10, 'y' => 20]],
        ]));

        $before = file_get_contents($path);

        $this->expectException(StateFileException::class);

        try {
            (new LayoutRepository($path))->put(['Post' => ['x' => 999, 'y' => 999]]);
        } finally {
            $this->assertSame($before, file_get_contents($path), 'the file must be left byte-for-byte unchanged');
        }
    }

    #[Test]
    public function it_refuses_to_overwrite_views_written_by_a_newer_version(): void
    {
        $path = $this->write('views.json', json_encode([
            'version' => 2,
            'views' => [['id' => 'billing', 'name' => 'Billing', 'models' => ['Invoice']]],
        ]));

        $before = file_get_contents($path);

        $this->expectException(StateFileException::class);

        try {
            (new ViewRepository($path))->put([]);
        } finally {
            $this->assertSame($before, file_get_contents($path));
        }
    }

    #[Test]
    public function a_newer_file_is_still_readable(): void
    {
        // Refusing to write must not mean refusing to render: the page shows
        // what this version can make sense of, and simply cannot save.
        $path = $this->write('layout.json', json_encode([
            'version' => 2,
            'positions' => ['Post' => ['x' => 10, 'y' => 20]],
        ]));

        $this->assertSame(
            ['Post' => ['x' => 10, 'y' => 20]],
            (new LayoutRepository($path))->get()['positions'],
        );
    }

    // A file from the past — rewritten, but not before a copy is taken.

    #[Test]
    public function it_copies_an_older_layout_aside_before_rewriting_it(): void
    {
        $path = $this->write('layout.json', $original = json_encode([
            'version' => 0,
            'positions' => ['Post' => ['x' => 10, 'y' => 20]],
        ]));

        (new LayoutRepository($path))->put(['Post' => ['x' => 30, 'y' => 40]]);

        $this->assertFileExists($path.'.v0.bak');
        $this->assertSame($original, file_get_contents($path.'.v0.bak'));
        $this->assertSame(['Post' => ['x' => 30, 'y' => 40]], (new LayoutRepository($path))->get()['positions']);
    }

    #[Test]
    public function it_never_overwrites_an_existing_backup(): void
    {
        // The copy worth keeping is the first one — taken before any write in
        // the new format touched the file. A later migration must not replace
        // it with an already-migrated copy.
        $path = $this->write('layout.json', json_encode(['version' => 0, 'positions' => []]));
        file_put_contents($path.'.v0.bak', 'the original');

        (new LayoutRepository($path))->put([]);

        $this->assertSame('the original', file_get_contents($path.'.v0.bak'));
    }

    #[Test]
    public function it_copies_a_corrupt_file_aside_before_replacing_it(): void
    {
        $path = $this->write('views.json', $mangled = '{"version": 1, "views": [ truncated');

        (new ViewRepository($path))->put([
            ['id' => 'billing', 'name' => 'Billing', 'models' => ['Invoice']],
        ]);

        $this->assertFileExists($path.'.corrupt.bak');
        $this->assertSame($mangled, file_get_contents($path.'.corrupt.bak'));
    }

    // The ordinary path stays ordinary.

    #[Test]
    public function a_current_file_is_rewritten_without_a_backup(): void
    {
        $path = $this->write('layout.json', json_encode([
            'version' => LayoutRepository::VERSION,
            'positions' => ['Post' => ['x' => 10, 'y' => 20]],
        ]));

        (new LayoutRepository($path))->put(['Post' => ['x' => 30, 'y' => 40]]);

        $this->assertCount(0, glob($this->directory.'/*.bak') ?: []);
        $this->assertSame(['Post' => ['x' => 30, 'y' => 40]], (new LayoutRepository($path))->get()['positions']);
    }

    #[Test]
    public function a_first_write_needs_no_existing_file(): void
    {
        $path = $this->path('layout.json');

        $this->assertSame(1, (new LayoutRepository($path))->put(['Post' => ['x' => 1, 'y' => 2]]));
        $this->assertFileExists($path);
    }

    #[Test]
    public function a_missing_version_is_read_as_the_first_format(): void
    {
        // Files written before anything read the field say version 1 already,
        // but a hand-edited one may not say anything at all.
        $path = $this->write('layout.json', json_encode(['positions' => ['Post' => ['x' => 1, 'y' => 2]]]));

        (new LayoutRepository($path))->put(['Post' => ['x' => 3, 'y' => 4]]);

        $this->assertCount(0, glob($this->directory.'/*.bak') ?: [], 'version 1 is current, so nothing to migrate');
    }

    #[Test]
    public function a_nonsense_version_is_treated_as_the_first_format_rather_than_the_future(): void
    {
        // A typo must not lock somebody out of their own layout file.
        $path = $this->write('layout.json', json_encode(['version' => 'banana', 'positions' => []]));

        (new LayoutRepository($path))->put(['Post' => ['x' => 1, 'y' => 2]]);

        $this->assertSame(['Post' => ['x' => 1, 'y' => 2]], (new LayoutRepository($path))->get()['positions']);
    }
}
