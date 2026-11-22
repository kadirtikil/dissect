<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DiffCommandTest extends TestCase
{
    protected string $directory;

    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/dissect-diff-'.bin2hex(random_bytes(4));
        $this->file = $this->directory.'/snapshot.json';
        mkdir($this->directory);
        config()->set('dissect.snapshot_path', $this->file);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return array{int, string} */
    protected function diff(array $options = []): array
    {
        $code = Artisan::call('dissect:diff', $options);

        return [$code, Artisan::output()];
    }

    /**
     * Writes the snapshot of the app as it is now, changed by $edit: the
     * diff then reads as the reverse of the edit.
     */
    protected function snapshot(?callable $edit = null): void
    {
        $snapshot = $this->app->make(Snapshot::class)->take();

        if ($edit !== null) {
            $edit($snapshot);
        }

        file_put_contents($this->file, Snapshot::encode($snapshot));
    }

    #[Test]
    public function nothing_changed_since_a_fresh_snapshot(): void
    {
        $this->snapshot();

        [$code, $out] = $this->diff();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing changed since '.$this->file.'.', $out);
    }

    #[Test]
    public function each_change_is_listed_with_its_severity(): void
    {
        // Before: the store route did not exist, Post had no tags, and the
        // update route's name was something else.
        $this->snapshot(function (array &$snapshot) {
            unset($snapshot['routes']['POST:api/posts']);
            unset($snapshot['models']['Workbench\\App\\Models\\Post']['relations']['tags']);
            $snapshot['routes']['PUT|PATCH:api/posts/{post}']['name'] = 'posts.edit';
        });

        [$code, $out] = $this->diff();

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  additive ~ model Post\n", $out);
        $this->assertStringContainsString("  additive     + relation tags (MorphToMany Tag)\n", $out);
        $this->assertStringContainsString("  additive + route POST api/posts\n", $out);
        $this->assertStringContainsString("  breaking ~ route PUT|PATCH api/posts/{post}\n", $out);
        $this->assertStringContainsString("  breaking     ~ name posts.edit → posts.update\n", $out);
        $this->assertStringContainsString('3 changes: 1 breaking, 2 additive since '.$this->file.'.', $out);
    }

    #[Test]
    public function json_is_the_report_for_a_script(): void
    {
        $this->snapshot(function (array &$snapshot) {
            unset($snapshot['routes']['POST:api/posts']);
        });

        [$code, $out] = $this->diff(['--format' => 'json']);
        $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $code);
        $this->assertSame($this->file, $json['against']);
        $this->assertSame(['breaking' => 0, 'risky' => 0, 'additive' => 1], $json['summary']);
        $this->assertSame(['part' => 'routes', 'id' => 'POST:api/posts', 'name' => 'POST api/posts', 'change' => 'added', 'severity' => 'additive', 'text' => '+ route POST api/posts', 'facts' => []], $json['changes'][0]);
    }

    #[Test]
    public function against_compares_with_another_snapshot(): void
    {
        $this->snapshot(function (array &$snapshot) {
            unset($snapshot['routes']['POST:api/posts']);
        });
        rename($this->file, $other = $this->directory.'/main.json');
        $this->snapshot();

        $this->assertStringContainsString('Nothing changed', $this->diff()[1]);
        $this->assertStringContainsString('+ route POST api/posts', $this->diff(['--against' => $other])[1]);
    }

    #[Test]
    public function no_snapshot_says_how_to_take_one(): void
    {
        [$code, $out] = $this->diff();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no snapshot at '.$this->file.'. Take one with: php artisan dissect:snapshot', $out);
    }

    #[Test]
    public function a_file_that_is_not_a_snapshot_is_refused(): void
    {
        file_put_contents($this->file, '{"positions": {}}');

        [$code, $out] = $this->diff();

        $this->assertSame(1, $code);
        $this->assertStringContainsString($this->file.' is not a dissect snapshot.', $out);
    }

    #[Test]
    public function a_snapshot_in_another_format_is_refused_rather_than_shown_as_everything_changed(): void
    {
        file_put_contents($this->file, '{"version": 99, "models": {}}');

        [$code, $out] = $this->diff();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is in snapshot format 99, and this version of dissect reads format '.Snapshot::VERSION, $out);
        $this->assertStringNotContainsString('+ route', $out);
    }

    #[Test]
    public function an_unknown_format_is_refused_with_the_choices(): void
    {
        [$code, $out] = $this->diff(['--format' => 'xml']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Pick one of: text, json, md.', $out);
    }

    #[Test]
    public function md_is_for_a_pull_request_comment_or_a_prompt(): void
    {
        $this->snapshot(function (array &$snapshot) {
            unset($snapshot['models']['Workbench\\App\\Models\\Post']['relations']['tags']);
            $snapshot['routes']['PUT|PATCH:api/posts/{post}']['name'] = 'posts.edit';
        });

        [$code, $out] = $this->diff(['--format' => 'md']);

        $this->assertSame(0, $code);
        $this->assertSame(implode("\n", [
            '# Changes since '.$this->file,
            '',
            '2 changes: 1 breaking, 1 additive.',
            '',
            '## Models',
            '',
            '- additive `~ model Post` · `Workbench\\App\\Models\\Post`',
            '  - additive `+ relation tags (MorphToMany Tag)`',
            '',
            '## Routes',
            '',
            '- **breaking** `~ route PUT|PATCH api/posts/{post}`',
            '  - **breaking** `~ name posts.edit → posts.update`',
            '',
        ]), $out);
    }

    #[Test]
    public function md_with_nothing_changed_says_so(): void
    {
        $this->snapshot();

        $this->assertSame("# Changes since {$this->file}\n\nNothing changed.\n", $this->diff(['--format' => 'md'])[1]);
    }

    #[Test]
    public function fail_on_exits_1_on_a_change_that_bad_or_worse(): void
    {
        // One additive change, one risky one.
        $this->snapshot(function (array &$snapshot) {
            unset($snapshot['routes']['POST:api/posts']);
            $snapshot['routes']['PUT|PATCH:api/posts/{post}']['middleware'][] = 'auth';
        });

        $this->assertSame(0, $this->diff()[0], 'without --fail-on, a diff is a report');
        $this->assertSame(0, $this->diff(['--fail-on' => 'breaking'])[0]);
        $this->assertSame(1, $this->diff(['--fail-on' => 'risky'])[0]);
        $this->assertSame(1, $this->diff(['--fail-on' => 'any'])[0]);
        $this->assertSame(1, $this->diff(['--fail-on' => 'any', '--format' => 'json'])[0]);

        $this->snapshot();

        $this->assertSame(0, $this->diff(['--fail-on' => 'any'])[0]);
    }

    #[Test]
    public function an_unknown_fail_on_is_refused_with_the_choices(): void
    {
        [$code, $out] = $this->diff(['--fail-on' => 'bad']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Pick one of: breaking, risky, any.', $out);
    }
}
