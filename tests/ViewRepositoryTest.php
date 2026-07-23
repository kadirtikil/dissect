<?php

namespace KdrDev\Dissect\Tests;

use KdrDev\Dissect\ViewRepository;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * ViewRepository is the trust boundary for views.json: everything it returns is
 * rendered by the page, and everything it accepts came from either a POST or a
 * file somebody edited by hand. It has no framework dependencies, so these run
 * without booting Laravel.
 */
class ViewRepositoryTest extends PhpUnitTestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/dissect-views-'.uniqid().'/views.json';
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

    protected function repository(): ViewRepository
    {
        return new ViewRepository($this->path);
    }

    public function test_a_missing_file_reads_as_no_views(): void
    {
        $this->assertSame(['version' => 1, 'views' => []], $this->repository()->get());
    }

    public function test_it_writes_and_reads_back_a_view(): void
    {
        $written = $this->repository()->put([
            ['id' => 'billing', 'name' => 'Billing', 'models' => ['Invoice', 'Payment']],
        ]);

        $this->assertSame(1, $written);
        $this->assertSame([
            'version' => 1,
            'views' => [
                ['id' => 'billing', 'name' => 'Billing', 'models' => ['Invoice', 'Payment']],
            ],
        ], $this->repository()->get());
    }

    public function test_a_corrupt_file_degrades_to_no_views(): void
    {
        mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, '{ this is not json');

        // The graph is perfectly usable with no views; blanking the page over a
        // bad file is not.
        $this->assertSame(['version' => 1, 'views' => []], $this->repository()->get());
    }

    public function test_it_derives_a_missing_id_from_the_name(): void
    {
        $this->repository()->put([
            ['name' => 'Billing & Invoicing', 'models' => ['Invoice']],
        ]);

        $this->assertSame('billing-invoicing', $this->repository()->get()['views'][0]['id']);
    }

    public function test_it_drops_views_that_cannot_be_shown(): void
    {
        $this->repository()->put([
            ['id' => 'empty', 'name' => 'Empty', 'models' => []],
            ['id' => 'nameless', 'name' => '   ', 'models' => ['Invoice']],
            ['id' => 'good', 'name' => 'Good', 'models' => ['Invoice']],
            'not an array',
        ]);

        $views = $this->repository()->get()['views'];

        $this->assertCount(1, $views);
        $this->assertSame('good', $views[0]['id']);
    }

    public function test_a_duplicate_id_keeps_the_first_definition(): void
    {
        $this->repository()->put([
            ['id' => 'billing', 'name' => 'First', 'models' => ['Invoice']],
            ['id' => 'billing', 'name' => 'Second', 'models' => ['Payment']],
        ]);

        $views = $this->repository()->get()['views'];

        $this->assertCount(1, $views);
        $this->assertSame('First', $views[0]['name']);
    }

    public function test_it_rejects_model_ids_that_are_not_class_basenames(): void
    {
        $this->repository()->put([
            ['id' => 'v', 'name' => 'V', 'models' => ['Invoice', 'App\\Models\\Payment', '../etc', 42, 'Invoice']],
        ]);

        // Duplicates collapse, and anything that could not be a node id is
        // dropped rather than rendering a member matching nothing.
        $this->assertSame(['Invoice'], $this->repository()->get()['views'][0]['models']);
    }

    public function test_it_strips_control_characters_from_a_name(): void
    {
        $this->repository()->put([
            ['id' => 'v', 'name' => "Bill\x00ing\n", 'models' => ['Invoice']],
        ]);

        $this->assertSame('Billing', $this->repository()->get()['views'][0]['name']);
    }

    public function test_writing_replaces_the_whole_file(): void
    {
        $repository = $this->repository();

        $repository->put([['id' => 'a', 'name' => 'A', 'models' => ['Invoice']]]);
        $repository->put([['id' => 'b', 'name' => 'B', 'models' => ['Payment']]]);

        $views = $repository->get()['views'];

        $this->assertCount(1, $views);
        $this->assertSame('b', $views[0]['id']);
    }
}
