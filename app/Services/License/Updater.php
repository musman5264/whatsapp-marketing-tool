<?php

namespace App\Services\License;

use App\Services\Install\EnvWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a product update fetched from the License Manager: downloads the
 * package, extracts it over the application (never touching .env / storage /
 * .git / node_modules), imports any SQL, runs migrations, bumps APP_VERSION and
 * clears caches. The app is put into maintenance mode during the swap and is
 * always brought back up, even on failure.
 *
 * Overwriting application files cannot be undone automatically — run this on a
 * staging copy first and keep a backup of production.
 */
class Updater
{
    /** Top-level paths that must never be overwritten by an update package. */
    private const PROTECTED_PATHS = ['.env', '.git', 'storage', 'node_modules'];

    public function __construct(
        private LicenseManager $license,
        private EnvWriter $env,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function apply(): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        if (! class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'The PHP zip extension is required to apply updates.'];
        }

        $check = $this->license->checkUpdate();
        if (! ($check['ok'] && $check['update_available'])) {
            return ['ok' => false, 'message' => $check['message'] ?: 'No update is available.'];
        }

        $versionId = (string) ($check['update_id'] ?: $check['version'] ?: '');
        $newVersion = (string) ($check['version'] ?: '');
        if ($versionId === '') {
            return ['ok' => false, 'message' => 'The update has no version identifier.'];
        }

        if (! is_writable(base_path())) {
            return ['ok' => false, 'message' => 'The application directory is not writable, so the update cannot be applied automatically.'];
        }

        $work = storage_path('app/updates');
        @mkdir($work, 0775, true);
        $slug = preg_replace('/[^A-Za-z0-9._-]/', '_', $versionId);
        $zipPath = "$work/update-$slug.zip";
        $extractDir = "$work/extract-$slug";
        $this->rrmdir($extractDir);

        Artisan::call('down', ['--retry' => 30]);

        try {
            // 1. Download the main package.
            $dl = $this->license->downloadUpdate($versionId, 'main', $zipPath);
            if (! $dl['ok']) {
                return ['ok' => false, 'message' => $dl['message']];
            }

            // 2. Extract and copy over the app (protected paths untouched).
            if (! $this->extract($zipPath, $extractDir)) {
                return ['ok' => false, 'message' => 'The downloaded update package is not a valid zip archive.'];
            }
            $this->copyTree($this->sourceRoot($extractDir), base_path(), self::PROTECTED_PATHS);

            // 3. Apply SQL changes if the release ships them.
            if (! empty($check['has_sql'])) {
                $sqlPath = "$work/update-$slug.sql";
                $sqlDl = $this->license->downloadUpdate($versionId, 'sql', $sqlPath);
                if ($sqlDl['ok']) {
                    $this->importSql($sqlPath);
                    @unlink($sqlPath);
                }
            }

            // 4. Run migrations shipped with the new code.
            Artisan::call('migrate', ['--force' => true]);

            // 5. Record the new version and refresh caches.
            if ($newVersion !== '') {
                $this->env->set(['APP_VERSION' => $newVersion]);
            }
            Artisan::call('optimize:clear');

            return ['ok' => true, 'message' => 'Updated successfully'.($newVersion !== '' ? " to v{$newVersion}" : '').'.'];
        } catch (\Throwable $e) {
            Log::error('License update failed: '.$e->getMessage(), ['exception' => $e]);

            return ['ok' => false, 'message' => 'Update failed: '.$e->getMessage()];
        } finally {
            Artisan::call('up');
            @unlink($zipPath);
            $this->rrmdir($extractDir);
        }
    }

    private function extract(string $zipPath, string $to): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        @mkdir($to, 0775, true);
        $ok = $zip->extractTo($to);
        $zip->close();

        return $ok;
    }

    /**
     * Some packages wrap everything in a single top-level folder; descend into
     * it so files land at the project root rather than inside a subfolder.
     */
    private function sourceRoot(string $extractDir): string
    {
        $entries = array_values(array_filter(
            scandir($extractDir) ?: [],
            fn ($e) => $e !== '.' && $e !== '..'
        ));

        if (count($entries) === 1 && is_dir($extractDir.'/'.$entries[0])) {
            return $extractDir.'/'.$entries[0];
        }

        return $extractDir;
    }

    /**
     * Recursively copy $src into $dst. $skipTop names are skipped at the top
     * level only (so a nested "storage" inside a package folder is still copied).
     *
     * @param  list<string>  $skipTop
     */
    private function copyTree(string $src, string $dst, array $skipTop = []): void
    {
        foreach (new \FilesystemIterator($src, \FilesystemIterator::SKIP_DOTS) as $item) {
            /** @var \SplFileInfo $item */
            if (in_array($item->getFilename(), $skipTop, true)) {
                continue;
            }

            $target = $dst.DIRECTORY_SEPARATOR.$item->getFilename();

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
                $this->copyTree($item->getPathname(), $target);
            } else {
                @copy($item->getPathname(), $target);
            }
        }
    }

    private function importSql(string $sqlPath): void
    {
        $sql = trim((string) @file_get_contents($sqlPath));
        if ($sql !== '') {
            DB::unprepared($sql);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? $this->rrmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
