<?php

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the standalone public/deploy.php by invoking it as a subprocess with
 * a simulated $_GET (it never boots Laravel).
 */
class DeployScriptTest extends TestCase
{
    private string $script;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = base_path('public/deploy.php');
        $this->tmpDir = sys_get_temp_dir().'/deploy_script_test_'.uniqid();
        mkdir($this->tmpDir.'/public', 0777, true);
        copy($this->script, $this->tmpDir.'/public/deploy.php');
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->tmpDir.'/public/*') ?: []);
        @rmdir($this->tmpDir.'/public');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function invoke(array $get): array
    {
        $php = PHP_BINARY;
        $bootstrap = tempnam(sys_get_temp_dir(), 'dbs').'.php';
        $getExport = var_export($get, true);
        file_put_contents($bootstrap, "<?php \$_GET = {$getExport}; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; require '".addslashes($this->tmpDir.'/public/deploy.php')."';");

        $out = shell_exec(escapeshellarg($php).' '.escapeshellarg($bootstrap).' 2>&1');
        @unlink($bootstrap);

        return ['raw' => $out, 'json' => json_decode((string) $out, true)];
    }

    #[Test]
    public function without_a_config_file_it_returns_503(): void
    {
        $r = $this->invoke(['key' => 'x', 'action' => 'status']);
        $this->assertIsArray($r['json']);
        $this->assertSame('not configured', $r['json']['error']);
    }

    #[Test]
    public function with_a_config_but_wrong_key_it_returns_403(): void
    {
        file_put_contents(
            $this->tmpDir.'/public/deploy.config.php',
            "<?php return ['DEPLOY_KEY' => 'right-key', 'REPO_URL_SSH' => '', 'REPO_URL_HTTPS' => '', 'BRANCH' => 'main'];"
        );

        $r = $this->invoke(['key' => 'wrong-key', 'action' => 'status']);
        $this->assertSame('invalid deploy key', $r['json']['error'] ?? null);
    }

    #[Test]
    public function status_on_a_non_git_dir_reports_no_repo(): void
    {
        file_put_contents(
            $this->tmpDir.'/public/deploy.config.php',
            "<?php return ['DEPLOY_KEY' => 'right-key', 'REPO_URL_SSH' => '', 'REPO_URL_HTTPS' => '', 'BRANCH' => 'main'];"
        );

        $r = $this->invoke(['key' => 'right-key', 'action' => 'status']);
        $this->assertTrue($r['json']['ok'] ?? false);
        $this->assertFalse($r['json']['git_initialized']);
    }

    #[Test]
    public function unknown_action_is_rejected(): void
    {
        file_put_contents(
            $this->tmpDir.'/public/deploy.config.php',
            "<?php return ['DEPLOY_KEY' => 'right-key', 'REPO_URL_SSH' => '', 'REPO_URL_HTTPS' => '', 'BRANCH' => 'main'];"
        );

        $r = $this->invoke(['key' => 'right-key', 'action' => 'evil']);
        $this->assertStringContainsString('unknown action', strtolower((string) ($r['json']['error'] ?? '')));
    }
}
