<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DbBackupCommand extends Command
{
    protected $signature   = 'db:backup {--disk=local : Storage disk to upload the backup to} {--no-upload : Keep backup local only}';
    protected $description = 'Dump the MySQL database and optionally upload to a storage disk.';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("db:backup currently only supports MySQL (configured: {$connection}).");
            return self::FAILURE;
        }

        $cfg  = config('database.connections.mysql');
        $host = $cfg['host'];
        $port = $cfg['port'];
        $db   = $cfg['database'];
        $user = $cfg['username'];
        $pass = $cfg['password'];

        $timestamp = now()->format('Y_m_d_His');
        $filename  = "{$db}_{$timestamp}.sql.gz";
        $tmpPath   = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;

        $this->info("Backing up database `{$db}` to {$filename}…");

        $env  = "MYSQL_PWD={$pass}";
        $cmd  = "{$env} mysqldump --host={$host} --port={$port} --user={$user} {$db} | gzip > ".escapeshellarg($tmpPath);

        $output = null;
        $return = null;
        exec($cmd, $output, $return);

        if ($return !== 0 || ! file_exists($tmpPath)) {
            $this->error('mysqldump failed. Make sure mysqldump is in PATH and credentials are correct.');
            return self::FAILURE;
        }

        $sizeMb = round(filesize($tmpPath) / 1_048_576, 2);
        $this->info("Dump created: {$tmpPath} ({$sizeMb} MB)");

        if (! $this->option('no-upload')) {
            $disk = $this->option('disk');
            $this->info("Uploading to disk `{$disk}`…");

            Storage::disk($disk)->put("backups/{$filename}", file_get_contents($tmpPath));
            $this->info('Upload complete: backups/'.$filename);
        }

        @unlink($tmpPath);

        $this->info('✅  Backup finished.');
        return self::SUCCESS;
    }
}
