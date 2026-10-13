<?php

namespace Tests\Feature\Deployment;

use App\Modules\Deployment\Models\DeploymentSetting;
use App\Modules\Deployment\Services\CommandAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommandAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DeploymentSetting::put('deploy_url', 'https://deploy.test/deploy.php');
        DeploymentSetting::put('deploy_key', 'k');
    }

    /** Commands that must not run without the second key: chained, substituted, redirected or unknown. */
    public static function refusedCommands(): array
    {
        return [
            'semicolon chain' => ['php artisan optimize:clear; echo INJECTED'],
            'and chain' => ['php artisan optimize:clear && echo INJECTED'],
            'pipe' => ['php artisan optimize:clear | echo INJECTED'],
            'backticks' => ['php artisan optimize:clear `echo INJECTED`'],
            'command substitution' => ['php artisan optimize:clear $(echo INJECTED)'],
            'redirect' => ['php artisan optimize:clear > injected.txt'],
            'newline' => ["php artisan optimize:clear\necho INJECTED"],
            'quotes' => ["php artisan optimize:clear 'x'"],
            'git with chain' => ['git status; echo INJECTED'],
            'longer artisan name' => ['php artisan optimize:clearx'],
            'destructive artisan name' => ['php artisan migrate:fresh --force'],
            'git branch delete' => ['git branch -D main'],
            'unknown command' => ['rm -rf /'],
        ];
    }

    /** Commands the Deployment page offers, plus plain artisan arguments. */
    public static function allowedCommands(): array
    {
        return [
            ['php artisan optimize:clear'],
            ['php artisan migrate --force'],
            ['php artisan migrate:status'],
            ['php artisan queue:restart'],
            ['php artisan storage:link'],
            ['php artisan reverb:launch'],
            ['git status'],
            ['git log --oneline -20'],
            ['composer install --no-dev --optimize-autoloader'],
        ];
    }

    #[Test]
    #[DataProvider('refusedCommands')]
    public function the_app_refuses_a_chained_or_unknown_command_without_the_second_key(string $command): void
    {
        Http::fake(); // must not be called

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.run-command'), ['command' => $command])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('refusedCommands')]
    public function the_app_forwards_a_chained_command_only_with_the_second_key(string $command): void
    {
        Http::fake(['deploy.test/*' => Http::response(['action' => 'cmd', 'ok' => true], 200)]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.run-command'), ['command' => $command, 'confirm_key' => 'second-key'])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'key2=second-key'));
    }

    #[Test]
    #[DataProvider('allowedCommands')]
    public function the_allowed_commands_run_without_the_second_key(string $command): void
    {
        Http::fake(['deploy.test/*' => Http::response(['action' => 'cmd', 'ok' => true], 200)]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.run-command'), ['command' => $command])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'key2='));
    }

    #[Test]
    public function the_app_and_the_remote_script_share_one_allowlist(): void
    {
        $script = (string) file_get_contents(base_path('public/deploy.php'));

        $exact = $this->listIn($script, '$allowedExact');
        $artisan = $this->listIn($script, '$allowedArtisan');

        sort($exact);
        sort($artisan);
        $appExact = CommandAllowlist::EXACT;
        $appArtisan = CommandAllowlist::ARTISAN;
        sort($appExact);
        sort($appArtisan);

        $this->assertSame($appExact, $exact);
        $this->assertSame($appArtisan, $artisan);
    }

    #[Test]
    public function the_remote_script_has_no_built_in_deploy_key(): void
    {
        $script = (string) file_get_contents(base_path('public/deploy.php'));

        $this->assertStringNotContainsString('esystematicTechnologies', $script);
    }

    #[Test]
    public function the_repo_docs_do_not_show_the_deploy_key_literal(): void
    {
        $doc = (string) file_get_contents(base_path('WAHA-EXPANSION-PLAN.md'));

        $this->assertStringNotContainsString('esystematicTechnologies', $doc);
    }

    #[Test]
    #[DataProvider('refusedCommands')]
    public function the_remote_script_refuses_a_chained_command_without_the_second_key(string $command): void
    {
        $output = $this->runRemoteScript(['DEPLOY_KEY' => 'test-key'], [
            'key' => 'test-key', 'action' => 'cmd', 'command' => $command,
        ]);

        $this->assertStringContainsString('not on the allowlist', $output);
        $this->assertStringNotContainsString('INJECTED', $output);
    }

    #[Test]
    public function the_remote_script_refuses_every_request_when_no_key_is_configured(): void
    {
        // The built-in string must not open the script when the config has no key.
        $output = $this->runRemoteScript([], [
            'key' => 'esystematicTechnologies', 'action' => 'probe',
        ]);

        $this->assertStringContainsString('not configured', $output);
    }

    /** @return list<string> */
    private function listIn(string $script, string $variable): array
    {
        $pattern = '/'.preg_quote($variable, '/').' = \[(.*?)\];/s';
        $this->assertSame(1, preg_match($pattern, $script, $match), $variable.' is missing from deploy.php');
        preg_match_all("/'([^']*)'/", $match[1], $items);

        return $items[1];
    }

    /**
     * Runs a copy of public/deploy.php from a temp folder with its own config, so nothing real is
     * touched. Refused commands never reach the shell, so this only checks the refusal path.
     */
    private function runRemoteScript(array $config, array $query): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-test-'.uniqid();
        File::ensureDirectoryExists($root.'/public');
        File::copy(base_path('public/deploy.php'), $root.'/public/deploy.php');
        File::put($root.'/public/deploy.config.php', '<?php return '.var_export($config, true).';');
        // A different client address per run keeps the script's per-IP rate limit out of the way.
        $ip = '10.'.random_int(1, 250).'.'.random_int(0, 255).'.'.random_int(1, 254);
        File::put($root.'/prepend.php', '<?php $_GET = json_decode((string) getenv("DEPLOY_TEST_GET"), true) ?: []; '
            .'$_SERVER["REMOTE_ADDR"] = "'.$ip.'";');

        try {
            return Process::env(['DEPLOY_TEST_GET' => json_encode($query)])
                ->timeout(60)
                ->run([PHP_BINARY, '-d', 'auto_prepend_file='.$root.'/prepend.php', $root.'/public/deploy.php'])
                ->output();
        } finally {
            File::deleteDirectory($root);
        }
    }
}
