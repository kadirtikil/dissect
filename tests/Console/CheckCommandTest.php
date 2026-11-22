<?php

namespace KdrDev\Dissect\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\DomainMap;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Tests\Concerns\UsesDomainFixture;
use KdrDev\Dissect\Console\CheckCommand;
use KdrDev\Dissect\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
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

    #[Test]
    public function files_narrows_what_is_reported_to_the_files_given(): void
    {
        // Customer breaks Sales's rule; Quote, in the same domain, does not.
        [$code, $out] = $this->check(['--files' => ['app/Domain/Sales/Models/Quote.php']]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No violations of 2 rules in the files given.', $out);

        [$code, $out] = $this->check(['--files' => ['app/Domain/Sales/Models/Quote.php,app/Domain/Sales/Models/Customer.php']]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Customer.php:19', $out);
    }

    #[Test]
    public function files_may_be_absolute_or_wander_through_dots(): void
    {
        $customer = $this->domainFixture().'/app/Domain/Sales/Models/Customer.php';

        $this->assertSame(1, $this->check(['--files' => [$customer]])[0]);
        $this->assertSame(1, $this->check(['--files' => ['./app/Domain/Sales/../Sales/Models/Customer.php']])[0]);
        // A file outside the app, or one just deleted, is simply not a violation.
        $this->assertSame(0, $this->check(['--files' => ['/tmp/elsewhere.php', 'app/Gone.php']])[0]);
    }

    #[Test]
    public function files_still_fails_on_a_broken_domains_json(): void
    {
        $this->domainsJson(['rules' => ['Sales' => ['may_uses' => []]]]);

        $this->assertSame(1, $this->check(['--files' => ['app/Domain/Sales/Models/Quote.php']])[0]);
    }

    #[Test]
    public function json_is_the_verdict_and_everything_behind_it(): void
    {
        [$code, $out] = $this->check(['--format' => 'json']);
        $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $code);
        $this->assertFalse($json['passed']);
        $this->assertSame(['Billing', 'Sales'], array_column($json['rules'], 'domain'));
        $this->assertSame(['app/Domain/Sales/Models/Customer.php'], array_column($json['violations'], 'file'));
        $this->assertSame([], $json['problems']);

        $this->domainsJson(['rules' => ['Sales' => ['may_use' => ['Billing']]]]);

        [$code, $out] = $this->check(['--format' => 'json']);

        $this->assertSame(0, $code);
        $this->assertTrue(json_decode($out, true)['passed']);
    }

    #[Test]
    public function github_writes_an_annotation_on_the_line(): void
    {
        [$code, $out] = $this->check(['--format' => 'github']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString(
            "::error file=app/Domain/Sales/Models/Customer.php,line=19,title=dissect%3A Sales may_use::Sales may not use Billing: Customer → Invoice (relation, line 19).\n",
            $out,
        );
    }

    #[Test]
    public function github_points_a_rules_problem_at_the_rules_file(): void
    {
        $this->domainsJson(['rules' => ['Sales' => ['may_uses' => []]]]);

        [, $out] = $this->check(['--format' => 'github']);

        $this->assertMatchesRegularExpression('/^::error file=[^,]+,title=dissect%3A domains\.json::rules\.Sales: unknown key "may_uses"/m', $out);
    }

    #[Test]
    public function an_unknown_format_is_refused_with_the_choices(): void
    {
        [$code, $out] = $this->check(['--format' => 'xml']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Pick one of: text, json, github.', $out);
    }

    /**
     * Runs the command the way Claude Code runs a hook: the event on stdin,
     * stdout and stderr apart.
     *
     * @return array{int, string, string}  Exit code, stdout, stderr.
     */
    protected function hook(array|string $event): array
    {
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, is_string($event) ? $event : json_encode($event));
        rewind($stdin);

        $input = new ArrayInput(['--hook' => true]);
        $input->setStream($stdin);

        $output = new class extends BufferedOutput implements ConsoleOutputInterface
        {
            public OutputInterface $stderr;

            public function getErrorOutput(): OutputInterface
            {
                return $this->stderr;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->stderr = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('No sections here.');
            }
        };
        $output->setErrorOutput($stderr = new BufferedOutput);

        $command = $this->app->make(CheckCommand::class);
        $command->setLaravel($this->app);
        $code = $command->run($input, $output);

        return [$code, $output->fetch(), $stderr->fetch()];
    }

    /** @return array<string, mixed> */
    protected function edit(string $path): array
    {
        return [
            'hook_event_name' => 'PostToolUse',
            'tool_name' => 'Edit',
            'tool_input' => ['file_path' => $path, 'old_string' => 'a', 'new_string' => 'b'],
            'cwd' => $this->domainFixture(),
        ];
    }

    #[Test]
    public function the_hook_tells_the_agent_what_its_edit_broke_on_stderr(): void
    {
        [$code, $stdout, $stderr] = $this->hook($this->edit($this->domainFixture().'/app/Domain/Sales/Models/Customer.php'));

        // 2 is what makes Claude Code show stderr to the agent.
        $this->assertSame(2, $code);
        $this->assertSame('', $stdout);
        $this->assertSame(<<<'TXT'
            dissect: this breaks a boundary rule in .dissect/domains.json:
            - `app/Domain/Sales/Models/Customer.php:19` Sales may not use Billing: Customer → Invoice (relation, line 19).

            The rule:
            - **Sales** may use no other domain

            Change the code so it keeps to the rule. The rule says where this team wants the boundary; only change .dissect/domains.json if you were asked to move it.

            TXT, $stderr);
    }

    #[Test]
    public function the_hook_is_silent_when_the_edit_keeps_to_the_rules(): void
    {
        $this->assertSame([0, '', ''], $this->hook($this->edit($this->domainFixture().'/app/Domain/Sales/Models/Quote.php')));
    }

    #[Test]
    public function the_hook_ignores_what_it_cannot_judge(): void
    {
        // Another file type, a file outside the app, an event with no file,
        // and something that is not an event at all.
        $this->assertSame([0, '', ''], $this->hook($this->edit($this->domainFixture().'/README.md')));
        $this->assertSame([0, '', ''], $this->hook($this->edit('/somewhere/else/Customer.php')));
        $this->assertSame([0, '', ''], $this->hook(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]));
        $this->assertSame([0, '', ''], $this->hook('not json'));
    }

    #[Test]
    public function the_hook_checks_the_whole_app_when_the_rules_are_edited(): void
    {
        [$code, , $stderr] = $this->hook($this->edit($this->domainFixture().'/.dissect/domains.json'));

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Customer.php:19', $stderr);
    }

    #[Test]
    public function the_hook_reports_a_broken_rules_file_whatever_was_edited(): void
    {
        $this->domainsJson(['rules' => ['Sales' => ['may_uses' => []]]]);

        [$code, , $stderr] = $this->hook($this->edit($this->domainFixture().'/app/Domain/Sales/Models/Quote.php'));

        $this->assertSame(2, $code);
        $this->assertStringContainsString("domains.json has a problem, so its boundary rules are not all checked:\n- rules.Sales: unknown key \"may_uses\"", $stderr);
    }
}
