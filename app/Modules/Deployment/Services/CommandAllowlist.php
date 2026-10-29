<?php

namespace App\Modules\Deployment\Services;

/**
 * Commands the Deployment page runs without the second key. public/deploy.php applies the same
 * rule with the same lists (CommandAllowlistTest fails if they drift). Anything refused here needs
 * the second key, and the remote script checks it again.
 */
final class CommandAllowlist
{
    /** Commands that must match exactly. */
    public const EXACT = [
        'git status', 'git log', 'git log --oneline -20', 'git branch',
        'composer install --no-dev', 'composer install --no-dev --optimize-autoloader',
    ];

    /** `php artisan <name>` runs with any plain arguments. */
    public const ARTISAN = [
        'optimize:clear', 'migrate', 'migrate:status', 'config:clear', 'cache:clear', 'queue:restart',
        'storage:link', 'whatsapp-web:status', 'whatsapp-web:resubscribe', 'about', 'reverb:launch', 'queue:failed',
    ];

    public static function allows(string $command): bool
    {
        $command = trim($command);

        // Letters, digits, space and _ . : = / - only. Shell characters, quotes and newlines are
        // refused, so nothing can be chained onto an allowed command.
        if (preg_match('/^[A-Za-z0-9 _.:=\/-]+\z/', $command) !== 1) {
            return false;
        }

        if (in_array($command, self::EXACT, true)) {
            return true;
        }

        if (preg_match('/^php artisan ([a-z0-9:_-]+)(?: .*)?\z/', $command, $match) === 1) {
            return in_array($match[1], self::ARTISAN, true);
        }

        return false;
    }
}
