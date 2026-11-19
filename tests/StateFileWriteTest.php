<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Exceptions\StateFileException;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\ViewRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * What happens when the filesystem does not cooperate.
 *
 * StateVersioningTest covers files dissect chooses not to write. These cover
 * writes it tried to make and could not: the promise is the same in both
 * cases — the file somebody arranged is left exactly as it was, and the reason
 * comes back as a StateFileException rather than a PHP warning.
 */
class StateFileWriteTest extends PhpUnitTestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-write-'.uniqid();
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->directory, 0755);

        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            unlink($this->directory.'/'.$name);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function a_successful_write_leaves_nothing_but_the_file(): void
    {
        $path = $this->directory.'/layout.json';

        (new LayoutRepository($path))->put(['Post' => ['x' => 1, 'y' => 2]]);
        (new LayoutRepository($path))->put(['Post' => ['x' => 3, 'y' => 4]]);

        $this->assertSame([$path], glob($this->directory.'/*'));
    }

    #[Test]
    public function each_write_uses_a_temporary_file_of_its_own(): void
    {
        // The old fixed name: a file left behind by an interrupted write, or
        // being written by an overlapping request. Neither may be renamed into
        // place by this write.
        $path = $this->directory.'/layout.json';
        file_put_contents($path.'.tmp', '{"version": 1, "positions": {"Stale": {"x": 0, "y": 0}}}');

        (new LayoutRepository($path))->put(['Post' => ['x' => 1, 'y' => 2]]);

        $this->assertSame(['Post' => ['x' => 1, 'y' => 2]], (new LayoutRepository($path))->get()['positions']);
        $this->assertStringContainsString('Stale', (string) file_get_contents($path.'.tmp'));
    }

    #[Test]
    public function a_read_only_directory_is_reported_and_the_file_left_alone(): void
    {
        $this->skipIfRoot();

        $path = $this->directory.'/layout.json';
        file_put_contents($path, $before = '{"version": 1, "positions": {"Post": {"x": 7, "y": 9}}}');
        chmod($this->directory, 0555);

        try {
            (new LayoutRepository($path))->put(['Post' => ['x' => 1, 'y' => 1]]);
            $this->fail('the write should have been reported');
        } catch (StateFileException $e) {
            $this->assertSame(500, $e->status);
            $this->assertStringContainsString('layout.json', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($path));
        $this->assertSame([$path], glob($this->directory.'/*'), 'no temporary file may be left behind');
    }

    #[Test]
    public function a_directory_that_cannot_be_created_is_reported(): void
    {
        // A file where the directory should be: mkdir() fails no matter who
        // runs it, so this one holds under root too.
        file_put_contents($this->directory.'/.dissect', 'not a directory');

        $this->expectException(StateFileException::class);
        $this->expectExceptionMessage('directory could not be created');

        (new ViewRepository($this->directory.'/.dissect/views.json'))->put([
            ['id' => 'billing', 'name' => 'Billing', 'models' => ['Invoice']],
        ]);
    }

    protected function skipIfRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
    }
}
