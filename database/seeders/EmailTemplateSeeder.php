<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // ── Auth ────────────────────────────────────────────────────────
            [
                'name'    => 'Welcome',
                'slug'    => 'welcome',
                'subject' => 'Welcome to {{app_name}}!',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Welcome to {{app_name}}! We're excited to have you on board.</p><p><a href=\"{{login_url}}\">Log in to your account</a></p><p>— The {{app_name}} Team</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a new user registers.',
                    'placeholders' => ['app_name', 'user_name', 'login_url'],
                ],
            ],
            [
                'name'    => 'Password Reset',
                'slug'    => 'reset_password',
                'subject' => 'Reset your {{app_name}} password',
                'type'    => 'email',
                'content' => "<p>Hello,</p><p>You requested a password reset. Click the link below to reset your password:</p><p><a href=\"{{reset_url}}\">Reset Password</a></p><p>This link expires in 60 minutes. If you did not request this, please ignore this email.</p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a user requests a password reset.',
                    'placeholders' => ['app_name', 'reset_url'],
                ],
            ],
            [
                'name'    => 'Email Verification',
                'slug'    => 'email_verification',
                'subject' => 'Verify your {{app_name}} account',
                'type'    => 'email',
                'content' => "<p>Hello,</p><p>Please verify your email address by clicking the link below:</p><p><a href=\"{{verification_url}}\">Verify Email</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent to verify a user\'s email address.',
                    'placeholders' => ['app_name', 'verification_url'],
                ],
            ],
            [
                'name'    => 'Magic Link Login',
                'slug'    => 'magic_link',
                'subject' => 'Your magic login link for {{app_name}}',
                'type'    => 'email',
                'content' => "<p>Hello,</p><p>Click the link below to log in to {{app_name}} (expires in {{expires_minutes}} minutes):</p><p><a href=\"{{magic_link_url}}\">Log in to {{app_name}}</a></p><p>If you did not request this, ignore this email.</p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a user requests a magic login link.',
                    'placeholders' => ['app_name', 'magic_link_url', 'expires_minutes'],
                ],
            ],
            [
                'name'    => 'Team Invitation',
                'slug'    => 'team_invitation',
                'subject' => 'You\'ve been invited to join {{organization_name}}',
                'type'    => 'email',
                'content' => "<p>Hello,</p><p>{{inviter_name}} has invited you to join {{organization_name}} on {{app_name}}.</p><p><a href=\"{{invitation_url}}\">Accept Invitation</a></p><p>This invitation expires in {{expires_days}} days.</p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a user is invited to join an organization.',
                    'placeholders' => ['app_name', 'inviter_name', 'organization_name', 'invitation_url', 'expires_days'],
                ],
            ],

            // ── Billing ─────────────────────────────────────────────────────
            [
                'name'    => 'Payment Failed',
                'slug'    => 'payment_failed',
                'subject' => 'Payment Failed - Action Required',
                'type'    => 'email',
                'content' => "<p>Hello {{user_name}},</p><p>Your payment of <strong>{{amount}} {{currency}}</strong> could not be processed.</p><p>Please update your payment method to avoid service interruption.</p><p><a href=\"{{billing_url}}\">Update Payment Method</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a subscription payment fails.',
                    'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                ],
            ],
            [
                'name'    => 'Payment Success',
                'slug'    => 'payment_success',
                'subject' => 'Payment Successful - {{app_name}}',
                'type'    => 'email',
                'content' => "<p>Hello {{user_name}},</p><p>Your payment of <strong>{{amount}} {{currency}}</strong> was successful. Thank you for your subscription.</p><p><a href=\"{{billing_url}}\">View Billing</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a subscription payment succeeds.',
                    'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                ],
            ],
            [
                'name'    => 'Subscription Started',
                'slug'    => 'subscription_started',
                'subject' => 'Your {{plan_name}} subscription is now active',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription ({{billing_cycle}}ly billing) is now active as of {{starts_at}}.</p><p>Thank you for subscribing to {{app_name}}!</p><p>— The {{app_name}} Team</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a new subscription becomes active.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at'],
                ],
            ],
            [
                'name'    => 'Subscription Confirmation',
                'slug'    => 'subscription_confirmation',
                'subject' => 'Subscription Confirmed - {{plan_name}}',
                'type'    => 'email',
                'content' => "<p>Hello {{user_name}},</p><p>Your subscription to <strong>{{plan_name}}</strong> has been confirmed.</p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent to confirm a subscription (checkout completed).',
                    'placeholders' => ['app_name', 'user_name', 'plan_name'],
                ],
            ],
            [
                'name'    => 'Subscription Cancelled',
                'slug'    => 'subscription_cancelled',
                'subject' => 'Your {{plan_name}} subscription has been cancelled',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription has been cancelled.</p><p>You will continue to have access until <strong>{{ends_at}}</strong>.</p><p>We hope to see you again. If you have any questions, feel free to reach out.</p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a subscription is cancelled.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at'],
                ],
            ],
            [
                'name'    => 'Subscription Renewed',
                'slug'    => 'subscription_renewed',
                'subject' => 'Your {{plan_name}} subscription has been renewed',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription has been successfully renewed.</p><p>Amount charged: <strong>{{amount}} {{currency}}</strong></p><p>Next renewal: <strong>{{next_renewal}}</strong></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a subscription renews (invoice paid).',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal'],
                ],
            ],
            [
                'name'    => 'Subscription Expired',
                'slug'    => 'subscription_expired',
                'subject' => 'Your {{plan_name}} subscription has expired',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your <strong>{{plan_name}}</strong> subscription on {{app_name}} has expired.</p><p>Renew your subscription to restore full access.</p><p><a href=\"{{billing_url}}\">Renew Subscription</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a subscription expires or becomes past due.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_url'],
                ],
            ],
            [
                'name'    => 'Plan Changed',
                'slug'    => 'plan_changed',
                'subject' => 'Your {{app_name}} plan has been updated',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your subscription plan on {{app_name}} has been updated from <strong>{{old_plan}}</strong> to <strong>{{new_plan}}</strong>.</p><p><a href=\"{{billing_url}}\">View Billing</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a user\'s plan is changed.',
                    'placeholders' => ['app_name', 'user_name', 'old_plan', 'new_plan', 'billing_url'],
                ],
            ],
            [
                'name'    => 'Trial Ending',
                'slug'    => 'trial_ending',
                'subject' => 'Your {{plan_name}} trial ends in {{days_remaining}} day(s)',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Your free trial for the <strong>{{plan_name}}</strong> plan on {{app_name}} ends in <strong>{{days_remaining}} day(s)</strong> (on {{trial_ends_at}}).</p><p>Add a payment method now to keep uninterrupted access.</p><p><a href=\"{{billing_url}}\">Add Payment Method</a></p><p>— {{app_name}}</p>",
                'enabled' => true,
                'meta'    => [
                    'description' => 'Sent when a user\'s trial is about to expire.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'days_remaining', 'trial_ends_at', 'billing_url'],
                ],
            ],
            // ── Support / Tickets ───────────────────────────────────────────
            [
                'name'    => 'Support Ticket Created',
                'slug'    => 'support_ticket_created',
                'subject' => 'Your support ticket #{{ticket_id}} has been received',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Thank you for reaching out! We have received your support ticket and our team will get back to you as soon as possible.</p><p><strong>Ticket Details</strong><br>Ticket ID: <strong>#{{ticket_id}}</strong><br>Subject: <strong>{{ticket_subject}}</strong><br>Priority: <strong>{{ticket_priority}}</strong><br>Status: <strong>Open</strong></p><p><a href=\"{{ticket_url}}\">View Your Ticket</a></p><p>— The {{app_name}} Support Team</p>",
                'enabled' => true,
                'meta'    => [
                    'description'  => 'Sent to the user when they submit a new support ticket.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                ],
            ],
            [
                'name'    => 'New Support Ticket (Admin)',
                'slug'    => 'support_ticket_admin_new',
                'subject' => 'New support ticket #{{ticket_id}} from {{user_name}}',
                'type'    => 'email',
                'content' => "<p>A new support ticket has been submitted.</p><p><strong>Ticket Details</strong><br>Ticket ID: <strong>#{{ticket_id}}</strong><br>From: <strong>{{user_name}}</strong> ({{user_email}})<br>Subject: <strong>{{ticket_subject}}</strong><br>Priority: <strong>{{ticket_priority}}</strong></p><p><strong>Message:</strong><br>{{ticket_message}}</p><p><a href=\"{{ticket_url}}\">View &amp; Respond</a></p>",
                'enabled' => true,
                'meta'    => [
                    'description'  => 'Sent to admin users when a new support ticket is submitted.',
                    'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'ticket_priority', 'ticket_message', 'ticket_url'],
                ],
            ],
            [
                'name'    => 'Support Ticket Reply (to Client)',
                'slug'    => 'support_ticket_reply_client',
                'subject' => 'New reply on your ticket #{{ticket_id}}: {{ticket_subject}}',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>Our support team has replied to your ticket <strong>#{{ticket_id}}</strong>.</p><p><strong>Reply from {{staff_name}}:</strong><br>{{reply_message}}</p><p><a href=\"{{ticket_url}}\">View &amp; Reply</a></p><p>— The {{app_name}} Support Team</p>",
                'enabled' => true,
                'meta'    => [
                    'description'  => 'Sent to the client when an admin posts a reply on their ticket.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'staff_name', 'reply_message', 'ticket_url'],
                ],
            ],
            [
                'name'    => 'Support Ticket Reply (to Admin)',
                'slug'    => 'support_ticket_reply_admin',
                'subject' => 'Client replied on ticket #{{ticket_id}}: {{ticket_subject}}',
                'type'    => 'email',
                'content' => "<p>A client has replied to support ticket <strong>#{{ticket_id}}</strong>.</p><p><strong>From:</strong> {{user_name}} ({{user_email}})<br><strong>Subject:</strong> {{ticket_subject}}</p><p><strong>Reply:</strong><br>{{reply_message}}</p><p><a href=\"{{ticket_url}}\">View &amp; Respond</a></p>",
                'enabled' => true,
                'meta'    => [
                    'description'  => 'Sent to admin users when a client posts a reply on a ticket.',
                    'placeholders' => ['ticket_id', 'user_name', 'user_email', 'ticket_subject', 'reply_message', 'ticket_url'],
                ],
            ],
            [
                'name'    => 'Support Ticket Status Changed',
                'slug'    => 'support_ticket_status_changed',
                'subject' => 'Your ticket #{{ticket_id}} status updated to {{new_status}}',
                'type'    => 'email',
                'content' => "<p>Hi {{user_name}},</p><p>The status of your support ticket has been updated.</p><p><strong>Ticket:</strong> #{{ticket_id}} — {{ticket_subject}}<br><strong>New Status:</strong> <strong>{{new_status}}</strong></p><p><a href=\"{{ticket_url}}\">View Your Ticket</a></p><p>— The {{app_name}} Support Team</p>",
                'enabled' => true,
                'meta'    => [
                    'description'  => 'Sent to the client when an admin changes the status of their ticket.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'new_status', 'ticket_url'],
                ],
            ],
        ];

        foreach ($templates as $data) {
            $existing = Template::where('slug', $data['slug'])->where('type', 'email')->first();
            if ($existing) {
                // Only update meta/placeholders; never overwrite admin-customized subject/content
                $existing->update(['meta' => $data['meta']]);
            } else {
                Template::create($data);
            }
        }
    }
}
