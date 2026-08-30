<?php

namespace App\Modules\Deployment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per deploy / rollback / command run from the admin panel.
 *
 * @property int $id
 * @property string|null $commit_hash
 * @property string|null $commit_short
 * @property string|null $branch
 * @property string|null $commit_message
 * @property string|null $commit_author
 * @property \Illuminate\Support\Carbon|null $commit_date
 * @property string $action
 * @property string $status
 * @property string|null $previous_commit
 * @property string|null $output
 * @property string|null $error_output
 * @property int|null $deployed_by
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DeploymentLog extends Model
{
    protected $table = 'deployment_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'commit_date' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function deployer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployed_by');
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'in_progress', 'started_at' => now()]);
    }

    /** @param array<string,string|null> $git */
    public function finish(string $status, ?string $output = null, ?string $error = null, array $git = []): void
    {
        $this->update(array_filter([
            'status' => $status,
            'output' => $output,
            'error_output' => $error,
            'commit_hash' => $git['commit_hash'] ?? $this->commit_hash,
            'commit_short' => $git['commit_short'] ?? $this->commit_short,
            'previous_commit' => $git['previous_commit'] ?? $this->previous_commit,
            'completed_at' => now(),
        ], fn ($v) => $v !== null));
    }
}
