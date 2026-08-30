<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Client extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'status',
        'base_currency',
        'currency_symbol',
        'currency_position',
        'logo_path',
        'logo_disk',
        'primary_color',
        'tagline',
        'custom_domain',
        'support_email',
    ];

    public function logoUrl(): ?string
    {
        if (empty($this->logo_path)) {
            return null;
        }

        $disk = $this->logo_disk ?? 'public';

        return Storage::disk($disk)->url($this->logo_path);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(ClientSubscription::class)->where('status', 'active')->latestOfMany();
    }

    public function activePlan(): ?Plan
    {
        return $this->activeSubscription?->plan;
    }

    /**
     * The plan actually in effect for this client, mirroring
     * User::effectiveSubscription(): an admin-assigned ClientSubscription takes
     * precedence, otherwise fall back to the plan from any of the client's users'
     * active/trialing Subscriptions (e.g. a self-serve Stripe plan). The admin client
     * list must use this — not just activeSubscription — otherwise clients on a normal
     * user Subscription show as "No Plan" even though their dashboard shows the plan.
     */
    public function effectivePlan(): ?Plan
    {
        if ($this->activeSubscription?->plan) {
            return $this->activeSubscription->plan;
        }

        $sub = Subscription::whereIn('user_id', $this->users()->select('id'))
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        return $sub?->plan;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
