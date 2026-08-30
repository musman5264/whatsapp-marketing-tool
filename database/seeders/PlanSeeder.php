<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Perfect for individuals and small projects.',
                'currency_code' => 'USD',
                'price_cents' => 0,
                'interval' => 'month',
                'monthly_price_cents' => 0,
                'yearly_price_cents' => 0,
                'trial_days' => 0,
                'features' => ['Up to 2 team members', '5 GB storage', 'Email support'],
                'limits' => [
                    'users' => 2,
                    'storage' => 5120,
                    // WhatsApp
                    'whatsapp_accounts' => 1,
                    'whatsapp_templates' => 10,
                    'whatsapp_messages_per_month' => 1000,
                    // Broadcasting
                    'campaigns_per_month' => 5,
                    'sms_per_month' => 500,
                    'emails_per_month' => 1000,
                    // Inbox
                    'inbox_agents' => 2,
                    // AI
                    'ai_tokens_per_month' => 100000,
                    'knowledge_bases' => 1,
                    'chatbots' => 1,
                    // Social
                    'social_accounts' => 2,
                    'social_posts_per_month' => 30,
                    // Leads
                    'lead_credits_per_month' => 200,
                    // Automations
                    'automations' => 3,
                ],
                'white_label_enabled' => false,
                'sort_order' => 1,
                'enabled' => true,
                'featured' => false,
                'popular' => false,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For growing teams that need more power.',
                'currency_code' => 'USD',
                'price_cents' => 2900,
                'interval' => 'month',
                'monthly_price_cents' => 2900,
                'yearly_price_cents' => 29000,
                'trial_days' => 14,
                'features' => ['Up to 10 team members', '50 GB storage', 'Priority support', 'Advanced analytics'],
                'limits' => [
                    'users' => 10,
                    'storage' => 51200,
                    'whatsapp_accounts' => 3,
                    'whatsapp_templates' => 50,
                    'whatsapp_messages_per_month' => 20000,
                    'campaigns_per_month' => 30,
                    'sms_per_month' => 10000,
                    'emails_per_month' => 25000,
                    'inbox_agents' => 10,
                    'ai_tokens_per_month' => 2000000,
                    'knowledge_bases' => 5,
                    'chatbots' => 5,
                    'social_accounts' => 10,
                    'social_posts_per_month' => 200,
                    'lead_credits_per_month' => 2000,
                    'automations' => 20,
                ],
                'white_label_enabled' => false,
                'sort_order' => 2,
                'enabled' => true,
                'featured' => false,
                'popular' => true,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Enterprise-grade features for large organisations.',
                'currency_code' => 'USD',
                'price_cents' => 9900,
                'interval' => 'month',
                'monthly_price_cents' => 9900,
                'yearly_price_cents' => 99000,
                'trial_days' => 14,
                'features' => ['Unlimited team members', '500 GB storage', 'Dedicated support', 'White-label branding', 'Custom domain', 'SLA'],
                'limits' => [
                    'users' => null,
                    'storage' => 512000,
                    'whatsapp_accounts' => null,
                    'whatsapp_templates' => null,
                    'whatsapp_messages_per_month' => null,
                    'campaigns_per_month' => null,
                    'sms_per_month' => null,
                    'emails_per_month' => null,
                    'inbox_agents' => null,
                    'ai_tokens_per_month' => null,
                    'knowledge_bases' => null,
                    'chatbots' => null,
                    'social_accounts' => null,
                    'social_posts_per_month' => null,
                    'lead_credits_per_month' => null,
                    'automations' => null,
                ],
                'white_label_enabled' => true,
                'sort_order' => 3,
                'enabled' => true,
                'featured' => true,
                'popular' => false,
            ],
        ];

        foreach ($plans as $row) {
            Plan::updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
