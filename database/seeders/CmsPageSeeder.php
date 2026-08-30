<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Support\Brand;
use Illuminate\Database\Seeder;

class CmsPageSeeder extends Seeder
{
    /**
     * Seed the standard legal pages as editable CMS templates.
     *
     * Non-destructive: uses firstOrCreate keyed on slug, so existing pages
     * (and any admin edits) are preserved. The bodies are placeholder
     * templates — clearly marked to be reviewed with legal counsel before
     * publishing. Edit them in Admin → CMS Pages.
     */
    public function run(): void
    {
        // Store the brand token rather than the resolved name: this copy lives in the
        // database, and CmsPageController::show() resolves `:brand` via Brand::apply()
        // on render — so the pages follow the brand through any later rename.
        $app = Brand::TOKEN;

        $notice = '<p style="padding:12px 16px;border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;color:#92400e;">'
            .'<strong>⚠️ Template notice:</strong> This is placeholder text generated for convenience. '
            .'Review and adapt it with qualified legal counsel before relying on it.</p>';

        $updated = 'Last updated: '.now()->format('F j, Y');

        $pages = [
            [
                'slug'             => 'privacy',
                'title'            => 'Privacy Policy',
                'meta_title'       => "Privacy Policy — {$app}",
                'meta_description' => "How {$app} collects, uses, and protects your personal data.",
                'content'          => $notice."<p>{$updated}</p>"
                    ."<p>This Privacy Policy explains how {$app} (\"we\", \"us\") collects, uses, discloses, and safeguards your information when you use our platform and services.</p>"
                    .'<h2>1. Information We Collect</h2><p>We collect information you provide directly (such as your name, email, and billing details), information collected automatically (such as usage data, device information, and cookies), and information from third parties (such as messaging platforms you connect).</p>'
                    .'<h2>2. How We Use Your Information</h2><p>We use your information to provide and improve our services, process payments, communicate with you, ensure security, and comply with legal obligations.</p>'
                    .'<h2>3. Sharing of Information</h2><p>We do not sell your personal data. We share information only with service providers, when required by law, or with your consent.</p>'
                    .'<h2>4. Data Retention</h2><p>We retain personal data only as long as necessary to provide our services and fulfill the purposes described in this policy.</p>'
                    .'<h2>5. Your Rights</h2><p>Depending on your location, you may have rights to access, correct, delete, or port your data, and to object to or restrict certain processing.</p>'
                    .'<h2>6. Security</h2><p>We use industry-standard technical and organizational measures, including encryption in transit and at rest, to protect your data.</p>'
                    ."<h2>7. Contact</h2><p>For privacy questions, contact us at <a href=\"/contact\">our contact page</a>.</p>",
            ],
            [
                'slug'             => 'terms',
                'title'            => 'Terms of Service',
                'meta_title'       => "Terms of Service — {$app}",
                'meta_description' => "The terms and conditions for using {$app}.",
                'content'          => $notice."<p>{$updated}</p>"
                    ."<p>These Terms of Service govern your access to and use of {$app}. By using our services, you agree to these terms.</p>"
                    .'<h2>1. Accounts</h2><p>You are responsible for maintaining the confidentiality of your account credentials and for all activity under your account.</p>'
                    .'<h2>2. Acceptable Use</h2><p>You agree not to misuse the services, send unsolicited messages (spam), violate messaging-platform policies, or infringe the rights of others.</p>'
                    .'<h2>3. Subscriptions &amp; Billing</h2><p>Paid plans are billed in advance on a recurring basis. You may cancel at any time; fees already paid are non-refundable except where required by law.</p>'
                    .'<h2>4. Third-Party Platforms</h2><p>Use of WhatsApp, Messenger, Instagram, and other connected services is subject to those providers\' own terms and policies.</p>'
                    .'<h2>5. Intellectual Property</h2><p>The platform, including its software and content, is owned by us and protected by intellectual-property laws.</p>'
                    .'<h2>6. Termination</h2><p>We may suspend or terminate access for violations of these terms. You may stop using the services at any time.</p>'
                    .'<h2>7. Disclaimers &amp; Limitation of Liability</h2><p>The services are provided "as is" without warranties. To the maximum extent permitted by law, our liability is limited.</p>'
                    ."<h2>8. Contact</h2><p>Questions about these terms? Reach us via <a href=\"/contact\">our contact page</a>.</p>",
            ],
            [
                'slug'             => 'cookies',
                'title'            => 'Cookie Policy',
                'meta_title'       => "Cookie Policy — {$app}",
                'meta_description' => "How {$app} uses cookies and similar technologies.",
                'content'          => $notice."<p>{$updated}</p>"
                    ."<p>This Cookie Policy explains how {$app} uses cookies and similar technologies to recognize you when you visit our website.</p>"
                    .'<h2>1. What Are Cookies?</h2><p>Cookies are small data files placed on your device that help websites function and provide reporting information.</p>'
                    .'<h2>2. Types of Cookies We Use</h2><ul><li><strong>Essential cookies</strong> — required for the site to function (e.g. authentication, security).</li><li><strong>Preference cookies</strong> — remember settings such as language and theme.</li><li><strong>Analytics cookies</strong> — help us understand how visitors use the site.</li></ul>'
                    .'<h2>3. Managing Cookies</h2><p>You can control cookies through your browser settings. Disabling some cookies may affect site functionality.</p>'
                    ."<h2>4. Contact</h2><p>For questions about our use of cookies, visit <a href=\"/contact\">our contact page</a>.</p>",
            ],
            [
                'slug'             => 'gdpr',
                'title'            => 'GDPR Compliance',
                'meta_title'       => "GDPR Compliance — {$app}",
                'meta_description' => "How {$app} supports your rights under the GDPR.",
                'content'          => $notice."<p>{$updated}</p>"
                    ."<p>{$app} is committed to protecting the rights of individuals in the European Economic Area under the General Data Protection Regulation (GDPR).</p>"
                    .'<h2>1. Lawful Basis for Processing</h2><p>We process personal data on the basis of contract performance, legitimate interests, consent, and legal obligations.</p>'
                    .'<h2>2. Your Rights</h2><ul><li>Right to access your data</li><li>Right to rectification</li><li>Right to erasure ("right to be forgotten")</li><li>Right to restrict or object to processing</li><li>Right to data portability</li></ul>'
                    .'<h2>3. Data Processing Agreement</h2><p>For customers acting as data controllers, we offer a Data Processing Agreement (DPA) describing our role as a processor.</p>'
                    .'<h2>4. International Transfers</h2><p>Where data is transferred outside the EEA, we rely on appropriate safeguards such as Standard Contractual Clauses.</p>'
                    ."<h2>5. Exercising Your Rights</h2><p>To exercise any of your rights, contact us via <a href=\"/contact\">our contact page</a>.</p>",
            ],
        ];

        foreach ($pages as $page) {
            CmsPage::firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title'            => $page['title'],
                    'content'          => $page['content'],
                    'meta_title'       => $page['meta_title'],
                    'meta_description' => $page['meta_description'],
                    'published'        => true,
                    'layout'           => 'legal',
                ]
            );
        }
    }
}
