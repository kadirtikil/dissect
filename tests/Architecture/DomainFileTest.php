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

    #[Test]
    public function it_reads_each_domains_rule(): void
    {
        $file = $this->file(['rules' => [
            'Sales' => ['may_use' => ['Billing', 'Billing'], 'must_not_use' => ['\\Illuminate\\Http', 'App\\Support\\Clock']],
            'Billing' => ['must_not_use' => ['Illuminate\\Http']],
            'Identity' => ['may_use' => []],
        ]]);

        $rules = $file->rules();

        // Keyed and sorted by domain.
        $this->assertSame(['Billing', 'Identity', 'Sales'], array_keys($rules));
        $this->assertSame(
            ['domain' => 'Sales', 'may_use' => ['Billing'], 'must_not_use' => ['Illuminate\\Http', 'App\\Support\\Clock']],
            $rules['Sales']->toArray(),
        );

        // No may_use: free to use any domain. An empty one: free to use none.
        $this->assertNull($rules['Billing']->mayUse);
        $this->assertSame([], $rules['Identity']->mayUse);
        $this->assertSame([], $file->problems());
    }

    #[Test]
    public function a_rule_allows_its_own_domain_and_its_list_and_forbids_by_namespace(): void
    {
        $rule = $this->file(['rules' => ['Sales' => ['may_use' => ['Billing'], 'must_not_use' => ['Illuminate\\Http']]]])->rules()['Sales'];

        $this->assertTrue($rule->allows('Sales'));
        $this->assertTrue($rule->allows('Billing'));
        $this->assertFalse($rule->allows('Identity'));

        $this->assertSame('Illuminate\\Http', $rule->forbids('Illuminate\\Http\\Request'));
        $this->assertSame('Illuminate\\Http', $rule->forbids('Illuminate\\Http'));
        // A namespace is a whole segment, not a prefix of a name.
        $this->assertNull($rule->forbids('Illuminate\\HttpClient\\Factory'));
        $this->assertNull($rule->forbids('Illuminate\\Support\\Collection'));
    }

    #[Test]
    public function a_mistyped_rule_is_reported_and_never_silently_stricter(): void
    {
        $this->assertSame(['"rules" must be an object keyed by domain name.'], $this->file(['rules' => ['Sales']])->problems());

        $file = $this->file(['rules' => [
            'Sales' => ['may_uses' => ['Billing']],
            'Billing' => ['may_use' => 'Sales', 'must_not_use' => ['Illuminate\\Http', 42]],
            'Identity' => 'none',
        ]]);

        $this->assertSame([
            'rules.Sales: unknown key "may_uses"; a rule has may_use and must_not_use.',
            'rules.Billing.may_use must be a list of names.',
            'rules.Billing.must_not_use: every entry must be a non-empty string.',
            'rules.Identity must be an object with may_use and/or must_not_use.',
        ], $file->problems());

        // A may_use that cannot be read leaves the domain unrestricted rather
        // than forbidding everything; what could be read still applies.
        $this->assertNull($file->rules()['Billing']->mayUse);
        $this->assertSame(['Illuminate\\Http'], $file->rules()['Billing']->mustNotUse);
        $this->assertNull($file->rules()['Sales']->mayUse);
        $this->assertArrayNotHasKey('Identity', $file->rules());
    }
}
