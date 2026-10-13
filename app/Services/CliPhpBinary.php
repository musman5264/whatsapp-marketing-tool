<?php

namespace App\Services;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The command-line PHP binary, for starting artisan from a web request. PHP_BINARY is the web
 * handler's binary on many hosts (php-fpm, CGI), which cannot run scripts from the command line.
 * The finder only trusts PHP_BINARY inside the CLI; otherwise it looks for `php` on the path.
 */
final class CliPhpBinary
{
    public static function path(): string
    {
        $found = (new PhpExecutableFinder)->find(false);

        return $found !== false ? $found : PHP_BINARY;
    }
}
