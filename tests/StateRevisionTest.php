<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\Exceptions\StateFileException;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\ViewRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * A save is based on what the page last read, and is refused when the file has
 * moved on since: a teammate's views arriving with a git pull, or a layout
 * arranged in another tab. Without this, the open page writes its stale copy
 * over them, whole.
 */
class StateRevisionTest extends PhpUnitTestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/dissect-revision-'.uniqid().'/views.json';
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->path).'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    /** @return array<int, array{id: string, name: string, models: array<int, string>}> */
    protected function views(string $name): array
    {
        return [['id' => strtolower($name), 'name' => $name, 'models' => ['Invoice']]];
    }

    #[Test]
    public function the_revision_travels_with_the_contents(): void
    {
        $views = new ViewRepository($this->path);

        $this->assertNull($views->get()['revision'], 'no file, no revision');

        $views->put($this->views('Billing'));

        $this->assertSame($views->revision(), $views->get()['revision']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $views->revision());
    }

    #[Test]
    public function a_save_based_on_the_current_file_goes_through(): void
    {
        $views = new ViewRepository($this->path);
        $views->put($this->views('Billing'));
        $read = $views->get()['revision'];

        $views->put($this->views('Sales'), $read);

        $this->assertSame('Sales', $views->get()['views'][0]['name']);
        $this->assertNotSame($read, $views->revision());
    }

    #[Test]
    public function a_save_based_on_an_older_file_is_refused_and_changes_nothing(): void
    {
        $views = new ViewRepository($this->path);
        $views->put($this->views('Billing'));
        $stale = $views->revision();

        // The pull: somebody else's views land on disk.
        $views->put($this->views('Teammate'));
        $before = file_get_contents($this->path);

        try {
            $views->put($this->views('Mine'), $stale);
            $this->fail('the stale save should have been refused');
        } catch (StateFileException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('views.json has changed', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function a_page_that_saw_no_file_does_not_replace_one_that_appeared(): void
    {
        // Null is a revision too: "there was nothing there".
        $views = new ViewRepository($this->path);
        $views->put($this->views('Teammate'));

        $this->expectException(StateFileException::class);

        $views->put($this->views('Mine'), null);
    }

    #[Test]
    public function a_page_that_saw_no_file_may_create_it(): void
    {
        $layout = new LayoutRepository(dirname($this->path).'/layout.json');

        $layout->put(['Post' => ['x' => 1, 'y' => 2]], null);

        $this->assertSame(['Post' => ['x' => 1, 'y' => 2]], $layout->get()['positions']);
    }

    #[Test]
    public function a_save_without_a_revision_is_not_checked(): void
    {
        // The dev-server plugin, and pages from before revisions existed.
        $layout = new LayoutRepository(dirname($this->path).'/layout.json');
        $layout->put(['Post' => ['x' => 1, 'y' => 2]]);

        $layout->put(['Post' => ['x' => 3, 'y' => 4]], LayoutRepository::ANY_REVISION);

        $this->assertSame(['Post' => ['x' => 3, 'y' => 4]], $layout->get()['positions']);
    }

    #[Test]
    public function the_refusal_comes_before_any_backup(): void
    {
        // A corrupt file gets copied aside before it is replaced. A save that
        // is refused replaces nothing, so it has nothing to copy.
        mkdir(dirname($this->path));
        file_put_contents($this->path, '{ broken');

        try {
            (new ViewRepository($this->path))->put($this->views('Mine'), 'not-the-revision');
        } catch (StateFileException) {
        }

        $this->assertFileDoesNotExist($this->path.'.corrupt.bak');
    }
}
