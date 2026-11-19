<?php

namespace KdrDev\Dissect\Tests\Architecture;

use KdrDev\Dissect\Architecture\DomainFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DomainFileTest extends TestCase
{
    use TempTree;

    protected function file(mixed $contents, array $paths = []): DomainFile
    {
        $root = $this->tree([
            ...$paths,
            '.dissect/domains.json' => is_string($contents) ? $contents : json_encode($contents),
        ]);

        return new DomainFile($root.'/.dissect/domains.json', $root);
    }

    #[Test]
    public function no_file_means_no_domains_and_nothing_wrong(): void
    {
        $file = new DomainFile('/does/not/exist/domains.json', '/does/not/exist');

        $this->assertSame([], $file->contexts());
        $this->assertSame([], $file->problems());
        $this->assertTrue($file->conventions());
        $this->assertSame(0, $file->signal());
    }

    #[Test]
    public function it_maps_files_folders_and_globs_to_a_domain(): void
    {
        $file = $this->file(['version' => 1, 'domains' => [
            ['name' => 'Identity', 'paths' => ['app/Models/User.php', 'app/Http/Controllers/Auth', 'app/Policies/*']],
        ]], [
            'app/Models/User.php',
            'app/Http/Controllers/Auth/',
            'app/Policies/Users/',
            'app/Policies/Teams/',
        ]);

        [$identity] = $file->contexts();

        $this->assertSame('Identity', $identity->name);
        $this->assertSame(['config'], array_map(fn ($s) => $s->value, $identity->sources));
        $this->assertSame([
            $this->tree.'/app/Models/User.php',
            $this->tree.'/app/Http/Controllers/Auth',
            $this->tree.'/app/Policies/Teams',
            $this->tree.'/app/Policies/Users',
        ], $identity->roots);
        $this->assertSame([], $file->problems());
    }

    #[Test]
    public function the_same_name_twice_is_one_domain(): void
    {
        $file = $this->file(['domains' => [
            ['name' => 'Billing', 'paths' => ['a']],
            ['name' => 'Billing', 'paths' => ['b']],
        ]], ['a/', 'b/']);

        $this->assertCount(1, $file->contexts());
        $this->assertCount(2, $file->contexts()[0]->roots);
    }

    #[Test]
    public function conventions_can_be_switched_off(): void
    {
        $this->assertFalse($this->file(['conventions' => false])->conventions());
    }

    #[Test]
    public function a_path_that_matches_nothing_is_reported_and_the_rest_still_used(): void
    {
        $file = $this->file(['domains' => [['name' => 'Billing', 'paths' => ['gone', 'here']]]], ['here/']);

        $this->assertSame(['"Billing": gone matches nothing.'], $file->problems());
        $this->assertSame([$this->tree.'/here'], $file->contexts()[0]->roots);
    }

    #[Test]
    public function an_unreadable_file_is_reported_not_thrown(): void
    {
        $this->assertStringStartsWith('domains.json is not valid JSON', $this->file('{nope')->problems()[0]);
        $this->assertSame(['domains.json must hold an object.'], $this->file('"text"')->problems());
        $this->assertSame(
            ['domains.json is version 2; this version of dissect reads version 1.'],
            $this->file(['version' => 2])->problems(),
        );
        $this->assertSame(['"domains" must be a list.'], $this->file(['domains' => ['a' => []]])->problems());
    }

    #[Test]
    public function a_bad_entry_is_skipped_and_reported(): void
    {
        $file = $this->file(['conventions' => 'yes', 'domains' => [
            'Billing',
            ['name' => ''],
            ['name' => 'Sales', 'paths' => 'app'],
            ['name' => 'Ok', 'paths' => [42, 'app']],
        ]], ['app/']);

        $this->assertSame([
            '"conventions" must be true or false.',
            'domains[0] must be an object with a name and paths.',
            'domains[1] needs a name of 1 to 64 characters.',
            '"Sales": paths must be a list.',
            '"Ok": every path must be a non-empty string.',
        ], $file->problems());
        $this->assertSame(['Ok'], array_map(fn ($c) => $c->name, $file->contexts()));
    }
}
