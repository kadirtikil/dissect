<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\LayoutRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * LayoutRepository is the trust boundary for layout.json, the same way
 * ViewRepository is for views.json: whatever it accepts came from a POST or a
 * hand edit, and whatever it returns is inlined into the page.
 */
class LayoutRepositoryTest extends PhpUnitTestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/dissect-layout-'.uniqid().'/layout.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        if (is_dir(dirname($this->path))) {
            rmdir(dirname($this->path));
        }

        parent::tearDown();
    }

    protected function repository(): LayoutRepository
    {
        return new LayoutRepository($this->path);
    }

    #[Test]
    public function ids_that_are_not_model_names_are_dropped(): void
    {
        $this->repository()->put([
            'Post' => ['x' => 1, 'y' => 2],
            '<script>' => ['x' => 1, 'y' => 2],
            'App\\Models\\Post' => ['x' => 1, 'y' => 2],
            '' => ['x' => 1, 'y' => 2],
            '9Lives' => ['x' => 1, 'y' => 2],
            'App\\Models\\' => ['x' => 1, 'y' => 2],
            'App\\\\Models' => ['x' => 1, 'y' => 2],
            'App/Models/Tag' => ['x' => 1, 'y' => 2],
            str_repeat('A', 256) => ['x' => 1, 'y' => 2],
        ]);

        $this->assertSame([
            'Post' => ['x' => 1, 'y' => 2],
            'App\\Models\\Post' => ['x' => 1, 'y' => 2],
        ], $this->repository()->get()['positions']);
    }

    #[Test]
    public function an_id_that_is_not_utf8_is_dropped_rather_than_failing_the_write(): void
    {
        // json_encode() throws on it, which was a 500 for a merely malformed
        // request — and nothing else in the payload was saved.
        $saved = $this->repository()->put([
            "Po\xffst" => ['x' => 1, 'y' => 2],
            'Comment' => ['x' => 3, 'y' => 4],
        ]);

        $this->assertSame(1, $saved);
        $this->assertSame(['Comment' => ['x' => 3, 'y' => 4]], $this->repository()->get()['positions']);
    }

    #[Test]
    public function coordinates_off_any_canvas_are_dropped(): void
    {
        // 1e300 cannot be cast to an int: a warning (a 500 under Laravel) and
        // a garbage coordinate in the file.
        $this->repository()->put([
            'Post' => ['x' => 1e300, 'y' => 0],
            'Comment' => ['x' => 0, 'y' => -2000000],
            'Tag' => ['x' => -1000000, 'y' => 1000000],
        ]);

        $this->assertSame(
            ['Tag' => ['x' => -1000000, 'y' => 1000000]],
            $this->repository()->get()['positions'],
        );
    }

    #[Test]
    public function the_number_of_positions_is_capped(): void
    {
        $positions = [];

        for ($i = 0; $i < 10050; $i++) {
            $positions['Model'.$i] = ['x' => $i, 'y' => 0];
        }

        $this->assertSame(10000, $this->repository()->put($positions));
        $this->assertCount(10000, $this->repository()->get()['positions']);
    }

    #[Test]
    public function a_hand_edited_file_gets_the_same_rules(): void
    {
        mkdir(dirname($this->path));
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'positions' => [
                'Post' => ['x' => 10, 'y' => 20],
                'not a model' => ['x' => 0, 'y' => 0],
                'Comment' => ['x' => 'NaN', 'y' => 0],
            ],
        ]));

        $this->assertSame(['Post' => ['x' => 10, 'y' => 20]], $this->repository()->get()['positions']);
    }
}
