<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\InternalNote;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\NotificationPreference;
use App\Models\OnboardingStep;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\SupportReply;
use App\Models\SupportTicket;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Inbox\Models\CannedReply;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Integrations\Models\IntegrationAuditLog;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Models\Segment;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Models\WhatsappWidget;
use App\Services\ClientWorkspaceService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprehensive, realistic demo dataset for a single richly-populated client
 * (SpaGreen Wellness — client@spagreen.net) covering every module + feature,
 * plus a couple of lighter secondary clients so the admin client list looks real.
 *
 * Idempotent: keyed on natural unique values and guarded by existence checks, so
 * it is safe to run repeatedly (e.g. from the installer or `php artisan db:seed`).
 */
class DemoSeeder extends Seeder
{
    private Client $client;

    private int $workspaceId;

    private User $admin;

    /** @var array<string,User> staff agents keyed by short handle */
    private array $agents = [];

    /** @var Collection<int,Contact> */
    private $contacts;

    /** @var array<string,ChannelAccount> keyed by channel name */
    private array $channels = [];

    /** @var array<string,int> contact tag id by name */
    private array $tags = [];

    private string $waPhoneNumberId = '109820000111222';

    public function run(): void
    {
        $this->command?->info('Seeding comprehensive demo data for SpaGreen Wellness…');

        $this->seedPrimaryClient();
        $this->seedTeam();
        $this->seedBilling();
        $this->seedTagsAndSegments();
        $this->seedContacts();
        $this->seedChannels();
        $this->seedWhatsapp();
        $this->seedInbox();
        $this->seedConversations();
        $this->seedAi();
        $this->seedAutomations();
        $this->seedBroadcasting();
        $this->seedLeads();
        $this->seedSocial();
        $this->seedEcommerce();
        $this->seedSupport();
        $this->seedWebhooks();
        $this->seedMisc();
        $this->seedIntegrationsAudit();
        $this->seedSecondaryClients();

        $this->command?->info('Demo data complete. Login: client@spagreen.net / 12345678');
    }

    /* ───────────────────────────── helpers ──────────────────────────── */

    /** Backdate created_at/updated_at on a freshly-created model for realistic time-series. */
    private function bk($model, Carbon $created, ?Carbon $updated = null): void
    {
        $model->forceFill([
            'created_at' => $created,
            'updated_at' => $updated ?? $created,
        ])->saveQuietly();
    }

    private function days(int $n): Carbon
    {
        return Carbon::now()->subDays($n);
    }

    /* ──────────────────────────── primary client ─────────────────────── */

    private function seedPrimaryClient(): void
    {
        $this->client = Client::updateOrCreate(
            ['email' => 'client@spagreen.net'],
            [
                'name' => 'SpaGreen Wellness',
                'status' => Client::STATUS_ACTIVE,
                'phone' => '+1 (415) 555-0142',
                'address' => "1280 Market Street, Suite 400\nSan Francisco, CA 94102\nUnited States",
                'tagline' => 'Relax. Restore. Reconnect.',
                'support_email' => 'hello@spagreen.net',
                'primary_color' => '#0E7C5A',
                'base_currency' => 'USD',
                'currency_symbol' => '$',
                'currency_position' => 'before',
            ]
        );

        $this->admin = User::updateOrCreate(
            ['email' => 'client@spagreen.net'],
            [
                'name' => 'Olivia Bennett',
                'password' => '12345678',
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'client_id' => $this->client->id,
                'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                'timezone' => 'America/Los_Angeles',
                'locale' => 'en',
                'theme' => 'light',
            ]
        );

        if (! $this->admin->hasVerifiedEmail()) {
            $this->admin->markEmailAsVerified();
        }

        app(ClientWorkspaceService::class)->syncClientUser($this->admin->fresh());
        $this->admin->refresh();
        $this->workspaceId = (int) $this->admin->workspace_id;

        // Give the workspace a proper brand name.
        Workspace::whereKey($this->workspaceId)->update([
            'name' => 'SpaGreen Wellness',
            'currency_code' => 'USD',
            'default_locale' => 'en',
        ]);
    }

    private function seedTeam(): void
    {
        $team = [
            ['handle' => 'marcus', 'name' => 'Marcus Reed', 'email' => 'marcus@spagreen.net', 'role' => User::CLIENT_ROLE_STAFF],
            ['handle' => 'priya', 'name' => 'Priya Sharma', 'email' => 'priya@spagreen.net', 'role' => User::CLIENT_ROLE_STAFF],
            ['handle' => 'daniel', 'name' => 'Daniel Okafor', 'email' => 'daniel@spagreen.net', 'role' => User::CLIENT_ROLE_STAFF],
        ];

        foreach ($team as $member) {
            $user = User::updateOrCreate(
                ['email' => $member['email']],
                [
                    'name' => $member['name'],
                    'password' => 'password',
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'client_id' => $this->client->id,
                    'client_role' => $member['role'],
                    'workspace_id' => $this->workspaceId,
                    'timezone' => 'America/Los_Angeles',
                ]
            );
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
            app(ClientWorkspaceService::class)->syncClientUser($user->fresh());
            $this->agents[$member['handle']] = $user->fresh();
        }
    }

    /* ──────────────────────────────── billing ────────────────────────── */

    private function seedBilling(): void
    {
        $business = Plan::where('slug', 'business')->first() ?? Plan::orderByDesc('price_cents')->first();
        if (! $business) {
            return;
        }

        ClientSubscription::firstOrCreate(
            ['client_id' => $this->client->id, 'plan_id' => $business->id, 'status' => ClientSubscription::STATUS_ACTIVE],
            [
                'billing_cycle' => ClientSubscription::BILLING_MONTHLY,
                'starts_at' => $this->days(208),
                'ends_at' => Carbon::now()->addDays(22),
            ]
        );

        $sub = Subscription::firstOrCreate(
            ['user_id' => $this->admin->id, 'plan_id' => $business->id],
            [
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'gateway' => 'stripe',
                'gateway_subscription_id' => 'sub_'.Str::lower(Str::random(20)),
                'starts_at' => $this->days(208),
                'renews_at' => Carbon::now()->addDays(22),
            ]
        );

        // ~14 monthly invoices.
        if ($sub->paymentTransactions()->count() === 0) {
            for ($i = 14; $i >= 1; $i--) {
                $when = Carbon::now()->subMonthsNoOverflow($i)->setTime(9, 12);
                $tx = PaymentTransaction::create([
                    'subscription_id' => $sub->id,
                    'user_id' => $this->admin->id,
                    'gateway' => 'stripe',
                    'gateway_transaction_id' => 'ch_'.Str::lower(Str::random(22)),
                    'amount_cents' => $business->monthly_price_cents ?: 9900,
                    'currency_code' => 'USD',
                    'status' => 'succeeded',
                    'tax_amount_cents' => 817,
                    'invoice_path' => 'invoices/spagreen-'.$when->format('Y-m').'.pdf',
                    'payload' => ['brand' => 'visa', 'last4' => '4242', 'description' => 'SpaGreen Wellness — Business plan'],
                ]);
                $this->bk($tx, $when);
            }
        }

        Coupon::firstOrCreate(['code' => 'WELCOME20'], [
            'kind' => 'percent', 'amount' => 20, 'duration' => 'once',
            'max_redemptions' => 500, 'times_redeemed' => 134, 'enabled' => true,
            'expires_at' => Carbon::now()->addMonths(3),
        ]);
        Coupon::firstOrCreate(['code' => 'SPAVIP10'], [
            'kind' => 'fixed', 'amount' => 1000, 'duration' => 'repeating', 'duration_in_months' => 3,
            'max_redemptions' => 200, 'times_redeemed' => 41, 'enabled' => true,
        ]);
        Coupon::firstOrCreate(['code' => 'BLACKFRIDAY', 'kind' => 'percent'], [
            'amount' => 40, 'duration' => 'once', 'times_redeemed' => 0, 'enabled' => false,
            'expires_at' => Carbon::now()->subMonths(6),
        ]);

        TaxRate::firstOrCreate(['name' => 'California Sales Tax', 'country' => 'US'], [
            'region' => 'CA', 'percentage' => 8.50, 'inclusive' => false, 'enabled' => true,
        ]);
        TaxRate::firstOrCreate(['name' => 'EU VAT', 'country' => 'DE'], [
            'percentage' => 19.00, 'inclusive' => true, 'enabled' => true,
        ]);
    }

    /* ─────────────────────────── tags & segments ─────────────────────── */

    private function seedTagsAndSegments(): void
    {
        $tags = [
            'VIP' => '#d97706', 'Member' => '#0E7C5A', 'New Lead' => '#3b82f6',
            'Birthday Club' => '#ec4899', 'Lapsed' => '#6b7280', 'Bridal' => '#a855f7',
            'Corporate' => '#0ea5e9', 'Newsletter' => '#14b8a6', 'Abandoned Cart' => '#ef4444',
            'High Spender' => '#f59e0b',
        ];
        foreach ($tags as $name => $color) {
            $tag = ContactTag::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $name],
                ['color' => $color]
            );
            $this->tags[$name] = $tag->id;
        }

        Segment::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'VIP Members'],
            ['type' => 'static', 'contact_count' => 0, 'rules_json' => null]
        );
        Segment::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'Lapsed (no visit 90+ days)'],
            ['type' => 'dynamic', 'contact_count' => 0, 'rules_json' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'tag', 'operator' => 'equals', 'value' => 'Lapsed'],
                    ['field' => 'opt_in_whatsapp', 'operator' => 'equals', 'value' => true],
                ],
            ]]
        );
        Segment::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'Email Newsletter Subscribers'],
            ['type' => 'dynamic', 'contact_count' => 0, 'rules_json' => [
                'match' => 'all',
                'rules' => [['field' => 'opt_in_email', 'operator' => 'equals', 'value' => true]],
            ]]
        );
    }

    /* ────────────────────────────── contacts ─────────────────────────── */

    private function seedContacts(): void
    {
        // Curated, recognisable customers used throughout conversations/orders.
        $curated = [
            ['Emma', 'Thompson', '+14155550111', 'emma.thompson@gmail.com', ['VIP', 'Member', 'High Spender'], 'whatsapp', ['membership' => 'Gold', 'preferred_therapist' => 'Priya', 'lifetime_value' => 2840]],
            ['James', 'Wilson', '+14155550112', 'james.wilson@outlook.com', ['Member'], 'instagram', ['membership' => 'Silver', 'preferred_therapist' => 'Marcus']],
            ['Sophia', 'Martinez', '+14155550113', 'sophia.m@gmail.com', ['VIP', 'Birthday Club'], 'whatsapp', ['membership' => 'Gold', 'birthday' => '06-28']],
            ['Liam', 'Anderson', '+14155550114', 'liam.anderson@gmail.com', ['New Lead'], 'messenger', ['source_detail' => 'Instagram ad']],
            ['Olivia', 'Garcia', '+14155550115', 'olivia.garcia@yahoo.com', ['Member', 'Newsletter'], 'whatsapp', ['membership' => 'Silver']],
            ['Noah', 'Brown', '+14155550116', 'noah.brown@gmail.com', ['Corporate'], 'whatsapp', ['company' => 'Northbeam Inc.']],
            ['Ava', 'Davis', '+14155550117', 'ava.davis@gmail.com', ['Bridal'], 'instagram', ['event_date' => '2026-09-12']],
            ['William', 'Rodriguez', '+14155550118', 'will.rodriguez@gmail.com', ['Lapsed'], 'whatsapp', ['last_visit' => '2025-12-02']],
            ['Isabella', 'Lopez', '+14155550119', 'bella.lopez@gmail.com', ['VIP', 'Member', 'High Spender'], 'whatsapp', ['membership' => 'Platinum', 'lifetime_value' => 5120]],
            ['Mason', 'Lee', '+14155550120', 'mason.lee@gmail.com', ['New Lead'], 'whatsapp', []],
            ['Mia', 'Gonzalez', '+14155550121', 'mia.g@gmail.com', ['Member', 'Birthday Club'], 'instagram', ['birthday' => '06-22']],
            ['Ethan', 'Harris', '+14155550122', 'ethan.harris@gmail.com', ['Lapsed', 'Newsletter'], 'messenger', ['last_visit' => '2025-11-18']],
            ['Charlotte', 'Clark', '+14155550123', 'charlotte.clark@gmail.com', ['VIP', 'Bridal'], 'whatsapp', ['event_date' => '2026-08-30']],
            ['Benjamin', 'Walker', '+14155550124', 'ben.walker@gmail.com', ['Corporate'], 'whatsapp', ['company' => 'Halcyon Studios']],
        ];

        $created = collect();
        foreach ($curated as $i => $row) {
            [$first, $last, $phone, $email, $tagNames, $source, $custom] = $row;
            $contact = Contact::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'phone_e164' => $phone],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'country' => 'US',
                    'language' => 'en',
                    'opt_in_whatsapp' => true,
                    'opt_in_sms' => $i % 3 !== 0,
                    'opt_in_email' => $i % 4 !== 0,
                    'source' => $source,
                    'custom_fields' => $custom,
                    'last_seen_at' => $this->days(rand(0, 40)),
                ]
            );
            $this->bk($contact, $this->days(rand(20, 200)));
            foreach ($tagNames as $tn) {
                if (isset($this->tags[$tn])) {
                    DB::table('contact_tag_pivot')->insertOrIgnore(['contact_id' => $contact->id, 'tag_id' => $this->tags[$tn]]);
                }
            }
            $created->push($contact);
        }

        // Procedural fillers to reach a healthy contact list.
        $sources = ['whatsapp', 'instagram', 'messenger', 'website', 'walk-in', 'referral', 'campaign', 'import'];
        $fillerTags = ['Member', 'New Lead', 'Newsletter', 'Lapsed', 'Birthday Club'];
        for ($i = 0; $i < 70; $i++) {
            $first = fake()->firstName();
            $last = fake()->lastName();
            $contact = Contact::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'phone_e164' => '+1415555'.str_pad((string) (1300 + $i), 4, '0', STR_PAD_LEFT)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => Str::lower($first.'.'.$last.$i.'@example.com'),
                    'country' => 'US',
                    'language' => 'en',
                    'opt_in_whatsapp' => fake()->boolean(85),
                    'opt_in_sms' => fake()->boolean(55),
                    'opt_in_email' => fake()->boolean(75),
                    'source' => Arr::random($sources),
                    'last_seen_at' => $this->days(rand(0, 90)),
                ]
            );
            $this->bk($contact, $this->days(rand(5, 210)));
            foreach (Arr::random($fillerTags, rand(0, 2)) as $tn) {
                DB::table('contact_tag_pivot')->insertOrIgnore(['contact_id' => $contact->id, 'tag_id' => $this->tags[$tn]]);
            }
            $created->push($contact);
        }

        $this->contacts = $created;

        // Attach the VIP segment to VIP-tagged contacts.
        $vipSegment = Segment::where('workspace_id', $this->workspaceId)->where('name', 'VIP Members')->first();
        if ($vipSegment) {
            $vipContactIds = DB::table('contact_tag_pivot')
                ->where('tag_id', $this->tags['VIP'])
                ->pluck('contact_id');
            foreach ($vipContactIds as $cid) {
                DB::table('segment_contact')->insertOrIgnore(['segment_id' => $vipSegment->id, 'contact_id' => $cid]);
            }
            $vipSegment->update(['contact_count' => $vipContactIds->count()]);
        }
    }

    private function contact(string $first): ?Contact
    {
        return $this->contacts->firstWhere('first_name', $first);
    }

    /* ────────────────────────────── channels ─────────────────────────── */

    private function seedChannels(): void
    {
        $this->channels['whatsapp'] = ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => 'whatsapp', 'phone_number_id' => $this->waPhoneNumberId],
            [
                'provider' => 'meta',
                'display_name' => 'SpaGreen Wellness',
                'business_account_id' => '102938475610293',
                'credentials' => ['system_user_token' => 'DEMO_WA_SYSTEM_TOKEN', 'phone_number_id' => $this->waPhoneNumberId],
                'status' => 'active',
                'meta_json' => ['phone_number_id' => $this->waPhoneNumberId, 'verified_name' => 'SpaGreen Wellness'],
            ]
        );

        $this->channels['instagram'] = ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => 'instagram', 'display_name' => '@spagreenwellness'],
            [
                'provider' => 'meta',
                'business_account_id' => '17841400000111222',
                'credentials' => ['access_token' => 'DEMO_IG_TOKEN', 'instagram_account_id' => '17841400000111222'],
                'status' => 'active',
                'meta_json' => ['instagram_page_id' => '556677889900112', 'instagram_account_id' => '17841400000111222', 'username' => 'spagreenwellness'],
            ]
        );

        $this->channels['messenger'] = ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => 'messenger', 'display_name' => 'SpaGreen Wellness (Facebook)'],
            [
                'provider' => 'meta',
                'business_account_id' => '556677889900112',
                'credentials' => ['page_access_token' => 'DEMO_MSGR_TOKEN', 'page_id' => '556677889900112'],
                'status' => 'active',
                'meta_json' => ['page_id' => '556677889900112', 'page_name' => 'SpaGreen Wellness'],
            ]
        );
    }

    /* ────────────────────────────── whatsapp ─────────────────────────── */

    private function seedWhatsapp(): void
    {
        $wabaId = '102938475610293';
        $waba = WhatsappBusinessAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'waba_id' => $wabaId],
            [
                'credentials' => ['system_user_token' => 'DEMO_WA_SYSTEM_TOKEN'],
                'webhook_verify_token' => 'spagreen-verify-'.Str::random(24),
                'status' => 'active',
                'meta_json' => ['business_name' => 'SpaGreen Wellness', 'timezone' => 'America/Los_Angeles'],
            ]
        );

        WhatsappPhoneNumber::firstOrCreate(
            ['phone_number_id' => $this->waPhoneNumberId],
            [
                'waba_id_fk' => $waba->id,
                'display_phone' => '+1 415-555-0142',
                'verified_name' => 'SpaGreen Wellness',
                'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_10K',
                'code_verification_status' => 'VERIFIED',
                'name_status' => 'APPROVED',
                'account_mode' => 'LIVE',
            ]
        );

        $this->seedWhatsappTemplates($wabaId);
        $this->seedAutoReplies();

        WhatsappWidget::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'Website Chat Bubble'],
            [
                'phone_number_id' => $this->waPhoneNumberId,
                'display_phone' => '+1 415-555-0142',
                'prefilled_message' => "Hi SpaGreen! I'd like to book an appointment.",
                'greeting_message' => '👋 Hi there! Need help booking a treatment? Chat with us on WhatsApp.',
                'agent_name' => 'SpaGreen Concierge',
                'agent_avatar_color' => '#0E7C5A',
                'button_color' => '#25D366',
                'position' => 'bottom_right',
                'allowed_domains' => ['spagreen.net', 'www.spagreen.net'],
                'working_hours_json' => ['mon_fri' => ['09:00', '19:00'], 'sat' => ['10:00', '17:00'], 'sun' => 'closed'],
            ]
        );
    }

    private function seedWhatsappTemplates(string $wabaId): void
    {
        $templates = [
            [
                'name' => 'appointment_reminder', 'category' => 'UTILITY', 'status' => 'APPROVED',
                'meta_template_id' => '1462738291029384',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, a friendly reminder of your {{2}} appointment at SpaGreen on {{3}}. Reply CONFIRM to keep it or RESCHEDULE to change.', 'example' => ['body_text' => [['Emma', 'Deep Tissue Massage', 'Fri 27 Jun, 2:00 PM']]]],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'QUICK_REPLY', 'text' => 'Confirm'],
                        ['type' => 'QUICK_REPLY', 'text' => 'Reschedule'],
                    ]],
                ],
            ],
            [
                'name' => 'booking_confirmation', 'category' => 'UTILITY', 'status' => 'APPROVED',
                'meta_template_id' => '1462738291029385',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Booking Confirmed ✅'],
                    ['type' => 'BODY', 'text' => "Thanks {{1}}! Your {{2}} is booked for {{3}}. We can't wait to pamper you. See you soon!", 'example' => ['body_text' => [['Sophia', 'Hot Stone Facial', 'Sat 28 Jun, 11:30 AM']]]],
                    ['type' => 'FOOTER', 'text' => 'SpaGreen Wellness · 1280 Market St, SF'],
                ],
            ],
            [
                'name' => 'welcome_offer', 'category' => 'MARKETING', 'status' => 'APPROVED',
                'meta_template_id' => '1462738291029386',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['https://loremflickr.com/1080/608/spa,wellness?lock=77']]],
                    ['type' => 'BODY', 'text' => 'Welcome to SpaGreen, {{1}}! 🌿 Enjoy 20% off your first treatment with code WELCOME20. Valid for 14 days.', 'example' => ['body_text' => [['Liam']]]],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'URL', 'text' => 'Book Now', 'url' => 'https://spagreen.net/book'],
                    ]],
                ],
            ],
            [
                'name' => 'review_request', 'category' => 'UTILITY', 'status' => 'APPROVED',
                'meta_template_id' => '1462738291029387',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, we hope you loved your visit! Would you mind leaving us a quick review? It means the world to our therapists. 💚', 'example' => ['body_text' => [['Emma']]]],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'URL', 'text' => 'Leave a Review', 'url' => 'https://g.page/spagreen/review'],
                    ]],
                ],
            ],
            [
                'name' => 'monthly_promo_june', 'category' => 'MARKETING', 'status' => 'PENDING',
                'meta_template_id' => null,
                'components' => [
                    ['type' => 'BODY', 'text' => '☀️ Summer Glow is here, {{1}}! Book any facial this month and get a free aromatherapy add-on. Limited slots — reserve yours today.', 'example' => ['body_text' => [['there']]]],
                ],
            ],
            [
                'name' => 'otp_verification', 'category' => 'AUTHENTICATION', 'status' => 'APPROVED',
                'meta_template_id' => '1462738291029388',
                'components' => [
                    ['type' => 'BODY', 'text' => '{{1}} is your SpaGreen verification code. It expires in 10 minutes.', 'example' => ['body_text' => [['483920']]]],
                ],
            ],
            [
                'name' => 'winback_offer', 'category' => 'MARKETING', 'status' => 'REJECTED',
                'meta_template_id' => null, 'rejection_reason' => 'Template contains content that violates WhatsApp Commerce Policy (excessive promotional language).',
                'components' => [
                    ['type' => 'BODY', 'text' => "We miss you {{1}}!!! Come back NOW for 50% OFF everything!!! Don't miss out!!!", 'example' => ['body_text' => [['friend']]]],
                ],
            ],
        ];

        foreach ($templates as $t) {
            WhatsappTemplate::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $t['name'], 'language' => 'en'],
                [
                    'waba_id' => $wabaId,
                    'category' => $t['category'],
                    'status' => $t['status'],
                    'components' => $t['components'],
                    'meta_template_id' => $t['meta_template_id'] ?? null,
                    'rejection_reason' => $t['rejection_reason'] ?? null,
                ]
            );
        }
    }

    private function seedAutoReplies(): void
    {
        $rules = [
            ['trigger_type' => 'welcome', 'match_mode' => 'contains', 'keywords' => [], 'response_kind' => 'text', 'priority' => 1,
                'payload_json' => ['text' => "👋 Welcome to *SpaGreen Wellness*! How can we help you relax today?\n\n• Type *BOOK* to make an appointment\n• Type *PRICES* for our treatment menu\n• Type *HOURS* for opening times\n• Type *HUMAN* to chat with our team"]],
            ['trigger_type' => 'keyword', 'match_mode' => 'exact', 'keywords' => ['prices', 'price', 'menu', 'cost'], 'response_kind' => 'text', 'priority' => 2,
                'payload_json' => ['text' => "🌿 *SpaGreen Treatment Menu*\n\n• Swedish Massage (60 min) — $95\n• Deep Tissue Massage (60 min) — $120\n• Hot Stone Facial (75 min) — $135\n• Aromatherapy Ritual (90 min) — $160\n• Bridal Glow Package — from $290\n\nMembers save 15%. Reply *BOOK* to reserve."]],
            ['trigger_type' => 'keyword', 'match_mode' => 'contains', 'keywords' => ['hours', 'open', 'opening', 'timing'], 'response_kind' => 'text', 'priority' => 3,
                'payload_json' => ['text' => "🕐 *Opening Hours*\nMon–Fri: 9:00 AM – 7:00 PM\nSaturday: 10:00 AM – 5:00 PM\nSunday: Closed\n\n1280 Market Street, Suite 400, San Francisco."]],
            ['trigger_type' => 'keyword', 'match_mode' => 'contains', 'keywords' => ['book', 'booking', 'appointment', 'reserve'], 'response_kind' => 'text', 'priority' => 4,
                'payload_json' => ['text' => "📅 Wonderful! You can book instantly here: https://spagreen.net/book\n\nOr tell me the treatment and your preferred day & time, and I'll check availability for you."]],
            ['trigger_type' => 'keyword', 'match_mode' => 'contains', 'keywords' => ['human', 'agent', 'staff', 'someone'], 'response_kind' => 'text', 'priority' => 5,
                'payload_json' => ['text' => '👤 Connecting you with our front desk. One of our team will be with you shortly — usually within a few minutes during opening hours. 💚']],
            ['trigger_type' => 'out_of_hours', 'match_mode' => 'contains', 'keywords' => [], 'response_kind' => 'text', 'priority' => 10,
                'schedule_json' => ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '19:00', 'timezone' => 'America/Los_Angeles'],
                'payload_json' => ['text' => "🌙 Thanks for messaging SpaGreen! We're currently closed but your message is safe with us. We'll reply when we reopen. For instant booking visit https://spagreen.net/book"]],
        ];

        foreach ($rules as $rule) {
            WhatsappAutoReply::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'trigger_type' => $rule['trigger_type'], 'priority' => $rule['priority']],
                array_merge($rule, ['workspace_id' => $this->workspaceId, 'channel_account_id' => $this->channels['whatsapp']->id ?? null, 'enabled' => true])
            );
        }
    }

    /* ──────────────────────────────── inbox ──────────────────────────── */

    private function seedInbox(): void
    {
        $canned = [
            ['booking', "Hi! You can book online any time at https://spagreen.net/book 🌿 Just let me know if you'd like me to reserve a slot for you."],
            ['hours', "We're open Mon–Fri 9am–7pm, Sat 10am–5pm, and closed Sundays. 💚"],
            ['prices', "Here's our menu: Swedish $95 · Deep Tissue $120 · Hot Stone Facial $135 · Aromatherapy $160. Members save 15%!"],
            ['thanks', "Thank you so much for choosing SpaGreen — we can't wait to see you! 🌸"],
            ['directions', "We're at 1280 Market Street, Suite 400, San Francisco. Parking is available in the building garage."],
            ['cancellation', 'No problem! Our policy allows free changes up to 24 hours before your appointment. Would you like to reschedule?'],
            ['membership', 'Our Gold Membership is $79/mo and includes one treatment monthly plus 15% off everything. Want me to send the details?'],
        ];
        foreach ($canned as [$shortcut, $body]) {
            CannedReply::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'shortcut' => $shortcut],
                ['body' => $body]
            );
        }

        $labels = [
            'Booking' => '#0E7C5A', 'Complaint' => '#ef4444', 'Product Question' => '#3b82f6',
            'VIP' => '#d97706', 'Follow-up' => '#a855f7', 'Urgent' => '#dc2626', 'Resolved' => '#6b7280',
        ];
        foreach ($labels as $name => $color) {
            InboxLabel::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $name],
                ['color' => $color]
            );
        }
    }

    private function label(string $name): ?InboxLabel
    {
        return InboxLabel::where('workspace_id', $this->workspaceId)->where('name', $name)->first();
    }

    /* ─────────────────────────── conversations ───────────────────────── */

    private function seedConversations(): void
    {
        if (Conversation::where('workspace_id', $this->workspaceId)->count() > 0) {
            return; // already seeded
        }

        // [contactFirst, channel, status, assignedTo, agentHandle|null, daysAgo, labels[], note|null, script[]]
        // script entry: [dir, type, body, status, sentBy, agentHandle|null]
        $threads = [
            ['Emma', 'whatsapp', 'resolved', 'human', 'priya', 6, ['Booking', 'VIP', 'Resolved'], 'Gold member — always books deep tissue with Priya.', [
                ['in', 'text', 'Hi! Could I book a deep tissue massage with Priya for Friday afternoon?', 'read', 'human', null],
                ['out', 'text', 'Hi Emma! 💚 Of course. Priya has 2:00 PM open this Friday — shall I reserve it for you?', 'read', 'human', 'priya'],
                ['in', 'text', 'Perfect, yes please!', 'read', 'human', null],
                ['out', 'template', 'Booking Confirmed ✅ Thanks Emma! Your Deep Tissue Massage is booked for Fri 27 Jun, 2:00 PM.', 'delivered', 'human', 'priya'],
                ['in', 'text', 'Thank you! See you then 🌸', 'read', 'human', null],
            ]],
            ['Liam', 'messenger', 'open', 'bot', null, 0, ['Booking'], null, [
                ['in', 'text', 'hey do you guys do couples massages?', 'read', 'human', null],
                ['out', 'text', 'Hi Liam! 👋 Yes we do — our Couples Aromatherapy Ritual is 90 minutes in our private suite, $300 for two. Would you like to book?', 'delivered', 'bot', null],
                ['in', 'text', 'nice, what times do you have next weekend?', 'delivered', 'human', null],
            ]],
            ['Sophia', 'whatsapp', 'open', 'human', 'marcus', 0, ['VIP', 'Follow-up'], 'Birthday on the 28th — offer the complimentary add-on.', [
                ['in', 'text', "Hi! I'd love to book my birthday facial 🎂", 'read', 'human', null],
                ['out', 'text', 'Happy almost-birthday Sophia! 🎉 As a VIP you get a complimentary aromatherapy add-on. When were you thinking?', 'read', 'human', 'marcus'],
                ['in', 'text', 'Saturday morning if possible?', 'delivered', 'human', null],
            ]],
            ['Isabella', 'whatsapp', 'resolved', 'human', 'priya', 12, ['VIP', 'Resolved'], null, [
                ['in', 'text', 'Can I move my Tuesday appointment to Wednesday same time?', 'read', 'human', null],
                ['out', 'text', "Absolutely Isabella — done! You're now booked Wednesday at 4:00 PM. 💚", 'read', 'human', 'priya'],
                ['in', 'text', "You're the best, thank you!", 'read', 'human', null],
            ]],
            ['James', 'instagram', 'open', 'bot', null, 1, ['Product Question'], null, [
                ['in', 'text', 'do you sell the lavender oil you used last time?', 'read', 'human', null],
                ['out', 'text', 'Hi James! 🌿 Yes — our Lavender Calm Body Oil is $32 in our boutique: https://spagreen.net/shop/lavender-oil', 'delivered', 'bot', null],
            ]],
            ['William', 'whatsapp', 'open', 'human', 'marcus', 2, ['Follow-up'], 'Lapsed customer — last visit Dec. Win-back opportunity.', [
                ['in', 'text', "Hi, it's been a while! Do you still have my membership on file?", 'read', 'human', null],
                ['out', 'text', "Welcome back William! 😊 Yes, your Silver membership is still active. We'd love to see you again — can I book something this week?", 'read', 'human', 'marcus'],
            ]],
            ['Ava', 'instagram', 'pending', 'human', 'priya', 3, ['Booking', 'VIP'], 'Bridal party of 6 — September wedding.', [
                ['in', 'text', "Hi! I'm getting married in September and want to book a bridal spa day for 6 people 💍", 'read', 'human', null],
                ['out', 'text', "Congratulations Ava! 🥂 How exciting. Our Bridal Glow Package is perfect for groups. I'll put together a custom quote for 6 — what date works?", 'read', 'human', 'priya'],
                ['in', 'text', 'September 12th!', 'delivered', 'human', null],
            ]],
            ['Charlotte', 'whatsapp', 'snoozed', 'human', 'daniel', 4, ['Booking', 'Follow-up'], 'Waiting on her to confirm the package tier.', [
                ['in', 'text', "Could you remind me what's included in the Platinum bridal package?", 'read', 'human', null],
                ['out', 'text', "Of course Charlotte! Platinum includes full-body massage, facial, mani-pedi, champagne & a private suite for the day. I'll send the brochure shortly. 💚", 'read', 'human', 'daniel'],
            ]],
            ['Noah', 'whatsapp', 'resolved', 'human', 'marcus', 8, ['Corporate', 'Resolved'], 'Corporate wellness day — Northbeam Inc.', [
                ['in', 'text', "We'd like to arrange a corporate wellness afternoon for 12 staff. Possible?", 'read', 'human', null],
                ['out', 'text', "Definitely Noah! We offer on-site chair massage or in-spa packages. I'll email you a corporate proposal today. 🙌", 'read', 'human', 'marcus'],
                ['in', 'text', 'Brilliant, thanks!', 'read', 'human', null],
            ]],
            ['Mia', 'instagram', 'open', 'bot', null, 0, [], null, [
                ['in', 'text', 'what are your prices?', 'read', 'human', null],
                ['out', 'text', 'Hi Mia! 🌿 Swedish $95 · Deep Tissue $120 · Hot Stone Facial $135 · Aromatherapy $160. Members save 15%! Want to book?', 'delivered', 'bot', null],
            ]],
            ['Ethan', 'messenger', 'open', 'human', 'priya', 1, ['Complaint', 'Urgent'], 'Unhappy about a late start last visit — handle with care.', [
                ['in', 'text', 'My last appointment started 25 minutes late and felt rushed. Not great honestly.', 'read', 'human', null],
                ['out', 'text', "I'm so sorry Ethan, that's not the SpaGreen standard. 🙏 I'd like to make it right — may I offer you a complimentary 75-minute session with our lead therapist?", 'read', 'human', 'priya'],
                ['in', 'text', 'That would actually be lovely, thank you.', 'delivered', 'human', null],
            ]],
            ['Olivia', 'whatsapp', 'resolved', 'human', 'marcus', 10, ['Resolved'], null, [
                ['in', 'text', 'Do you have gift cards?', 'read', 'human', null],
                ['out', 'text', 'We do! 🎁 Digital gift cards from $50, redeemable on any treatment or product: https://spagreen.net/gift', 'read', 'human', 'marcus'],
                ['in', 'text', 'Bought one, thanks!', 'read', 'human', null],
            ]],
        ];

        foreach ($threads as $t) {
            [$first, $channelKey, $status, $assignedTo, $agentHandle, $daysAgo, $labels, $note, $script] = $t;
            $contact = $this->contact($first);
            if (! $contact) {
                continue;
            }
            $channelAccount = $this->channels[$channelKey];
            $agent = $agentHandle ? ($this->agents[$agentHandle] ?? null) : null;

            $base = $this->days($daysAgo)->setTime(rand(9, 17), rand(0, 59));
            $convo = Conversation::create([
                'workspace_id' => $this->workspaceId,
                'channel_account_id' => $channelAccount->id,
                'contact_id' => $contact->id,
                'external_thread_id' => $channelKey.'_'.Str::random(12),
                'status' => $status,
                'assigned_to' => $assignedTo,
                'assigned_user_id' => $agent?->id,
                'handover_at' => $assignedTo === 'human' ? $base->copy()->addMinutes(2) : null,
            ]);

            $cursor = $base->copy();
            $firstInbound = null;
            $firstResponse = null;
            $lastInbound = null;
            $unread = 0;
            foreach ($script as $m) {
                [$dir, $type, $body, $mStatus, $sentBy, $msgAgentHandle] = $m;
                $cursor = $cursor->copy()->addMinutes(rand(2, 28));
                $msgAgent = $msgAgentHandle ? ($this->agents[$msgAgentHandle] ?? null) : null;
                $msg = Message::create([
                    'conversation_id' => $convo->id,
                    'direction' => $dir,
                    'channel' => $channelKey,
                    'type' => $type,
                    'body' => $body,
                    'status' => $dir === 'in' ? 'delivered' : $mStatus,
                    'sent_by' => $sentBy,
                    'user_id' => $dir === 'out' ? $msgAgent?->id : null,
                    'provider_message_id' => 'wamid.'.Str::random(28),
                    'sent_at' => $cursor,
                ]);
                $this->bk($msg, $cursor);
                if ($dir === 'in') {
                    $firstInbound ??= $cursor->copy();
                    $lastInbound = $cursor->copy();
                    if ($status === 'open') {
                        $unread++;
                    }
                } elseif ($dir === 'out') {
                    if ($firstInbound && ! $firstResponse) {
                        $firstResponse = $cursor->copy();
                    }
                    $unread = 0;
                }
            }

            $convo->forceFill([
                'last_message_at' => $cursor,
                'last_inbound_at' => $lastInbound,
                'first_response_at' => $firstResponse,
                'resolved_at' => $status === 'resolved' ? $cursor->copy()->addMinutes(5) : null,
                'unread_count' => $unread,
                'created_at' => $base,
                'updated_at' => $cursor,
            ])->saveQuietly();

            foreach ($labels as $labelName) {
                if ($lbl = $this->label($labelName)) {
                    DB::table('inbox_label_conversation')->insertOrIgnore(['label_id' => $lbl->id, 'conversation_id' => $convo->id]);
                }
            }

            if ($note && $agent) {
                InternalNote::create([
                    'conversation_id' => $convo->id,
                    'user_id' => $agent->id,
                    'body' => $note,
                ]);
            }
        }

        $this->seedProceduralConversations();
    }

    /**
     * Generate additional, lighter conversations from filler contacts so the
     * inbox feels busy — varied channels, statuses, assignees and short threads.
     */
    private function seedProceduralConversations(): void
    {
        $inbound = [
            'Hi, do you have any availability this week?',
            'How much is a 60-minute massage?',
            'Can I book a facial for two people?',
            'What are your opening hours on the weekend?',
            'Do you offer gift cards?',
            'Is parking available at your location?',
            "I'd like to reschedule my appointment, please.",
            'Do you have any membership options?',
            'Can I add a hot stone treatment to my booking?',
            "What's included in the bridal package?",
            'Are walk-ins welcome today?',
            'Do you have any openings with a female therapist?',
        ];
        $replies = [
            'Hi {{name}}! 🌿 Yes, we have a few slots open — what day works best for you?',
            'A 60-minute Swedish massage is $95, and members save 15%. Would you like to book?',
            'Absolutely! Our Couples Aromatherapy Ritual is perfect for two. Shall I check availability?',
            "We're open Sat 10am–5pm and closed Sundays 💚",
            'Yes! Digital gift cards start at $50: https://spagreen.net/gift',
            'We do — there’s a parking garage in our building, validated for guests. 🚗',
            'Of course! Just let me know your new preferred day and time and I’ll sort it out.',
            'Yes! Our Gold membership is $79/mo and includes a monthly treatment plus 15% off everything.',
            'Great choice — a hot stone add-on is $35. I’ll note it on your booking. 🔥',
            'Our Bridal Glow Package includes massage, facial, mani-pedi and bubbly, from $290pp.',
            'We’ll do our best to fit you in! Let me check today’s schedule for you. 🌸',
            'Yes, several of our therapists are available — I’ll find the next open slot for you.',
        ];
        $closers = ['Perfect, thank you!', 'Great, see you then 🌸', 'Thanks so much!', 'That works for me.', 'Appreciate it 💚'];
        $channelKeys = ['whatsapp', 'whatsapp', 'instagram', 'messenger'];
        $statuses = ['open', 'open', 'resolved', 'pending', 'snoozed', 'resolved'];
        $agentHandles = ['marcus', 'priya', 'daniel'];

        $pool = $this->contacts->slice(14)->values(); // filler contacts (after the curated set)
        $count = min(16, $pool->count());
        for ($i = 0; $i < $count; $i++) {
            $contact = $pool[$i];
            $channelKey = $channelKeys[$i % count($channelKeys)];
            $channelAccount = $this->channels[$channelKey];
            $status = $statuses[$i % count($statuses)];
            $useBot = $i % 3 === 0;
            $agent = $useBot ? null : $this->agents[$agentHandles[$i % count($agentHandles)]];

            $base = $this->days(rand(0, 25))->setTime(rand(9, 18), rand(0, 59));
            $convo = Conversation::create([
                'workspace_id' => $this->workspaceId,
                'channel_account_id' => $channelAccount->id,
                'contact_id' => $contact->id,
                'external_thread_id' => $channelKey.'_'.Str::random(12),
                'status' => $status,
                'assigned_to' => $useBot ? 'bot' : 'human',
                'assigned_user_id' => $agent?->id,
                'handover_at' => $useBot ? null : $base->copy()->addMinutes(2),
            ]);

            $idx = $i % count($inbound);
            $name = $contact->first_name;
            $script = [
                ['in', $inbound[$idx]],
                ['out', str_replace('{{name}}', $name, $replies[$idx])],
            ];
            // Resolved/closed threads get a closing inbound line.
            if (in_array($status, ['resolved', 'pending'], true)) {
                $script[] = ['in', Arr::random($closers)];
            }

            $cursor = $base->copy();
            $lastInbound = null;
            $firstInbound = null;
            $firstResponse = null;
            $unread = 0;
            foreach ($script as [$dir, $body]) {
                $cursor = $cursor->copy()->addMinutes(rand(2, 26));
                $msg = Message::create([
                    'conversation_id' => $convo->id,
                    'direction' => $dir,
                    'channel' => $channelKey,
                    'type' => 'text',
                    'body' => $body,
                    'status' => $dir === 'in' ? 'delivered' : ($status === 'open' ? 'sent' : 'read'),
                    'sent_by' => $dir === 'out' ? ($useBot ? 'bot' : 'human') : 'human',
                    'user_id' => $dir === 'out' ? $agent?->id : null,
                    'provider_message_id' => 'wamid.'.Str::random(28),
                    'sent_at' => $cursor,
                ]);
                $this->bk($msg, $cursor);
                if ($dir === 'in') {
                    $firstInbound ??= $cursor->copy();
                    $lastInbound = $cursor->copy();
                    if ($status === 'open') {
                        $unread++;
                    }
                } elseif ($firstInbound && ! $firstResponse) {
                    $firstResponse = $cursor->copy();
                    $unread = 0;
                }
            }

            $convo->forceFill([
                'last_message_at' => $cursor,
                'last_inbound_at' => $lastInbound,
                'first_response_at' => $firstResponse,
                'resolved_at' => $status === 'resolved' ? $cursor->copy()->addMinutes(5) : null,
                'unread_count' => $unread,
                'created_at' => $base,
                'updated_at' => $cursor,
            ])->saveQuietly();

            if ($i % 4 === 0 && ($lbl = $this->label('Booking'))) {
                DB::table('inbox_label_conversation')->insertOrIgnore(['label_id' => $lbl->id, 'conversation_id' => $convo->id]);
            }
        }
    }

    /* ──────────────────────────────── AI ─────────────────────────────── */

    private function seedAi(): void
    {
        AiProviderConfig::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'provider' => 'openai'],
            ['credentials' => ['api_key' => 'sk-demo-placeholder'], 'default_model_chat' => 'gpt-4o-mini', 'default_model_embed' => 'text-embedding-3-small', 'enabled' => true]
        );
        AiProviderConfig::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'provider' => 'anthropic'],
            ['credentials' => ['api_key' => 'sk-ant-demo-placeholder'], 'default_model_chat' => 'claude-sonnet-4-6', 'enabled' => false]
        );

        $kb = AiKnowledgeBase::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'SpaGreen Services & Policies'],
            ['embedding_model' => 'text-embedding-3-small', 'dimensions' => 1536, 'status' => 'active']
        );

        $docs = [
            ['Treatment Menu & Descriptions', "SpaGreen Wellness offers a curated menu of massage, facial and body treatments.\n\nMassages: Swedish (60 min, $95) for relaxation; Deep Tissue (60 min, $120) for muscle tension; Hot Stone (75 min, $140) using heated basalt stones.\n\nFacials: Signature Glow Facial (60 min, $115); Hot Stone Facial (75 min, $135); Anti-Ageing Collagen Facial (90 min, $175).\n\nRituals: Aromatherapy Ritual (90 min, $160); Couples Aromatherapy (90 min, $300 for two).\n\nPackages: Bridal Glow Package from $290 per person; Corporate Wellness afternoons by quote."],
            ['Membership & Pricing', "SpaGreen offers three memberships.\n\nSilver ($49/mo): 10% off all treatments and products, priority booking.\n\nGold ($79/mo): one 60-minute treatment included monthly, 15% off everything, free birthday add-on.\n\nPlatinum ($129/mo): two treatments included monthly, 20% off, complimentary refreshments, guest passes.\n\nMemberships can be paused once per year and cancelled any time with 30 days notice."],
            ['Booking & Cancellation Policy', "Appointments can be booked online at spagreen.net/book, via WhatsApp, or by phone.\n\nWe ask for 24 hours notice to change or cancel an appointment. Cancellations within 24 hours are charged 50% of the treatment price. No-shows are charged in full.\n\nPlease arrive 10 minutes early to enjoy our relaxation lounge. Late arrivals may have their treatment shortened to respect the next guest."],
            ['Product Care & Ingredients FAQ', "Our retail range is vegan, cruelty-free and made with naturally-derived ingredients.\n\nThe Lavender Calm Body Oil ($32) is safe for sensitive skin and pregnancy. The Detox Clay Mask ($28) should be used 1–2 times weekly. Our Soy Wellness Candles ($24) burn for approximately 45 hours.\n\nStore products away from direct sunlight. Patch-test new products 24 hours before full use."],
        ];
        foreach ($docs as $i => [$title, $content]) {
            $doc = AiKbDocument::firstOrCreate(
                ['kb_id' => $kb->id, 'title' => $title],
                ['source_type' => 'text', 'source_ref' => 'demo:'.Str::slug($title), 'status' => 'indexed', 'tokens' => str_word_count($content), 'last_indexed_at' => $this->days(30 - $i)]
            );
            if (AiKbChunk::where('document_id', $doc->id)->doesntExist()) {
                foreach (array_values(array_filter(array_map('trim', explode("\n\n", $content)))) as $ord => $para) {
                    AiKbChunk::create(['kb_id' => $kb->id, 'document_id' => $doc->id, 'ord' => $ord, 'content' => $para, 'tokens' => str_word_count($para), 'embedding' => null]);
                }
            }
        }

        $bot = AiChatbot::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => 'Aria — SpaGreen Concierge'],
            [
                'ai_kb_id' => $kb->id,
                'system_prompt' => "You are Aria, the warm and knowledgeable virtual concierge for SpaGreen Wellness, a premium spa. Help guests with treatments, bookings, memberships, products and policies using the knowledge base. Be calm, friendly and concise. If you can't help, offer to connect them with the team. Never invent prices — use the menu.",
                'tone' => 'friendly',
                'max_context_chunks' => 5,
                'fallback_reply' => 'I want to make sure you get the perfect answer — let me connect you with one of our team. In the meantime, you can book any time at spagreen.net/book 🌿',
                'channels' => ['whatsapp', 'instagram', 'messenger', 'playground'],
                'enabled' => true,
            ]
        );

        // AI usage logs.
        if (AiRun::where('chatbot_id', $bot->id)->count() === 0) {
            $convoIds = Conversation::where('workspace_id', $this->workspaceId)->pluck('id')->all();
            for ($i = 0; $i < 44; $i++) {
                $prompt = rand(180, 900);
                $completion = rand(40, 320);
                $run = AiRun::create([
                    'chatbot_id' => $bot->id,
                    'conversation_id' => $convoIds ? Arr::random($convoIds) : null,
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'cost_cents' => (int) ceil(($prompt * 0.000015 + $completion * 0.00006) * 100),
                    'latency_ms' => rand(420, 2600),
                    'model' => 'gpt-4o-mini',
                    'status' => $i === 9 ? 'guardrail_trip' : ($i === 17 ? 'error' : 'ok'),
                ]);
                $this->bk($run, $this->days(rand(0, 29))->setTime(rand(8, 20), rand(0, 59)));
            }
        }
    }

    /* ───────────────────────────── automations ───────────────────────── */

    private function seedAutomations(): void
    {
        $bot = AiChatbot::where('workspace_id', $this->workspaceId)->first();

        $automations = [
            [
                'name' => 'New Client Welcome Flow', 'status' => 'active', 'trigger_type' => 'contact.created', 'trigger_config' => [], 'run_count' => 86,
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Contact Created', 'triggerType' => 'contact.created']],
                    ['id' => 'wait-1', 'type' => 'wait', 'position' => ['x' => 250, 'y' => 170], 'data' => ['nodeType' => 'wait', 'label' => 'Wait', 'configured' => true, 'amount' => 30, 'unit' => 'minutes']],
                    ['id' => 'send-1', 'type' => 'send_whatsapp', 'position' => ['x' => 250, 'y' => 300], 'data' => ['nodeType' => 'send_whatsapp', 'label' => 'Send WhatsApp', 'configured' => true, 'message' => '🌿 Welcome to SpaGreen, {{contact.first_name}}! Enjoy 20% off your first treatment with code WELCOME20.']],
                    ['id' => 'tag-1', 'type' => 'add_tag', 'position' => ['x' => 250, 'y' => 430], 'data' => ['nodeType' => 'add_tag', 'label' => 'Add Tag', 'configured' => true, 'tag' => 'New Lead']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'wait-1'],
                    ['id' => 'e2', 'source' => 'wait-1', 'target' => 'send-1'],
                    ['id' => 'e3', 'source' => 'send-1', 'target' => 'tag-1'],
                ],
            ],
            [
                'name' => 'AI Support Triage', 'status' => 'active', 'trigger_type' => 'message.received',
                'trigger_config' => ['keywords' => ['help', 'price', 'book', 'hours', 'membership', 'product']], 'run_count' => 213,
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Message Received', 'triggerType' => 'message.received']],
                    ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 250, 'y' => 170], 'data' => ['nodeType' => 'condition', 'label' => 'Is VIP?', 'configured' => true, 'field' => 'contact.tag', 'operator' => 'contains', 'value' => 'VIP']],
                    ['id' => 'assign-1', 'type' => 'assign_agent', 'position' => ['x' => 60, 'y' => 320], 'data' => ['nodeType' => 'assign_agent', 'label' => 'Assign to Team', 'configured' => true]],
                    ['id' => 'ai-1', 'type' => 'ai_reply', 'position' => ['x' => 440, 'y' => 320], 'data' => ['nodeType' => 'ai_reply', 'label' => 'AI Reply', 'configured' => true, 'chatbot_id' => $bot?->id, 'prompt' => 'Answer the guest using the knowledge base: {{message.body}}']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'cond-1'],
                    ['id' => 'e2', 'source' => 'cond-1', 'target' => 'assign-1', 'sourceHandle' => 'true'],
                    ['id' => 'e3', 'source' => 'cond-1', 'target' => 'ai-1', 'sourceHandle' => 'false'],
                ],
            ],
            [
                'name' => 'Abandoned Cart Recovery', 'status' => 'active', 'trigger_type' => 'cart.abandoned', 'trigger_config' => ['delay_minutes' => 60], 'run_count' => 47,
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Cart Abandoned', 'triggerType' => 'cart.abandoned']],
                    ['id' => 'wait-1', 'type' => 'wait', 'position' => ['x' => 250, 'y' => 170], 'data' => ['nodeType' => 'wait', 'label' => 'Wait', 'configured' => true, 'amount' => 1, 'unit' => 'hours']],
                    ['id' => 'send-1', 'type' => 'send_whatsapp', 'position' => ['x' => 250, 'y' => 300], 'data' => ['nodeType' => 'send_whatsapp', 'label' => 'Send WhatsApp', 'configured' => true, 'message' => 'Hi {{contact.first_name}}, you left something in your SpaGreen cart 🛍️ Complete your order and enjoy free shipping today!']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'wait-1'],
                    ['id' => 'e2', 'source' => 'wait-1', 'target' => 'send-1'],
                ],
            ],
            [
                'name' => 'Win-back Lapsed Clients', 'status' => 'paused', 'trigger_type' => 'contact.tagged', 'trigger_config' => ['tag' => 'Lapsed'], 'run_count' => 18,
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Tagged Lapsed', 'triggerType' => 'contact.tagged']],
                    ['id' => 'send-1', 'type' => 'send_whatsapp', 'position' => ['x' => 250, 'y' => 180], 'data' => ['nodeType' => 'send_whatsapp', 'label' => 'Send WhatsApp', 'configured' => true, 'message' => "We miss you, {{contact.first_name}} 💚 Here's 25% off to welcome you back to SpaGreen."]],
                ],
                'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'send-1']],
            ],
            [
                'name' => 'Birthday Greeting', 'status' => 'draft', 'trigger_type' => 'contact.birthday', 'trigger_config' => [], 'run_count' => 0,
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Birthday', 'triggerType' => 'contact.birthday']],
                    ['id' => 'send-1', 'type' => 'send_whatsapp', 'position' => ['x' => 250, 'y' => 180], 'data' => ['nodeType' => 'send_whatsapp', 'label' => 'Send WhatsApp', 'configured' => false, 'message' => 'Happy Birthday {{contact.first_name}}! 🎂 Enjoy a free add-on this month, on us.']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'send-1']],
            ],
        ];

        foreach ($automations as $data) {
            $automation = Automation::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $data['name']],
                array_merge($data, ['workspace_id' => $this->workspaceId])
            );

            if ($automation->status === 'active' && $automation->runs()->count() === 0) {
                $contactIds = $this->contacts->pluck('id')->all();
                $statuses = ['completed', 'completed', 'completed', 'failed', 'waiting', 'running', 'completed', 'completed', 'completed', 'failed', 'waiting', 'cancelled'];
                foreach ($statuses as $idx => $st) {
                    $startedAt = $this->days(rand(0, 20))->setTime(rand(8, 19), rand(0, 59));
                    $run = AutomationRun::create([
                        'automation_id' => $automation->id,
                        'contact_id' => Arr::random($contactIds),
                        'status' => $st,
                        'context' => ['source' => 'demo', 'trigger' => $automation->trigger_type],
                        'current_node_id' => $st === 'waiting' ? 'wait-1' : null,
                        'resume_node_id' => $st === 'waiting' ? 'send-1' : null,
                        'error' => $st === 'failed' ? 'WhatsApp send failed: contact is outside the 24-hour session window and no template was set.' : null,
                        'started_at' => $startedAt,
                        'completed_at' => in_array($st, ['completed', 'failed']) ? $startedAt->copy()->addMinutes(rand(1, 90)) : null,
                    ]);
                    $this->bk($run, $startedAt);

                    AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'trigger-1', 'node_type' => 'trigger', 'result' => 'ok', 'message' => 'Triggered by '.$automation->trigger_type]);
                    if ($st === 'failed') {
                        AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'send-1', 'node_type' => 'send_whatsapp', 'result' => 'error', 'message' => $run->error]);
                    } elseif ($st === 'completed') {
                        AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'send-1', 'node_type' => 'send_whatsapp', 'result' => 'ok', 'message' => 'Message sent', 'output' => ['provider_message_id' => 'wamid.'.Str::random(20)]]);
                    }
                }
            }
        }
    }

    /* ──────────────────────────── broadcasting ───────────────────────── */

    private function seedBroadcasting(): void
    {
        SmsProviderConfig::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'provider' => 'twilio'],
            ['credentials' => ['account_sid' => 'ACdemo'.Str::random(20), 'auth_token' => 'demo-token'], 'sender_id' => 'SPAGREEN', 'default' => true]
        );

        WorkspaceSmtpConfig::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'username' => 'hello@spagreen.net'],
            ['host' => 'smtp.mailgun.org', 'port' => 587, 'password' => 'demo-smtp-password', 'encryption' => 'tls', 'from_email' => 'hello@spagreen.net', 'from_name' => 'SpaGreen Wellness', 'is_active' => true]
        );

        // Usage meters for the current month period (YYYYMM).
        $period = (int) Carbon::now()->format('Ym');
        $meters = ['whatsapp_messages' => 3720, 'sms_sent' => 1284, 'emails_sent' => 4960, 'ai_tokens' => 642800, 'campaigns_sent' => 18, 'lead_credits' => 690];
        foreach ($meters as $metric => $value) {
            UsageMeter::updateOrCreate(
                ['workspace_id' => $this->workspaceId, 'metric' => $metric, 'period' => $period],
                ['value' => $value]
            );
        }

        if (Campaign::where('workspace_id', $this->workspaceId)->count() > 0) {
            return;
        }

        $optedInContacts = $this->contacts->where('opt_in_whatsapp', true)->values();

        $campaigns = [
            ['name' => "Mother's Day Spa Package", 'channel' => 'whatsapp', 'status' => 'completed', 'audience_type' => 'segment', 'audience_ref' => 'VIP Members', 'daysAgo' => 38, 'recipients' => 48, 'body' => "💐 Treat the special woman in your life this Mother's Day. Gift a SpaGreen package and she'll thank you forever. Shop now!"],
            ['name' => 'New Year Wellness Reset', 'channel' => 'whatsapp', 'status' => 'completed', 'audience_type' => 'tag', 'audience_ref' => 'Member', 'daysAgo' => 24, 'recipients' => 60, 'body' => '🌿 New year, new you. Kick off 2026 with our Wellness Reset: 3 treatments for the price of 2. Limited time!'],
            ['name' => 'Summer Glow Email Promotion', 'channel' => 'email', 'status' => 'sending', 'audience_type' => 'segment', 'audience_ref' => 'Email Newsletter Subscribers', 'daysAgo' => 0, 'recipients' => 56, 'body' => 'Get summer-ready with 20% off all facials this month.'],
            ['name' => 'Valentine’s Couples Retreat', 'channel' => 'whatsapp', 'status' => 'completed', 'audience_type' => 'segment', 'audience_ref' => 'VIP Members', 'daysAgo' => 52, 'recipients' => 44, 'body' => '💞 Share the calm this Valentine’s. Book our Couples Aromatherapy Ritual and receive a complimentary glass of bubbly.'],
            ['name' => 'Black Friday Wellness Sale', 'channel' => 'email', 'status' => 'completed', 'audience_type' => 'tag', 'audience_ref' => 'Newsletter', 'daysAgo' => 30, 'recipients' => 54, 'body' => '🖤 Our biggest sale of the year — 30% off all memberships & gift cards this weekend only.'],
            ['name' => 'Spring Detox SMS Blast', 'channel' => 'sms', 'status' => 'completed', 'audience_type' => 'tag', 'audience_ref' => 'Member', 'daysAgo' => 16, 'recipients' => 40, 'body' => 'SpaGreen Spring Detox is here 🌷 Book a body wrap this week and save 15%. Reply BOOK to reserve.'],
            ['name' => 'Flash Sale — Aromatherapy Oils', 'channel' => 'sms', 'status' => 'queued', 'audience_type' => 'tag', 'audience_ref' => 'Newsletter', 'daysAgo' => -2, 'recipients' => 0, 'body' => 'SpaGreen flash sale! 30% off all aromatherapy oils for 48h only. Shop: spagreen.net/shop'],
            ['name' => 'Membership Renewal Reminder', 'channel' => 'whatsapp', 'status' => 'draft', 'audience_type' => 'tag', 'audience_ref' => 'Member', 'daysAgo' => 0, 'recipients' => 0, 'body' => 'Hi {{contact.first_name}}, your SpaGreen membership renews soon. Manage it any time in your account.'],
            ['name' => 'Father’s Day Gift Guide', 'channel' => 'email', 'status' => 'draft', 'audience_type' => 'segment', 'audience_ref' => 'Email Newsletter Subscribers', 'daysAgo' => 0, 'recipients' => 0, 'body' => 'Give Dad the gift of calm 🎁 Explore our curated Father’s Day wellness bundles.'],
        ];

        foreach ($campaigns as $c) {
            $scheduleAt = $c['daysAgo'] !== null ? $this->days($c['daysAgo'])->setTime(10, 0) : null;
            $campaign = Campaign::create([
                'workspace_id' => $this->workspaceId,
                'name' => $c['name'],
                'channel' => $c['channel'],
                'whatsapp_phone_number_id' => $c['channel'] === 'whatsapp' ? $this->waPhoneNumberId : null,
                'audience_type' => $c['audience_type'],
                'audience_ref' => $c['audience_ref'],
                'payload_json' => ['body' => $c['body']],
                'template_ref' => $c['channel'] === 'whatsapp' ? ['name' => 'welcome_offer', 'language' => 'en'] : null,
                'schedule_at' => $scheduleAt,
                'timezone' => 'America/Los_Angeles',
                'status' => $c['status'],
                'created_by' => $this->admin->id,
            ]);
            $this->bk($campaign, $scheduleAt ?? Carbon::now());

            $recipientTargets = $optedInContacts->take($c['recipients']);
            $sent = $delivered = $read = $failed = $clicked = $optedOut = 0;
            foreach ($recipientTargets as $idx => $contact) {
                $roll = $idx % 10;
                if ($roll === 9) {
                    $rStatus = 'failed';
                    $failed++;
                } elseif ($roll >= 6) {
                    $rStatus = 'read';
                    $read++;
                    $delivered++;
                    $sent++;
                } else {
                    $rStatus = 'delivered';
                    $delivered++;
                    $sent++;
                }
                $isClicked = $rStatus === 'read' && $idx % 4 === 0;
                $isOptOut = $idx === 13;
                if ($isClicked) {
                    $clicked++;
                }
                if ($isOptOut) {
                    $optedOut++;
                }

                $sentAt = $c['status'] === 'completed' ? $scheduleAt->copy()->addMinutes($idx) : ($c['status'] === 'sending' ? Carbon::now()->subMinutes($idx) : null);
                $rec = CampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'status' => $c['status'] === 'sending' && $idx > 14 ? 'queued' : $rStatus,
                    'provider_message_id' => $rStatus !== 'failed' ? 'wamid.'.Str::random(26) : null,
                    'tracking_token' => Str::random(40),
                    'unsubscribe_token' => Str::random(40),
                    'sent_at' => $sentAt,
                    'delivered_at' => $sentAt && $rStatus !== 'failed' ? $sentAt->copy()->addSeconds(rand(3, 40)) : null,
                    'read_at' => $sentAt && $rStatus === 'read' ? $sentAt->copy()->addMinutes(rand(1, 120)) : null,
                    'clicked_at' => $isClicked && $sentAt ? $sentAt->copy()->addMinutes(rand(2, 130)) : null,
                    'opted_out_at' => $isOptOut && $sentAt ? $sentAt->copy()->addMinutes(rand(5, 200)) : null,
                    'failed_reason' => $rStatus === 'failed' ? 'Message undeliverable: recipient number not on WhatsApp.' : null,
                ]);
                if ($sentAt) {
                    $this->bk($rec, $sentAt);
                }
            }

            if ($recipientTargets->isNotEmpty()) {
                $campaign->update(['totals_json' => [
                    'audience' => $recipientTargets->count(),
                    'sent' => $sent, 'delivered' => $delivered, 'read' => $read,
                    'failed' => $failed, 'clicked' => $clicked, 'opted_out' => $optedOut,
                ]]);
            }
        }
    }

    /* ─────────────────────────────── leads ───────────────────────────── */

    private function seedLeads(): void
    {
        if (LeadScrapeJob::where('workspace_id', $this->workspaceId)->count() === 0) {
            $jobs = [
                ['keyword' => 'day spa', 'location' => 'Austin, TX', 'status' => 'done', 'leads_found' => 18, 'daysAgo' => 9],
                ['keyword' => 'wellness center', 'location' => 'Dallas, TX', 'status' => 'done', 'leads_found' => 14, 'daysAgo' => 5],
                ['keyword' => 'massage therapy', 'location' => 'Houston, TX', 'status' => 'running', 'leads_found' => 0, 'daysAgo' => 0],
                ['keyword' => 'beauty salon', 'location' => 'San Diego, CA', 'status' => 'failed', 'leads_found' => 0, 'daysAgo' => 2],
                ['keyword' => 'facial spa', 'location' => 'Phoenix, AZ', 'status' => 'done', 'leads_found' => 16, 'daysAgo' => 7],
                ['keyword' => 'med spa', 'location' => 'San Antonio, TX', 'status' => 'done', 'leads_found' => 8, 'daysAgo' => 3],
            ];
            foreach ($jobs as $j) {
                $startedAt = $this->days($j['daysAgo'])->setTime(rand(9, 16), rand(0, 59));
                $job = LeadScrapeJob::create([
                    'workspace_id' => $this->workspaceId,
                    'keyword' => $j['keyword'],
                    'location' => $j['location'],
                    'radius_meters' => 5000,
                    'status' => $j['status'],
                    'leads_found' => $j['leads_found'],
                    'error' => $j['status'] === 'failed' ? 'Google Places API quota exceeded for today.' : null,
                    'started_at' => $startedAt,
                    'completed_at' => in_array($j['status'], ['done', 'failed']) ? $startedAt->copy()->addMinutes(rand(2, 20)) : null,
                ]);
                $this->bk($job, $startedAt);
            }
        }

        if (Lead::where('workspace_id', $this->workspaceId)->count() === 0) {
            $cities = [['Austin', 'TX', 30.2672, -97.7431], ['Dallas', 'TX', 32.7767, -96.7970], ['Houston', 'TX', 29.7604, -95.3698], ['San Diego', 'CA', 32.7157, -117.1611]];
            $categories = ['Day spa', 'Wellness center', 'Massage therapist', 'Beauty salon', 'Facial spa'];
            $prefixes = ['Serenity', 'Tranquil', 'Bliss', 'Lotus', 'Harmony', 'Pure', 'Oasis', 'Radiance', 'Calm', 'Verde', 'Aura', 'Nirvana'];
            $suffixes = ['Spa', 'Wellness', 'Retreat', 'Day Spa', 'Spa & Salon', 'Beauty Bar'];
            for ($i = 0; $i < 56; $i++) {
                [$city, $state, $lat, $lng] = $cities[$i % count($cities)];
                $name = Arr::random($prefixes).' '.Arr::random($suffixes);
                $lead = Lead::create([
                    'workspace_id' => $this->workspaceId,
                    'name' => $name,
                    'phone' => '+1'.rand(200, 989).rand(2000000, 9999999),
                    'email' => 'info@'.Str::slug($name, '').'.com',
                    'website' => 'https://'.Str::slug($name, '').'.com',
                    'address' => rand(100, 9999).' '.Arr::random(['Main St', 'Oak Ave', 'Congress Ave', 'Elm St', 'Sunset Blvd']),
                    'city' => $city,
                    'country' => 'US',
                    'lat' => $lat + (rand(-50, 50) / 1000),
                    'lng' => $lng + (rand(-50, 50) / 1000),
                    'category' => Arr::random($categories),
                    'rating' => round(rand(35, 50) / 10, 1),
                    'review_count' => rand(8, 740),
                    'google_place_id' => 'ChIJ'.Str::random(23),
                    'whatsapp_status' => Arr::random(['unknown', 'valid', 'valid', 'invalid']),
                    'pushed_to_contacts' => $i < 4,
                ]);
                $this->bk($lead, $this->days(rand(0, 9)));
            }
        }
    }

    /* ─────────────────────────────── social ──────────────────────────── */

    private function seedSocial(): void
    {
        $accounts = [
            ['network' => 'instagram', 'account_id' => '17841400000111222', 'name' => '@spagreenwellness'],
            ['network' => 'facebook', 'account_id' => '556677889900112', 'name' => 'SpaGreen Wellness'],
            ['network' => 'linkedin', 'account_id' => 'spagreen-wellness', 'name' => 'SpaGreen Wellness'],
        ];
        $accountModels = [];
        foreach ($accounts as $a) {
            $accountModels[$a['network']] = SocialAccount::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'network' => $a['network'], 'account_id' => $a['account_id']],
                [
                    'name' => $a['name'],
                    'picture_url' => 'https://ui-avatars.com/api/?name=SpaGreen+Wellness&background=0E7C5A&color=ffffff&size=256&bold=true',
                    'access_token' => 'DEMO_'.strtoupper($a['network']).'_TOKEN',
                    'token_expires_at' => Carbon::now()->addDays(50),
                    'scopes' => ['pages_manage_posts', 'pages_read_engagement'],
                    'active' => true,
                ]
            );
        }

        if (SocialPost::where('workspace_id', $this->workspaceId)->count() > 0) {
            return;
        }

        $posts = [
            ['title' => 'Self-care Sunday', 'body' => "There's no better way to end the week than with a hot stone massage. 🌿 Book your Sunday reset at SpaGreen. #selfcaresunday #spaday", 'status' => 'published', 'daysAgo' => 6, 'networks' => ['instagram', 'facebook'], 'ai' => false],
            ['title' => 'New Aromatherapy Oils', 'body' => 'Our new Lavender Calm Body Oil has landed 💜 Naturally calming, vegan, and perfect for sensitive skin. Available in-spa and online.', 'status' => 'published', 'daysAgo' => 4, 'networks' => ['instagram', 'facebook'], 'ai' => false],
            ['title' => 'Meet the Team — Priya', 'body' => 'Say hello to Priya, our lead therapist with 12 years of experience in deep tissue and aromatherapy. 💚 #meettheteam', 'status' => 'published', 'daysAgo' => 2, 'networks' => ['instagram', 'linkedin'], 'ai' => true],
            ['title' => 'Summer Glow Promo', 'body' => '☀️ Summer Glow is here! 20% off all facials this month. Tap the link in bio to book.', 'status' => 'scheduled', 'daysAgo' => -1, 'networks' => ['instagram', 'facebook'], 'ai' => true],
            ['title' => 'Corporate Wellness Days', 'body' => 'Bring calm to your team. SpaGreen now offers on-site corporate wellness afternoons. Get in touch for a tailored quote.', 'status' => 'scheduled', 'daysAgo' => -3, 'networks' => ['linkedin'], 'ai' => false],
            ['title' => 'Behind the scenes', 'body' => 'A peek inside our relaxation lounge ✨ #spalife', 'status' => 'draft', 'daysAgo' => 0, 'networks' => ['instagram'], 'ai' => false],
            ['title' => 'Failed publish example', 'body' => 'Limited bridal slots for September — DM to reserve! 💍', 'status' => 'failed', 'daysAgo' => 1, 'networks' => ['instagram', 'facebook'], 'ai' => false],
            ['title' => 'Client Love — 5 Star Review', 'body' => '“The most relaxing 90 minutes of my month.” ⭐⭐⭐⭐⭐ Thank you for the kind words! #clientlove', 'status' => 'published', 'daysAgo' => 8, 'networks' => ['instagram', 'facebook'], 'ai' => false],
            ['title' => 'Wellness Tip Tuesday', 'body' => '💧 Tip: drink a glass of water before and after your massage to help flush toxins. #wellnesstip', 'status' => 'published', 'daysAgo' => 10, 'networks' => ['instagram'], 'ai' => true],
            ['title' => 'New Rose Quartz Roller', 'body' => 'Cool, calm, glow ✨ Our new Rose Quartz Facial Roller is now in the boutique.', 'status' => 'published', 'daysAgo' => 12, 'networks' => ['instagram', 'facebook'], 'ai' => false],
            ['title' => 'Mid-week Reset', 'body' => 'Halfway through the week? Treat yourself to a lunchtime express facial. 30 minutes to glow. 🌿', 'status' => 'scheduled', 'daysAgo' => -2, 'networks' => ['instagram', 'facebook'], 'ai' => true],
            ['title' => 'Gift Card Reminder', 'body' => 'Stuck for a gift? A SpaGreen gift card is always the right size 🎁', 'status' => 'scheduled', 'daysAgo' => -5, 'networks' => ['facebook'], 'ai' => false],
            ['title' => 'Meet the Team — Marcus', 'body' => 'Marcus runs our front desk and makes sure every visit starts with a smile. 😊 #meettheteam', 'status' => 'draft', 'daysAgo' => 0, 'networks' => ['instagram', 'linkedin'], 'ai' => false],
            ['title' => 'Hiring — Massage Therapist', 'body' => 'We’re growing! SpaGreen is hiring a licensed massage therapist. Apply via the link in bio.', 'status' => 'published', 'daysAgo' => 14, 'networks' => ['linkedin', 'facebook'], 'ai' => false],
        ];

        foreach ($posts as $pi => $p) {
            $scheduledAt = $p['daysAgo'] !== null ? $this->days($p['daysAgo'])->setTime(11, 30) : null;
            $publishedAt = $p['status'] === 'published' ? $scheduledAt : null;
            $post = SocialPost::create([
                'workspace_id' => $this->workspaceId,
                'title' => $p['title'],
                'body' => $p['body'],
                'media_urls' => ['https://loremflickr.com/1080/1080/spa,wellness?lock='.($pi + 21)],
                'target_accounts' => array_map(fn ($n) => $accountModels[$n]->id, $p['networks']),
                'status' => $p['status'],
                'scheduled_at' => $scheduledAt,
                'timezone' => 'America/Los_Angeles',
                'published_at' => $publishedAt,
                'provider_post_id' => $publishedAt ? Str::random(18) : null,
                'post_url' => $publishedAt ? 'https://instagram.com/p/'.Str::random(11) : null,
                'ai_generated' => $p['ai'],
                'ai_prompt' => $p['ai'] ? 'Write a warm, on-brand social post for a premium spa about: '.$p['title'] : null,
            ]);
            $this->bk($post, $scheduledAt ?? Carbon::now());

            foreach ($p['networks'] as $n) {
                $acct = $accountModels[$n];
                $accStatus = match ($p['status']) {
                    'published' => 'published',
                    'failed' => 'failed',
                    default => 'pending',
                };
                SocialPostAccount::create([
                    'post_id' => $post->id,
                    'social_account_id' => $acct->id,
                    'status' => $accStatus,
                    'platform_post_id' => $accStatus === 'published' ? Str::random(16) : null,
                    'error' => $accStatus === 'failed' ? 'Token expired — please reconnect the account.' : null,
                    'published_at' => $accStatus === 'published' ? $publishedAt : null,
                ]);
            }
        }
    }

    /* ───────────────────────────── ecommerce ─────────────────────────── */

    private function seedEcommerce(): void
    {
        $store = EcommerceStore::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'platform' => 'shopify', 'domain' => 'spagreen-boutique.myshopify.com'],
            [
                'name' => 'SpaGreen Boutique',
                'credentials' => ['access_token' => 'shpat_demo'.Str::random(24), 'api_key' => 'demo'],
                'status' => 'connected',
                'webhook_secret' => Str::random(32),
                'last_tested_at' => $this->days(1),
                'last_test_status' => 'ok',
                'last_test_message' => 'Connection successful.',
                'customers_synced_at' => $this->days(1),
                'orders_synced_at' => Carbon::now()->subHours(3),
                'products_synced_at' => Carbon::now()->subHours(3),
            ]
        );

        $products = [
            ['Lavender Calm Body Oil', 'SG-OIL-LAV', 32.00, 84, 'oil'],
            ['Eucalyptus Recovery Oil', 'SG-OIL-EUC', 34.00, 56, 'oil'],
            ['Detox Clay Mask', 'SG-MASK-CLAY', 28.00, 120, 'skincare'],
            ['Hydra Glow Serum', 'SG-SERUM-GLOW', 48.00, 38, 'skincare'],
            ['Soy Wellness Candle — Jasmine', 'SG-CANDLE-JAS', 24.00, 200, 'home'],
            ['Soy Wellness Candle — Sandalwood', 'SG-CANDLE-SAN', 24.00, 18, 'home'],
            ['Bamboo Bathrobe', 'SG-ROBE-BAM', 89.00, 24, 'apparel'],
            ['Jade Facial Roller', 'SG-TOOL-JADE', 36.00, 0, 'tools'],
            ['Gua Sha Stone', 'SG-TOOL-GUA', 22.00, 65, 'tools'],
            ['Herbal Bath Soak', 'SG-BATH-HERB', 26.00, 90, 'bath'],
            ['Gift Card — $100', 'SG-GIFT-100', 100.00, 999, 'gift'],
            ['SpaGreen Signature Gift Set', 'SG-SET-SIG', 120.00, 30, 'set'],
            ['Rose Quartz Facial Roller', 'SG-TOOL-ROSE', 38.00, 44, 'tools'],
            ['Peppermint Foot Balm', 'SG-BALM-PEP', 18.00, 110, 'bath'],
            ['Vitamin C Brightening Serum', 'SG-SERUM-VITC', 52.00, 26, 'skincare'],
            ['Overnight Repair Sleep Mask', 'SG-MASK-SLEEP', 44.00, 0, 'skincare'],
            ['Aromatherapy Pillow Mist', 'SG-MIST-PILLOW', 21.00, 130, 'home'],
            ['Soy Wellness Candle — Eucalyptus', 'SG-CANDLE-EUC', 24.00, 72, 'home'],
            ['Organic Cotton Headband', 'SG-ACC-BAND', 12.00, 160, 'apparel'],
            ['Dry Body Brush', 'SG-TOOL-BRUSH', 19.00, 58, 'tools'],
            ['Magnesium Bath Flakes', 'SG-BATH-MAG', 29.00, 64, 'bath'],
            ['Hyaluronic Hydra Mist', 'SG-MIST-HYA', 33.00, 41, 'skincare'],
            ['Gift Card — $50', 'SG-GIFT-50', 50.00, 999, 'gift'],
            ['SpaGreen Deluxe Pamper Box', 'SG-SET-DELUXE', 185.00, 16, 'set'],
        ];
        // Relevant, always-valid product imagery keyed by category (deterministic via ?lock).
        $catKeyword = [
            'oil' => 'massage,oil', 'skincare' => 'skincare,cosmetics', 'home' => 'candle',
            'apparel' => 'bathrobe,spa', 'tools' => 'facial,roller', 'bath' => 'bath,spa',
            'gift' => 'gift,box', 'set' => 'spa,gift',
        ];
        $productModels = [];
        foreach ($products as $i => [$name, $sku, $price, $inv, $cat]) {
            $keyword = $catKeyword[$cat] ?? 'spa,wellness';
            $productModels[] = EcommerceProduct::firstOrCreate(
                ['store_id' => $store->id, 'external_id' => 'prod_'.(1000 + $i)],
                [
                    'workspace_id' => $this->workspaceId,
                    'platform' => 'shopify',
                    'name' => $name,
                    'sku' => $sku,
                    'price' => $price,
                    'inventory_quantity' => $inv,
                    'status' => $inv > 0 ? 'active' : 'out_of_stock',
                    'image_url' => 'https://loremflickr.com/600/600/'.$keyword.'?lock='.($i + 1),
                    'raw' => ['category' => $cat, 'vendor' => 'SpaGreen'],
                ]
            );
        }

        if (EcommerceOrder::where('store_id', $store->id)->count() === 0) {
            $contacts = $this->contacts->take(40)->values();
            $financialStates = ['paid', 'paid', 'paid', 'pending', 'refunded'];
            $fulfilStates = ['fulfilled', 'fulfilled', 'unfulfilled', 'partial'];
            for ($i = 0; $i < 32; $i++) {
                $contact = $contacts[$i % $contacts->count()];
                $lineItems = [];
                $total = 0;
                foreach (Arr::random($productModels, rand(1, 3)) as $prod) {
                    $qty = rand(1, 2);
                    $total += $prod->price * $qty;
                    $lineItems[] = ['name' => $prod->name, 'sku' => $prod->sku, 'quantity' => $qty, 'price' => (float) $prod->price];
                }
                $placedAt = $this->days(rand(0, 60))->setTime(rand(8, 21), rand(0, 59));
                $fin = Arr::random($financialStates);
                $order = EcommerceOrder::create([
                    'workspace_id' => $this->workspaceId,
                    'store_id' => $store->id,
                    'contact_id' => $contact->id,
                    'external_order_id' => 'order_'.(5000 + $i),
                    'platform' => 'shopify',
                    'number' => '#'.(1450 + $i),
                    'status' => $fin === 'refunded' ? 'cancelled' : 'open',
                    'financial_status' => $fin,
                    'fulfillment_status' => Arr::random($fulfilStates),
                    'currency' => 'USD',
                    'total' => round($total, 2),
                    'line_items' => $lineItems,
                    'tracking_number' => rand(0, 1) ? 'USPS'.rand(10000000, 99999999) : null,
                    'tracking_url' => null,
                    'placed_at' => $placedAt,
                    'raw' => ['gateway' => 'shopify_payments'],
                ]);
                $this->bk($order, $placedAt);
            }
        }

        if (EcommerceCart::where('store_id', $store->id)->count() === 0) {
            $contacts = $this->contacts->where('opt_in_whatsapp', true)->take(16)->values();
            for ($i = 0; $i < 12; $i++) {
                $contact = $contacts[$i % max(1, $contacts->count())];
                $prod = $productModels[array_rand($productModels)];
                $abandonedAt = $this->days(rand(0, 14))->setTime(rand(9, 22), rand(0, 59));
                $recovered = $i < 2;
                $cart = EcommerceCart::create([
                    'workspace_id' => $this->workspaceId,
                    'store_id' => $store->id,
                    'contact_id' => $contact->id,
                    'external_id' => 'cart_'.(7000 + $i),
                    'total' => (float) $prod->price,
                    'currency' => 'USD',
                    'line_items' => [['name' => $prod->name, 'quantity' => 1, 'price' => (float) $prod->price]],
                    'recovery_url' => 'https://spagreen-boutique.myshopify.com/cart/recover/'.Str::random(20),
                    'abandoned_at' => $abandonedAt,
                    'recovery_triggered_at' => $abandonedAt->copy()->addHour(),
                    'recovered_at' => $recovered ? $abandonedAt->copy()->addHours(rand(2, 30)) : null,
                ]);
                $this->bk($cart, $abandonedAt);

                if ($recovered) {
                    DB::table('contact_tag_pivot')->insertOrIgnore(['contact_id' => $contact->id, 'tag_id' => $this->tags['Abandoned Cart']]);
                }
            }
        }
    }

    /* ─────────────────────────────── support ─────────────────────────── */

    private function seedSupport(): void
    {
        if (SupportTicket::where('user_id', $this->admin->id)->count() > 0) {
            return;
        }

        $tickets = [
            ['subject' => 'WhatsApp template stuck in review', 'message' => "Hi, our 'monthly_promo_june' template has been pending approval for 3 days. Is that normal?", 'status' => 'in_progress', 'priority' => 'high', 'daysAgo' => 3, 'replies' => [
                ['staff', "Hi Olivia, thanks for reaching out! Marketing templates can take 24–48h, occasionally longer during busy periods. I've flagged it with our Meta partner team and will update you shortly."],
                ['user', 'Thank you, appreciate the quick response!'],
            ]],
            ['subject' => 'How do I export my contacts?', 'message' => 'Is there a way to export all contacts to CSV including their tags?', 'status' => 'closed', 'priority' => 'normal', 'daysAgo' => 12, 'replies' => [
                ['staff', 'Absolutely! Head to Contacts → Export. You can choose to include tags and custom fields in the CSV. Let me know if you need anything else 🌿'],
            ]],
            ['subject' => 'Billing — switch to annual plan', 'message' => "We'd like to move our Business plan to annual billing to save. How do we do that?", 'status' => 'open', 'priority' => 'normal', 'daysAgo' => 1, 'replies' => []],
            ['subject' => 'Instagram DMs not syncing', 'message' => 'Some Instagram messages are not appearing in the inbox since this morning.', 'status' => 'open', 'priority' => 'urgent', 'daysAgo' => 0, 'replies' => [
                ['staff', "Sorry about that! We're aware of a brief Meta webhook delay affecting some accounts. It should auto-recover within the hour. I'll keep you posted."],
            ]],
            ['subject' => 'Add a second WhatsApp number', 'message' => 'We want to connect a second location’s WhatsApp number. Is that supported on our plan?', 'status' => 'in_progress', 'priority' => 'normal', 'daysAgo' => 5, 'replies' => [
                ['staff', 'Great news — your Business plan supports unlimited WhatsApp numbers. Head to Channels → Connect WhatsApp to add the second one. Happy to hop on a call if helpful!'],
                ['user', 'Perfect, we’ll give it a go. Thanks!'],
            ]],
            ['subject' => 'Customise the chat widget colour', 'message' => 'Can we match the website chat bubble to our brand green?', 'status' => 'closed', 'priority' => 'low', 'daysAgo' => 20, 'replies' => [
                ['staff', 'Absolutely! Go to WhatsApp → Widget and set the Button Colour to your hex code (#0E7C5A). It updates live on your site. 🌿'],
            ]],
            ['subject' => 'Automation not firing on new contacts', 'message' => 'Our welcome flow doesn’t seem to trigger for contacts added via CSV import.', 'status' => 'in_progress', 'priority' => 'high', 'daysAgo' => 2, 'replies' => [
                ['staff', 'Thanks for flagging — imported contacts don’t fire the contact.created trigger by default to avoid mass-messaging. I can enable it for your import; want me to switch it on?'],
            ]],
            ['subject' => 'Request: export campaign analytics', 'message' => 'Would love a CSV export of campaign delivery and click stats for our monthly report.', 'status' => 'open', 'priority' => 'normal', 'daysAgo' => 1, 'replies' => []],
        ];

        foreach ($tickets as $t) {
            $createdAt = $this->days($t['daysAgo'])->setTime(rand(9, 17), rand(0, 59));
            $ticket = SupportTicket::create([
                'user_id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'subject' => $t['subject'],
                'message' => $t['message'],
                'status' => $t['status'],
                'priority' => $t['priority'],
            ]);
            $this->bk($ticket, $createdAt);

            $cursor = $createdAt->copy();
            foreach ($t['replies'] as [$who, $body]) {
                $cursor = $cursor->copy()->addHours(rand(1, 8));
                $reply = SupportReply::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $who === 'staff' ? null : $this->admin->id,
                    'author_name' => $who === 'staff' ? 'SpaGreen Support' : $this->admin->name,
                    'is_staff' => $who === 'staff',
                    'message' => $body,
                ]);
                $this->bk($reply, $cursor);
            }
        }

        // A few public contact-form submissions (global, not client-scoped).
        ContactMessage::firstOrCreate(['email' => 'partnerships@glowco.com', 'subject' => 'Wholesale enquiry'], [
            'name' => 'Rachel Kim', 'message' => "Hi, we'd love to stock SpaGreen products in our boutique chain. Who can I speak to?", 'status' => 'new', 'ip_address' => '203.0.113.42',
        ]);
        ContactMessage::firstOrCreate(['email' => 'press@wellnessmag.com', 'subject' => 'Feature request'], [
            'name' => 'Tom Avery', 'message' => "We're writing about top Bay Area spas and would love to feature SpaGreen.", 'status' => 'read', 'ip_address' => '198.51.100.7',
        ]);
    }

    /* ─────────────────────────────── webhooks ────────────────────────── */

    private function seedWebhooks(): void
    {
        $endpoint = WebhookEndpoint::firstOrCreate(
            ['user_id' => $this->admin->id, 'url' => 'https://hooks.example.com/incoming/webhook'],
            [
                'secret' => WebhookEndpoint::generateSecret(),
                'events' => ['message.received', 'message.status', 'conversation.assigned', 'contact.created'],
                'enabled' => true,
                'description' => 'Sync inbound messages into our internal CRM.',
            ]
        );

        if ($endpoint->deliveries()->count() === 0) {
            $events = ['message.received', 'message.status', 'contact.created', 'conversation.assigned'];
            for ($i = 0; $i < 16; $i++) {
                $when = $this->days(rand(0, 14))->setTime(rand(8, 20), rand(0, 59));
                $ok = $i % 4 !== 0;
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => Arr::random($events),
                    'payload' => ['id' => Str::uuid()->toString(), 'demo' => true],
                    'response_status' => $ok ? 200 : 500,
                    'response_body' => $ok ? '{"ok":true}' : 'Internal Server Error',
                    'attempts' => $ok ? 1 : 3,
                    'delivered_at' => $ok ? $when : null,
                    'next_retry_at' => $ok ? null : $when->copy()->addMinutes(15),
                ]);
                $this->bk($delivery, $when);
            }
        }
    }

    /* ──────────────────────────────── misc ───────────────────────────── */

    private function seedMisc(): void
    {
        $steps = [
            ['connect_channel', true], ['import_contacts', true], ['create_template', true],
            ['invite_team', true], ['build_automation', true], ['launch_campaign', true], ['connect_store', false],
        ];
        foreach ($steps as [$step, $done]) {
            OnboardingStep::updateOrCreate(
                ['user_id' => $this->admin->id, 'step' => $step],
                ['completed' => $done, 'completed_at' => $done ? $this->days(rand(60, 200)) : null]
            );
        }

        $prefs = [
            ['email', 'new_message', true], ['email', 'campaign_complete', true], ['email', 'weekly_digest', true],
            ['database', 'new_message', true], ['database', 'conversation_assigned', true], ['email', 'billing_receipt', false],
        ];
        foreach ($prefs as [$channel, $event, $enabled]) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $this->admin->id, 'channel' => $channel, 'event' => $event],
                ['enabled' => $enabled]
            );
        }

        PushSubscription::firstOrCreate(
            ['user_id' => $this->admin->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-'.Str::random(24)],
            ['p256dh_key' => Str::random(80), 'auth_key' => Str::random(22), 'ua' => 'Mozilla/5.0 (Macintosh) Chrome/124 Safari/537.36']
        );

        Invitation::firstOrCreate(
            ['client_id' => $this->client->id, 'email' => 'sofia@spagreen.net'],
            [
                'role' => User::ROLE_CLIENT,
                'client_role' => User::CLIENT_ROLE_STAFF,
                'token' => Str::random(48),
                'invited_by' => $this->admin->id,
                'expires_at' => Carbon::now()->addDays(7),
            ]
        );

        // Media library entries.
        $media = [
            ['spagreen-welcome.jpg', 'image/jpeg', 184320, 'campaign-assets'],
            ['summer-glow-banner.png', 'image/png', 240128, 'campaign-assets'],
            ['treatment-menu-2026.pdf', 'application/pdf', 612000, 'documents'],
            ['lavender-oil.jpg', 'image/jpeg', 98304, 'product-images'],
        ];
        foreach ($media as [$file, $mime, $size, $collection]) {
            Media::firstOrCreate(
                ['path' => 'media/spagreen/'.$file],
                ['disk' => 'public', 'filename' => $file, 'mime_type' => $mime, 'size_bytes' => $size, 'collection' => $collection, 'meta' => ['uploaded_by' => $this->admin->name]]
            );
        }

        // Client-scoped audit trail.
        if (AuditLog::where('client_id', $this->client->id)->count() === 0) {
            $actions = [
                ['campaign.launched', 'Launched campaign "New Year Wellness Reset"'],
                ['template.created', 'Created WhatsApp template "appointment_reminder"'],
                ['contact.imported', 'Imported 30 contacts from CSV'],
                ['team.invited', 'Invited sofia@spagreen.net as staff'],
                ['settings.updated', 'Updated business hours'],
            ];
            foreach ($actions as [$action, $desc]) {
                $log = AuditLog::create([
                    'user_id' => $this->admin->id,
                    'client_id' => $this->client->id,
                    'action' => $action,
                    'meta' => ['description' => $desc],
                    'ip' => '198.51.100.'.rand(2, 250),
                    'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/124',
                    'url' => 'https://webwithai.test/app',
                ]);
                $this->bk($log, $this->days(rand(1, 30)));
            }
        }
    }

    private function seedIntegrationsAudit(): void
    {
        $configs = IntegrationConfig::query()->limit(2)->get();
        foreach ($configs as $cfg) {
            IntegrationAuditLog::firstOrCreate(
                ['integration_config_id' => $cfg->id, 'action' => 'update'],
                ['admin_user_id' => null, 'provider' => $cfg->provider, 'diff_json' => ['enabled' => [false, true]], 'ip' => '203.0.113.10', 'user_agent' => 'Mozilla/5.0', 'created_at' => $this->days(rand(5, 40))]
            );
        }
    }

    /* ─────────────────────────── secondary clients ───────────────────── */

    private function seedSecondaryClients(): void
    {
        $clients = [
            ['name' => 'Bella Salon & Spa', 'email' => 'owner@bellasalon.com', 'admin' => 'Bella Martinez', 'plan' => 'pro', 'color' => '#be185d'],
            ['name' => 'Zen Yoga Studio', 'email' => 'hello@zenyoga.studio', 'admin' => 'Aanya Patel', 'plan' => 'starter', 'color' => '#7c3aed'],
            ['name' => 'Lumière Skin Clinic', 'email' => 'contact@lumiereskin.com', 'admin' => 'Camille Laurent', 'plan' => 'business', 'color' => '#0891b2'],
            ['name' => 'Peak Performance Physio', 'email' => 'hello@peakphysio.com', 'admin' => 'Derek Mwangi', 'plan' => 'pro', 'color' => '#ea580c'],
        ];

        foreach ($clients as $ci => $c) {
            $client = Client::firstOrCreate(
                ['email' => $c['email']],
                ['name' => $c['name'], 'status' => Client::STATUS_ACTIVE, 'base_currency' => 'USD', 'currency_symbol' => '$', 'currency_position' => 'before', 'primary_color' => $c['color']]
            );

            $admin = User::updateOrCreate(
                ['email' => $c['email']],
                [
                    'name' => $c['admin'],
                    'password' => 'password',
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'client_id' => $client->id,
                    'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                ]
            );
            if (! $admin->hasVerifiedEmail()) {
                $admin->markEmailAsVerified();
            }
            app(ClientWorkspaceService::class)->syncClientUser($admin->fresh());
            $admin->refresh();
            $wid = (int) $admin->workspace_id;

            $plan = Plan::where('slug', $c['plan'])->first();
            if ($plan) {
                ClientSubscription::firstOrCreate(
                    ['client_id' => $client->id, 'plan_id' => $plan->id, 'status' => ClientSubscription::STATUS_ACTIVE],
                    ['billing_cycle' => ClientSubscription::BILLING_MONTHLY, 'starts_at' => $this->days(rand(40, 120)), 'ends_at' => Carbon::now()->addDays(rand(5, 25))]
                );
            }

            // A WhatsApp channel + a handful of contacts + one conversation each.
            $channel = ChannelAccount::firstOrCreate(
                ['workspace_id' => $wid, 'channel' => 'whatsapp', 'display_name' => $c['name']],
                ['provider' => 'meta', 'credentials' => ['system_user_token' => 'DEMO'], 'status' => 'active', 'phone_number_id' => (string) rand(100000000000000, 999999999999999)]
            );

            for ($i = 0; $i < 16; $i++) {
                $contact = Contact::firstOrCreate(
                    ['workspace_id' => $wid, 'phone_e164' => '+1310555'.str_pad((string) (2000 + $ci * 100 + $i), 4, '0', STR_PAD_LEFT)],
                    ['first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => fake()->unique()->safeEmail(), 'country' => 'US', 'opt_in_whatsapp' => true, 'source' => Arr::random(['whatsapp', 'website', 'walk-in'])]
                );
                $this->bk($contact, $this->days(rand(5, 90)));

                if ($i === 0 && Conversation::where('workspace_id', $wid)->doesntExist()) {
                    $convo = Conversation::create([
                        'workspace_id' => $wid,
                        'channel_account_id' => $channel->id,
                        'contact_id' => $contact->id,
                        'status' => 'open',
                        'assigned_to' => 'bot',
                        'unread_count' => 1,
                        'last_message_at' => Carbon::now()->subHours(2),
                        'last_inbound_at' => Carbon::now()->subHours(2),
                    ]);
                    Message::create(['conversation_id' => $convo->id, 'direction' => 'in', 'channel' => 'whatsapp', 'type' => 'text', 'body' => 'Hi, are you open today?', 'status' => 'delivered', 'sent_by' => 'human', 'sent_at' => Carbon::now()->subHours(2)]);
                    Message::create(['conversation_id' => $convo->id, 'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'text', 'body' => 'Hello! Yes, we are open until 7pm today 😊', 'status' => 'delivered', 'sent_by' => 'bot', 'sent_at' => Carbon::now()->subHours(2)->addMinutes(3)]);
                }
            }
        }
    }
}
