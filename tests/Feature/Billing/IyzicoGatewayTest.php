<?php

namespace Tests\Feature\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the iyzico Subscription API integration. The two things most likely to break it
 * silently are the IYZWSv2 request signature and the X-IYZ-SIGNATURE-V3 webhook signature —
 * both are byte-exact algorithms with no useful error feedback — so they are asserted
 * against independently recomputed expectations rather than against the implementation.
 */
class IyzicoGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sandbox-apikey';

    private const SECRET = 'sandbox-secret';

    private const MERCHANT_ID = '1234567';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.iyzico' => [
                'enabled' => true,
                'api_key' => self::API_KEY,
                'secret_key' => self::SECRET,
                'merchant_id' => self::MERCHANT_ID,
                'sandbox' => true,
                'customer_defaults' => [
                    'gsm_number' => '+905350000000',
                    'identity_number' => '11111111111',
                    'address' => 'Not collected',
                    'city' => 'Istanbul',
                    'country' => 'Turkey',
                ],
                'success_url' => 'http://localhost/app/billing?checkout=success',
                'cancel_url' => 'http://localhost/app/pricing?checkout=canceled',
            ],
        ]);

        Storage::fake('local');
    }

    private function gateway(): BillingGatewayInterface
    {
        // A fresh registry per call — it reads config in its constructor.
        return (new BillingGatewayRegistry)->get('iyzico');
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'monthly_price_cents' => 19900,
            'yearly_price_cents' => 199000,
            'currency_code' => 'TRY',
            'trial_days' => 0,
        ], $overrides));
    }

    /** Successful iyzico responses for the product → pricing plan → initialize chain. */
    private function fakeCheckoutChain(): void
    {
        Http::fake([
            '*/v2/subscription/products' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PROD-1']]),
            '*/pricing-plans' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PLAN-1']]),
            '*/v2/subscription/checkoutform/initialize' => Http::response([
                'status' => 'success',
                'token' => 'TOKEN-1',
                'checkoutFormContent' => '<div id="iyzipay-checkout-form"></div>',
                'tokenExpireTime' => 1800,
            ]),
        ]);
    }

    // ─── Request signing ────────────────────────────────────────────────────

    public function test_requests_are_signed_with_iyzwsv2_over_the_path_and_the_exact_body_bytes(): void
    {
        $this->fakeCheckoutChain();
        $user = User::factory()->create(['role' => 'client', 'name' => 'Ada Lovelace']);

        $this->gateway()->createCheckout($user, $this->plan(), 'month');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/subscription/checkoutform/initialize')) {
                return false;
            }

            $header = $request->header('Authorization')[0];
            $this->assertStringStartsWith('IYZWSv2 ', $header);

            // Recompute the signature the way iyzico documents it, using the random key the
            // request actually carried and the body bytes actually transmitted.
            $decoded = base64_decode(substr($header, 8));
            $parts = [];
            foreach (explode('&', $decoded) as $pair) {
                [$k, $v] = explode(':', $pair, 2);
                $parts[$k] = $v;
            }

            $this->assertSame(self::API_KEY, $parts['apiKey']);

            $expected = bin2hex(hash_hmac(
                'sha256',
                $parts['randomKey'].'/v2/subscription/checkoutform/initialize'.$request->body(),
                self::SECRET,
                true
            ));

            $this->assertSame($expected, $parts['signature'], 'IYZWSv2 signature must cover randomKey + path + exact body bytes.');
            $this->assertSame($parts['randomKey'], $request->header('x-iyzi-rnd')[0]);

            return true;
        });
    }

    public function test_get_requests_are_signed_over_the_path_alone_and_send_no_body(): void
    {
        Http::fake(['*/v2/subscription/checkoutform/TOKEN-1' => Http::response(['status' => 'failure', 'errorMessage' => 'nope'])]);

        DB::table('iyzico_checkout_sessions')->insert([
            'token' => 'TOKEN-1',
            'user_id' => User::factory()->create(['role' => 'client'])->id,
            'plan_id' => $this->plan()->id,
            'billing_cycle' => 'month',
            'pricing_plan_reference_code' => 'PLAN-1',
            'checkout_form_content' => '<div></div>',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->gateway()->fulfillCheckoutSession('TOKEN-1');

        Http::assertSent(function ($request) {
            $decoded = base64_decode(substr($request->header('Authorization')[0], 8));
            preg_match('/randomKey:([^&]+)/', $decoded, $rnd);
            preg_match('/signature:(.+)$/', $decoded, $sig);

            $expected = bin2hex(hash_hmac('sha256', $rnd[1].'/v2/subscription/checkoutform/TOKEN-1', self::SECRET, true));

            $this->assertSame('', $request->body(), 'GET requests must not carry a body.');
            $this->assertSame($expected, $sig[1]);

            return true;
        });
    }

    // ─── Checkout ───────────────────────────────────────────────────────────

    public function test_checkout_provisions_a_pricing_plan_and_returns_our_own_hosted_form_url(): void
    {
        $this->fakeCheckoutChain();
        $user = User::factory()->create(['role' => 'client', 'name' => 'Ada Lovelace']);
        $plan = $this->plan();

        $result = $this->gateway()->createCheckout($user, $plan, 'month');

        // iyzico returns embedded markup, so the URL must point back at us, not at iyzico.
        $this->assertSame(route('checkout.iyzico.form', ['token' => 'TOKEN-1']), $result['url'] ?? null);

        $this->assertDatabaseHas('iyzico_pricing_plans', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'month',
            'mode' => 'test',
            'pricing_plan_reference_code' => 'PLAN-1',
        ]);

        $session = DB::table('iyzico_checkout_sessions')->where('token', 'TOKEN-1')->first();
        $this->assertSame($user->id, (int) $session->user_id);
        $this->assertSame($plan->id, (int) $session->plan_id);
        $this->assertStringContainsString('iyzipay-checkout-form', $session->checkout_form_content);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pricing-plans')
            && $request['price'] === '199.00'
            && $request['currencyCode'] === 'TRY'
            && $request['paymentInterval'] === 'MONTHLY'
            && $request['planPaymentType'] === 'RECURRING');
    }

    public function test_a_second_checkout_for_the_same_price_reuses_the_pricing_plan(): void
    {
        $this->fakeCheckoutChain();
        $user = User::factory()->create(['role' => 'client']);
        $plan = $this->plan();

        $this->gateway()->createCheckout($user, $plan, 'month');
        $this->gateway()->createCheckout($user, $plan, 'month');

        $this->assertSame(1, DB::table('iyzico_pricing_plans')->count());
        Http::assertSentCount(4); // product + plan + 2 × initialize
    }

    public function test_a_price_change_provisions_a_new_pricing_plan_because_iyzico_plans_are_immutable(): void
    {
        $this->fakeCheckoutChain();
        $user = User::factory()->create(['role' => 'client']);
        $plan = $this->plan();

        $this->gateway()->createCheckout($user, $plan, 'month');
        $plan->update(['monthly_price_cents' => 24900]);
        $this->gateway()->createCheckout($user, $plan->fresh(), 'month');

        $this->assertSame(2, DB::table('iyzico_pricing_plans')->count());
    }

    public function test_checkout_is_refused_for_a_currency_iyzico_subscriptions_do_not_support(): void
    {
        Http::fake();
        $user = User::factory()->create(['role' => 'client']);

        $result = $this->gateway()->createCheckout($user, $this->plan(['currency_code' => 'GBP']), 'month');

        $this->assertStringContainsString('TRY, USD and EUR', $result['error'] ?? '');
        Http::assertNothingSent();
    }

    public function test_a_body_level_failure_is_an_error_even_though_iyzico_answers_with_http_200(): void
    {
        Http::fake([
            '*/v2/subscription/products' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PROD-1']]),
            '*/pricing-plans' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PLAN-1']]),
            '*/v2/subscription/checkoutform/initialize' => Http::response([
                'status' => 'failure',
                'errorCode' => '5001',
                'errorMessage' => 'Geçersiz istek',
            ], 200),
        ]);

        $result = $this->gateway()->createCheckout(User::factory()->create(['role' => 'client']), $this->plan(), 'month');

        $this->assertSame('Geçersiz istek', $result['error'] ?? null);
    }

    // ─── Hosted form page ───────────────────────────────────────────────────

    private function seedSession(User $user, Plan $plan, array $overrides = []): void
    {
        DB::table('iyzico_checkout_sessions')->insert(array_merge([
            'token' => 'TOKEN-1',
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'month',
            'pricing_plan_reference_code' => 'PLAN-1',
            'checkout_form_content' => '<div id="iyzipay-checkout-form"></div>',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_the_form_page_renders_the_stored_iyzico_markup_for_its_owner(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->seedSession($user, $this->plan());

        $response = $this->actingAs($user)->get(route('checkout.iyzico.form', ['token' => 'TOKEN-1']));

        $response->assertOk();
        $response->assertSee('iyzipay-checkout-form', false);
        // The embedded form loads scripts and 3-D Secure frames from iyzipay.com.
        $this->assertStringContainsString('https://*.iyzipay.com', $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_form_page_is_not_readable_by_another_user(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $stranger = User::factory()->create(['role' => 'client']);
        $this->seedSession($owner, $this->plan());

        $this->actingAs($stranger)
            ->get(route('checkout.iyzico.form', ['token' => 'TOKEN-1']))
            ->assertForbidden();
    }

    public function test_an_expired_checkout_link_is_gone(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->seedSession($user, $this->plan(), ['expires_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->get(route('checkout.iyzico.form', ['token' => 'TOKEN-1']))
            ->assertStatus(410);
    }

    // ─── Callback fulfilment ────────────────────────────────────────────────

    public function test_the_callback_turns_a_paid_token_into_an_active_subscription(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $plan = $this->plan();
        $this->seedSession($user, $plan);

        Http::fake([
            '*/v2/subscription/checkoutform/TOKEN-1' => Http::response([
                'status' => 'success',
                'data' => [
                    'referenceCode' => 'SUB-1',
                    'customerReferenceCode' => 'CUST-1',
                    'pricingPlanReferenceCode' => 'PLAN-1',
                    'subscriptionStatus' => 'ACTIVE',
                    'trialDays' => 0,
                ],
            ]),
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => []],
            ]),
        ]);

        // The callback is a cross-site POST from iyzico's page — no session, no CSRF token.
        $response = $this->post('/billing/iyzico/callback', ['token' => 'TOKEN-1']);

        $response->assertRedirect(config('billing.gateways.iyzico.success_url'));
        $this->assertDatabaseHas('subscriptions', [
            'gateway' => 'iyzico',
            'gateway_subscription_id' => 'SUB-1',
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->assertNotNull(DB::table('iyzico_checkout_sessions')->where('token', 'TOKEN-1')->value('consumed_at'));
    }

    public function test_the_callback_backfills_a_first_payment_whose_webhook_arrived_too_early(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $plan = $this->plan();
        $this->seedSession($user, $plan);

        Http::fake([
            '*/v2/subscription/checkoutform/TOKEN-1' => Http::response([
                'status' => 'success',
                'data' => ['referenceCode' => 'SUB-1', 'subscriptionStatus' => 'ACTIVE'],
            ]),
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => [[
                    'referenceCode' => 'ORDER-1',
                    'price' => '199.00',
                    'currencyCode' => 'TRY',
                    'orderStatus' => 'SUCCESS',
                    'endPeriod' => now()->addMonth()->getTimestampMs(),
                    'paymentAttempts' => [['paymentStatus' => 'SUCCESS', 'paymentId' => 987654]],
                ]]],
            ]),
        ]);

        $this->post('/billing/iyzico/callback', ['token' => 'TOKEN-1'])->assertRedirect();

        $transaction = PaymentTransaction::where('gateway', 'iyzico')->first();
        $this->assertNotNull($transaction);
        $this->assertSame(19900, $transaction->amount_cents);
        $this->assertSame('ORDER-1', $transaction->gateway_transaction_id);
        // The payment id is the only handle a later refund has.
        $this->assertSame('987654', $transaction->payload['payment_id']);
    }

    // ─── Webhooks ───────────────────────────────────────────────────────────

    private function signWebhook(array $payload): string
    {
        $message = self::MERCHANT_ID
            .self::SECRET
            .($payload['iyziEventType'] ?? '')
            .($payload['subscriptionReferenceCode'] ?? '')
            .($payload['orderReferenceCode'] ?? '')
            .($payload['customerReferenceCode'] ?? '');

        return bin2hex(hash_hmac('sha256', $message, self::SECRET, true));
    }

    private function makeSubscription(User $user, Plan $plan): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'iyzico',
            'gateway_subscription_id' => 'SUB-1',
            'starts_at' => now()->subMonth(),
            'renews_at' => now(),
        ]);
    }

    public function test_a_signed_order_success_webhook_records_the_renewal(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        Http::fake([
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => [[
                    'referenceCode' => 'ORDER-2',
                    'price' => '199.00',
                    'currencyCode' => 'TRY',
                    'orderStatus' => 'SUCCESS',
                    'endPeriod' => now()->addMonth()->getTimestampMs(),
                    'paymentAttempts' => [['paymentStatus' => 'SUCCESS', 'paymentId' => 111]],
                ]]],
            ]),
        ]);

        $payload = [
            'iyziEventType' => 'subscription.order.success',
            'subscriptionReferenceCode' => 'SUB-1',
            'orderReferenceCode' => 'ORDER-2',
            'customerReferenceCode' => 'CUST-1',
            'iyziReferenceCode' => 'EVENT-1',
        ];

        $this->withHeaders(['X-IYZ-SIGNATURE-V3' => $this->signWebhook($payload)])
            ->postJson('/webhooks/iyzico', $payload)
            ->assertOk();

        $this->assertDatabaseHas('payment_transactions', [
            'gateway' => 'iyzico',
            'gateway_transaction_id' => 'ORDER-2',
            'amount_cents' => 19900,
            'status' => 'paid',
        ]);
        $this->assertTrue($subscription->fresh()->renews_at->isFuture());
    }

    public function test_the_signup_charge_announces_a_start_and_not_a_renewal(): void
    {
        Event::fake([SubscriptionStarted::class, SubscriptionRenewed::class]);

        $user = User::factory()->create(['role' => 'client']);
        $this->seedSession($user, $this->plan());

        Http::fake([
            '*/v2/subscription/checkoutform/TOKEN-1' => Http::response([
                'status' => 'success',
                'data' => ['referenceCode' => 'SUB-1', 'subscriptionStatus' => 'ACTIVE'],
            ]),
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => [[
                    'referenceCode' => 'ORDER-1',
                    'price' => '199.00',
                    'currencyCode' => 'TRY',
                    'orderStatus' => 'SUCCESS',
                    'paymentAttempts' => [['paymentStatus' => 'SUCCESS', 'paymentId' => 1]],
                ]]],
            ]),
        ]);

        $this->post('/billing/iyzico/callback', ['token' => 'TOKEN-1']);

        Event::assertDispatched(SubscriptionStarted::class);
        // Billing emails key off this — a signup must never look like a renewal.
        Event::assertNotDispatched(SubscriptionRenewed::class);
    }

    public function test_a_later_order_on_the_same_subscription_announces_a_renewal(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        // A signup charge is already on file, so the next settled order is a renewal.
        PaymentTransaction::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'gateway' => 'iyzico',
            'gateway_transaction_id' => 'ORDER-1',
            'amount_cents' => 19900,
            'currency_code' => 'TRY',
            'status' => 'paid',
            'payload' => [],
        ]);

        Http::fake([
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => [[
                    'referenceCode' => 'ORDER-2',
                    'price' => '199.00',
                    'currencyCode' => 'TRY',
                    'orderStatus' => 'SUCCESS',
                    'paymentAttempts' => [['paymentStatus' => 'SUCCESS', 'paymentId' => 2]],
                ]]],
            ]),
        ]);

        $payload = [
            'iyziEventType' => 'subscription.order.success',
            'subscriptionReferenceCode' => 'SUB-1',
            'orderReferenceCode' => 'ORDER-2',
            'customerReferenceCode' => 'CUST-1',
            'iyziReferenceCode' => 'EVENT-5',
        ];

        $this->withHeaders(['X-IYZ-SIGNATURE-V3' => $this->signWebhook($payload)])
            ->postJson('/webhooks/iyzico', $payload)
            ->assertOk();

        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_an_order_failure_webhook_marks_the_subscription_past_due(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        $payload = [
            'iyziEventType' => 'subscription.order.failure',
            'subscriptionReferenceCode' => 'SUB-1',
            'orderReferenceCode' => 'ORDER-3',
            'customerReferenceCode' => 'CUST-1',
            'iyziReferenceCode' => 'EVENT-2',
        ];

        $this->withHeaders(['X-IYZ-SIGNATURE-V3' => $this->signWebhook($payload)])
            ->postJson('/webhooks/iyzico', $payload)
            ->assertOk();

        $this->assertSame('past_due', $subscription->fresh()->status);
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->makeSubscription($user, $this->plan());

        $this->withHeaders(['X-IYZ-SIGNATURE-V3' => str_repeat('0', 64)])
            ->postJson('/webhooks/iyzico', [
                'iyziEventType' => 'subscription.order.failure',
                'subscriptionReferenceCode' => 'SUB-1',
                'iyziReferenceCode' => 'EVENT-3',
            ])
            ->assertStatus(401);

        $this->assertSame('active', Subscription::first()->status);
    }

    public function test_a_duplicate_webhook_does_not_record_the_payment_twice(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->makeSubscription($user, $this->plan());

        Http::fake([
            '*/v2/subscription/subscriptions/SUB-1' => Http::response([
                'status' => 'success',
                'data' => ['subscriptionStatus' => 'ACTIVE', 'orders' => [[
                    'referenceCode' => 'ORDER-4',
                    'price' => '199.00',
                    'currencyCode' => 'TRY',
                    'orderStatus' => 'SUCCESS',
                    'paymentAttempts' => [['paymentStatus' => 'SUCCESS', 'paymentId' => 222]],
                ]]],
            ]),
        ]);

        $payload = [
            'iyziEventType' => 'subscription.order.success',
            'subscriptionReferenceCode' => 'SUB-1',
            'orderReferenceCode' => 'ORDER-4',
            'customerReferenceCode' => 'CUST-1',
            'iyziReferenceCode' => 'EVENT-4',
        ];
        $headers = ['X-IYZ-SIGNATURE-V3' => $this->signWebhook($payload)];

        $this->withHeaders($headers)->postJson('/webhooks/iyzico', $payload)->assertOk();
        $this->withHeaders($headers)->postJson('/webhooks/iyzico', $payload)->assertOk();

        $this->assertSame(1, PaymentTransaction::where('gateway_transaction_id', 'ORDER-4')->count());
    }

    public function test_a_webhook_for_a_subscription_we_have_not_fulfilled_yet_is_acknowledged_not_retried(): void
    {
        Http::fake();

        $payload = [
            'iyziEventType' => 'subscription.order.success',
            'subscriptionReferenceCode' => 'SUB-UNKNOWN',
            'orderReferenceCode' => 'ORDER-9',
            'customerReferenceCode' => 'CUST-9',
            'iyziReferenceCode' => 'EVENT-9',
        ];

        $this->withHeaders(['X-IYZ-SIGNATURE-V3' => $this->signWebhook($payload)])
            ->postJson('/webhooks/iyzico', $payload)
            ->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
    }

    // ─── Lifecycle ──────────────────────────────────────────────────────────

    public function test_cancelling_marks_the_subscription_canceled(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        Http::fake(['*/v2/subscription/subscriptions/SUB-1/cancel' => Http::response(['status' => 'success'])]);

        $this->assertTrue($this->gateway()->cancel($subscription));
        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public function test_changing_billing_interval_is_refused_because_iyzico_cannot_do_it(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());
        Http::fake();

        $result = $this->gateway()->changePlan($subscription, $this->plan(), 'year');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cancel and resubscribe', $result['error']);
        Http::assertNothingSent();
    }

    public function test_an_upgrade_follows_the_subscription_to_the_new_reference_code_iyzico_mints(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());
        $newPlan = $this->plan(['monthly_price_cents' => 49900]);

        Http::fake([
            '*/v2/subscription/products' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PROD-1']]),
            '*/pricing-plans' => Http::response(['status' => 'success', 'data' => ['referenceCode' => 'PLAN-2']]),
            '*/v2/subscription/subscriptions/SUB-1/upgrade' => Http::response([
                'status' => 'success',
                'data' => [
                    'referenceCode' => 'SUB-2',
                    'parentReferenceCode' => 'SUB-1',
                    'subscriptionStatus' => 'ACTIVE',
                ],
            ]),
        ]);

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'month');

        $this->assertTrue($result['ok']);
        $fresh = $subscription->fresh();
        // iyzico retires the old subscription and creates a replacement.
        $this->assertSame('SUB-2', $fresh->gateway_subscription_id);
        $this->assertSame($newPlan->id, $fresh->plan_id);
        $this->assertSame('SUB-1', $fresh->gateway_metadata['parent_reference_code']);
    }

    public function test_a_refund_resolves_the_payment_transaction_id_before_calling_iyzico(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        $transaction = PaymentTransaction::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'gateway' => 'iyzico',
            'gateway_transaction_id' => 'ORDER-5',
            'amount_cents' => 19900,
            'currency_code' => 'TRY',
            'status' => 'paid',
            'payload' => ['payment_id' => '555'],
        ]);

        Http::fake([
            '*/payment/detail' => Http::response([
                'status' => 'success',
                'itemTransactions' => [['paymentTransactionId' => '777']],
            ]),
            '*/payment/refund' => Http::response(['status' => 'success']),
        ]);

        $result = $this->gateway()->refund($transaction);

        $this->assertTrue($result['ok']);
        $this->assertSame('refunded', $transaction->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment/refund')
            && $request['paymentTransactionId'] === '777'
            && $request['price'] === '199.00');
    }

    public function test_a_refund_without_a_recorded_payment_id_fails_loudly_instead_of_guessing(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $subscription = $this->makeSubscription($user, $this->plan());

        $transaction = PaymentTransaction::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'gateway' => 'iyzico',
            'gateway_transaction_id' => 'ORDER-6',
            'amount_cents' => 19900,
            'currency_code' => 'TRY',
            'status' => 'paid',
            'payload' => [],
        ]);

        Http::fake();

        $result = $this->gateway()->refund($transaction);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('dashboard', $result['error']);
        Http::assertNothingSent();
    }
}
