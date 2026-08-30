<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Events\CommerceEventReceived;
use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fires the cart.abandoned trigger if a checkout was not converted into an order
 * within the delay window. Idempotent: guarded by recovered_at / recovery_triggered_at.
 */
class CheckAbandonedCartJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $cartId) {}

    public function handle(): void
    {
        $cart = EcommerceCart::find($this->cartId);
        if (! $cart || $cart->recovered_at || $cart->recovery_triggered_at || ! $cart->contact_id) {
            return;
        }

        // If an order was placed by this contact after the cart was created, it converted.
        $converted = EcommerceOrder::where('store_id', $cart->store_id)
            ->where('contact_id', $cart->contact_id)
            ->where('placed_at', '>=', $cart->abandoned_at ?? $cart->created_at)
            ->exists();

        if ($converted) {
            $cart->update(['recovered_at' => now()]);

            return;
        }

        $cart->update(['recovery_triggered_at' => now()]);

        CommerceEventReceived::dispatch(
            $cart->workspace_id,
            $cart->contact_id,
            'cart.abandoned',
            [
                'cart_total' => (string) $cart->total,
                'order_currency' => (string) $cart->currency,
                'recovery_url' => (string) $cart->recovery_url,
            ],
        );
    }
}
