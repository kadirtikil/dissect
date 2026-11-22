<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\DomainMap;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * dissect:check on the domain fixture. Its own domains.json breaks one rule;
 * the other tests swap in a rules file of their own.
 */
class CheckCommandTest extends TestCase
{
    use UsesDomainFixture;

    protected ?string $rulesFile = null;

    protected function tearDown(): void
    {
        if ($this->rulesFile !== null) {
            @unlink($this->rulesFile);
        }

        parent::tearDown();
    }

    /** Points dissect at a domains.json with these contents instead of the fixture's. */
    protected function domainsJson(string|array $contents): void
    {
        $this->rulesFile = tempnam(sys_get_temp_dir(), 'dissect-rules-');
        file_put_contents($this->rulesFile, is_string($contents) ? $contents : json_encode(['version' => 1, ...$contents]));

        config()->set('dissect.domains.file', $this->rulesFile);

        foreach ([DomainFile::class, ContextResolver::class, SourceFiles::class, DomainMap::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    /** @return array{int, string} */
    protected function check(array $options = []): array
    {
        $code = Artisan::call('dissect:check', $options);

        return [$code, Artisan::output()];
    }

    #[Test]
    public function a_violation_fails_and_says_where_it_is(): void
    {
        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString(
            'app/Domain/Sales/Models/Customer.php:19 Sales may not use Billing: Customer → Invoice (relation, line 19).',
            $out,
        );
        $this->assertStringContainsString('1 violation of 2 rules.', $out);
    }

    #[Test]
    public function rules_that_hold_pass(): void
    {
        $this->domainsJson(['rules' => ['Sales' => ['may_use' => ['Billing']], 'Billing' => ['must_not_use' => ['Illuminate\Http']]]]);

        [$code, $out] = $this->check();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No violations of 2 rules.', $out);
    }

    #[Test]
    public function no_rules_passes_and_says_there_was_nothing_to_check(): void
    {
        $this->domainsJson(['domains' => []]);

        [$code, $out] = $this->check();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No boundary rules in .dissect/domains.json, so there is nothing to check.', $out);
    }

    #[Test]
    public function a_broken_domains_json_fails_instead_of_passing_as_no_rules(): void
    {
        $this->domainsJson('{"rules": {');

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('domains.json domains.json is not valid JSON', $out);
        $this->assertStringContainsString('domains.json has a problem, so not every rule was checked.', $out);
    }

    #[Test]
    public function a_rule_naming_a_domain_nothing_defines_fails(): void
    {
        $this->domainsJson(['rules' => ['Sales' => ['may_use' => ['Biling', 'Billing']]]]);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('rules.Sales.may_use: no domain is called Biling.', $out);
    }

    #[Test]
    public function class_names_print_as_they_are(): void
    {
        $this->domainsJson(['rules' => ['Billing' => ['must_not_use' => ['Illuminate\Foundation\Bus']]]]);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Billing must not use Illuminate\Foundation\Bus: SendInvoice → Dispatchable (trait, line 12).', $out);
    }

    #[Test]
    public function domains_switched_off_passes(): void
    {
        config()->set('dissect.domains.enabled', false);
        $this->app->forgetInstance(ContextResolver::class);

        [$code, $out] = $this->check();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('switched off', $out);
    }
}
