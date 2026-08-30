import { Head, useForm, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useState } from 'react';
import { Eye, EyeOff, FlaskConical, RotateCcw, ArrowLeft, CheckCircle, XCircle, HardDrive, Info, BookOpen, ChevronDown, ChevronUp, Copy, Check, Link2 } from 'lucide-react';
import axios from 'axios';
import { formatInTz } from '@/Utils/datetime';
import { useTranslation, Trans } from 'react-i18next';

const SETUP_GUIDES = {
    storage_s3: {
        title: 'Amazon S3 Setup',
        steps: [
            'Sign in to the AWS Console at aws.amazon.com.',
            'Go to IAM → Users → Create user with programmatic access.',
            'Attach the AmazonS3FullAccess policy (or a scoped bucket policy).',
            'Copy the Access Key ID and Secret Access Key.',
            'Create an S3 bucket and note the bucket name and region.',
        ],
        link: 'https://s3.console.aws.amazon.com',
        linkLabel: 'Open AWS Console',
    },
    storage_do: {
        title: 'DigitalOcean Spaces Setup',
        steps: [
            'Log in to cloud.digitalocean.com.',
            'Navigate to Spaces → Create a Space, choose a region.',
            'Go to API → Spaces Access Keys → Generate New Key.',
            'Copy the Access Key and Secret.',
            'Enter the Space name and region endpoint.',
        ],
        link: 'https://cloud.digitalocean.com/spaces',
        linkLabel: 'Open DigitalOcean',
    },
    storage_wasabi: {
        title: 'Wasabi Cloud Storage Setup',
        steps: [
            'Sign up or log in at console.wasabisys.com.',
            'Create a new bucket and note its name and region.',
            'Go to Access Keys → Create New Access Key.',
            'Copy the Access Key ID and Secret Access Key.',
        ],
        link: 'https://console.wasabisys.com',
        linkLabel: 'Open Wasabi Console',
    },
    meta_app: {
        title: 'Meta App — Complete Setup Guide',
        subtitle: 'Covers WhatsApp Business API · Instagram DMs · Messenger · Facebook Social Posting · Embedded Signup',
        sections: [
            {
                heading: 'Step 1 — Create & Configure Your Meta App',
                icon: 'create',
                steps: [
                    'Go to developers.facebook.com → "My Apps" → "Create App".',
                    'Select app type: "Business" (required for WhatsApp + Messenger).',
                    'Fill in app name, contact email, and attach your Business Manager account.',
                    'Under Settings → Basic, copy your App ID and App Secret into the fields below.',
                    'Set App Domain to your production domain (e.g. yourdomain.com).',
                    'Under Settings → Advanced, enable "Allow API access to App settings" for system user tokens.',
                ],
            },
            {
                heading: 'Step 2 — WhatsApp Business API',
                icon: 'whatsapp',
                note: 'Required for WhatsApp messaging and Embedded Signup (Connect WhatsApp button).',
                steps: [
                    'In your app dashboard, click "Add Product" → add WhatsApp.',
                    'Go to WhatsApp → Getting Started to see your test WABA and phone number.',
                    'In Meta Business Suite → System Users, create a System User with ADMIN role.',
                    'Click "Add Assets" → assign your WhatsApp Business Account to the system user.',
                    'Generate a Permanent Token for the system user with these permissions:',
                ],
                permissions: [
                    'whatsapp_business_management',
                    'whatsapp_business_messaging',
                    'business_management',
                ],
                steps2: [
                    'Paste the generated token into the "System User Access Token" field below.',
                    'For the Webhook: go to WhatsApp → Configuration, set Callback URL to: {APP_URL}/webhooks/whatsapp/{VERIFY_TOKEN} (shown in Channel Setup after adding a WABA).',
                ],
            },
            {
                heading: 'Step 3 — Embedded Signup (One-Click Connect)',
                icon: 'signup',
                note: 'Lets your clients connect WhatsApp / Instagram / Messenger with a single button click instead of manual token entry.',
                steps: [
                    'Add "Facebook Login for Business" product to your app.',
                    'Go to Facebook Login for Business → Configurations → Create Configuration.',
                    'WhatsApp config: set Permissions to whatsapp_business_management + whatsapp_business_messaging. Copy the config_id → paste into "Embedded Signup Config ID (WhatsApp)" below.',
                    'Social config (Instagram + Messenger): set the permissions listed below. Copy the config_id → paste into "Embedded Signup Config ID (Instagram / Messenger)" below.',
                ],
                permissions: [
                    'instagram_basic',
                    'instagram_manage_messages',
                    'pages_messaging',
                    'pages_manage_metadata',
                    'pages_read_engagement',
                    'pages_show_list',
                ],
                steps2: [
                    'Under your app Settings → Basic → App Domains, add your domain.',
                    'Under Facebook Login → Settings, add Valid OAuth Redirect URIs: {APP_URL}/app/inbox/setup',
                ],
            },
            {
                heading: 'Step 4 — Instagram Business DMs',
                icon: 'instagram',
                note: 'Allows receiving and sending Instagram Direct Messages in the Inbox.',
                steps: [
                    'Add "Messenger" product to your app (Instagram DMs use the Messenger API).',
                    'Go to Messenger → Instagram Settings → enable "Connected Tools".',
                    'Set the Webhook callback URL to: {APP_URL}/webhooks/meta/{VERIFY_TOKEN} (shown in Channel Setup).',
                    'Subscribe to these webhook fields:',
                ],
                permissions: [
                    'instagram_basic',
                    'instagram_manage_messages',
                    'pages_manage_metadata',
                ],
                webhookFields: [
                    'messages',
                    'messaging_postbacks',
                    'messaging_optins',
                    'message_deliveries',
                    'message_reads',
                ],
            },
            {
                heading: 'Step 5 — Facebook Messenger DMs',
                icon: 'messenger',
                note: 'Allows receiving and sending Facebook Page messages in the Inbox.',
                steps: [
                    'Go to Messenger → Settings in your app dashboard.',
                    'Set Webhook callback URL to: {APP_URL}/webhooks/meta/{VERIFY_TOKEN}.',
                    'Subscribe the webhook to these fields:',
                ],
                permissions: [
                    'pages_messaging',
                    'pages_manage_metadata',
                    'pages_read_engagement',
                ],
                webhookFields: [
                    'messages',
                    'messaging_postbacks',
                    'messaging_optins',
                    'message_deliveries',
                    'message_reads',
                ],
                steps2: [
                    'Pages are subscribed automatically when a client connects via Embedded Signup.',
                ],
            },
            {
                heading: 'Step 6 — Facebook & Instagram Social Posting',
                icon: 'social',
                note: 'Required only if you use the Social Posting feature to publish posts to Facebook Pages or Instagram.',
                steps: [
                    'Add "Facebook Login" product to your app.',
                    'Go to Facebook Login → Settings and add Valid OAuth Redirect URI: {APP_URL}/auth/facebook/callback',
                    'Request the following permissions (some require App Review for Advanced Access):',
                ],
                permissions: [
                    'pages_manage_posts',
                    'pages_read_engagement',
                    'pages_show_list',
                    'instagram_basic',
                    'instagram_content_publish',
                    'public_profile',
                    'email',
                ],
                steps2: [
                    'Submit your app for App Review for any permissions marked as "Advanced Access" (pages_manage_posts, instagram_content_publish).',
                    'Add a Privacy Policy URL and Terms of Service URL under Settings → Basic before submitting for review.',
                ],
            },
            {
                heading: 'Step 7 — Go Live',
                icon: 'live',
                steps: [
                    'Switch your app from Development to Live mode (top toggle in Meta App Dashboard).',
                    'In App Review → Permissions, request Advanced Access for any restricted permissions.',
                    'Add Privacy Policy URL and Terms of Service URL under Settings → Basic.',
                    'Enable the integration using the toggle at the top of this admin page.',
                ],
            },
        ],
        links: [
            { href: 'https://developers.facebook.com/apps', label: 'Meta App Dashboard' },
            { href: 'https://business.facebook.com/settings/system-users', label: 'System Users' },
            { href: 'https://developers.facebook.com/docs/whatsapp/embedded-signup', label: 'Embedded Signup Docs' },
            { href: 'https://developers.facebook.com/docs/messenger-platform', label: 'Messenger Platform Docs' },
        ],
    },
    oauth_linkedin: {
        title: 'LinkedIn OAuth Setup',
        steps: [
            'Go to linkedin.com/developers and sign in.',
            'Click "Create App", fill in company and app details.',
            'Under "Auth" tab, add redirect URL: {your-domain}/auth/linkedin/callback',
            'Copy the Client ID and Client Secret.',
            'Request r_liteprofile and r_emailaddress permissions.',
        ],
        link: 'https://www.linkedin.com/developers/apps',
        linkLabel: 'Open LinkedIn Developers',
    },
    oauth_twitter: {
        title: 'Twitter / X OAuth Setup',
        steps: [
            'Go to developer.twitter.com and apply for a developer account.',
            'Create a new Project and App.',
            'Under "User authentication settings", enable OAuth 2.0.',
            'Set the callback URL to: {your-domain}/auth/twitter/callback',
            'Copy the Client ID and Client Secret from "Keys and tokens" tab.',
        ],
        link: 'https://developer.twitter.com/en/portal/apps/new',
        linkLabel: 'Open Twitter Developer Portal',
    },
    oauth_youtube: {
        title: 'YouTube / Google OAuth Setup',
        steps: [
            'Go to console.cloud.google.com and create or select a project.',
            'Navigate to APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID.',
            'Set the authorized redirect URI to: {your-domain}/auth/google/callback',
            'Enable the YouTube Data API v3 in APIs & Services → Library.',
            'Copy the Client ID and Client Secret.',
        ],
        link: 'https://console.cloud.google.com/apis/credentials',
        linkLabel: 'Open Google Cloud Console',
    },
    oauth_shopify: {
        title: 'Shopify App (OAuth) Setup',
        subtitle: 'Lets merchants connect their Shopify store with one click instead of pasting an API token.',
        steps: [
            'Go to the Shopify Partner dashboard → Apps → Create app → Create app manually.',
            'Open the app → Configuration. Under "App URL" put your app URL, and under "Allowed redirection URL(s)" add the Callback URL shown above this guide.',
            'For unlisted/custom distribution you do NOT need to submit for App Store review.',
            'Open "API credentials" / "Client credentials" and copy the API key (Client ID) and API secret key (Client secret) into the fields below.',
            'Request scopes are handled automatically by this app (orders, customers, products, checkouts, fulfillments).',
            'Save, then merchants can connect from E-Commerce → Stores → Connect with Shopify.',
        ],
        link: 'https://partners.shopify.com',
        linkLabel: 'Open Shopify Partners',
    },
    oauth_bigcommerce: {
        title: 'BigCommerce App (OAuth) Setup',
        subtitle: 'Lets merchants install your app from their BigCommerce control panel to auto-connect.',
        steps: [
            'Go to the BigCommerce Developer Portal → My Apps → Create an app.',
            'Under "Technical", set the Auth Callback URL to the Callback URL shown above this guide. Load/Uninstall callbacks can point to the same host.',
            'Enable OAuth scopes: Orders, Customers (read), Products, Information & Settings (read), Carts (read), Webhooks (manage).',
            'Copy the Client ID and Client Secret into the fields below.',
            'Keep the app in Draft for unlisted use — no marketplace review needed; install it from your store\'s control panel under Apps → My Apps.',
        ],
        link: 'https://devtools.bigcommerce.com/my/apps',
        linkLabel: 'Open BigCommerce Dev Portal',
    },
    oauth_tiktok: {
        title: 'TikTok OAuth Setup',
        steps: [
            'Go to developers.tiktok.com and sign in.',
            'Create an app under "Manage apps".',
            'Add the callback URL: {your-domain}/auth/tiktok/callback',
            'Request the user.info.basic scope.',
            'Copy the Client Key and Client Secret.',
        ],
        link: 'https://developers.tiktok.com',
        linkLabel: 'Open TikTok Developers',
    },
    llm_openai_default: {
        title: 'OpenAI API Setup',
        steps: [
            'Sign up or log in at platform.openai.com.',
            'Go to API Keys → Create new secret key.',
            'Copy the key immediately — it won\'t be shown again.',
            'Add billing details under Settings → Billing.',
        ],
        link: 'https://platform.openai.com/api-keys',
        linkLabel: 'Open OpenAI Platform',
    },
    llm_anthropic_default: {
        title: 'Anthropic Claude API Setup',
        steps: [
            'Sign up or log in at console.anthropic.com.',
            'Go to Account Settings → API Keys → Create Key.',
            'Copy the key — shown only once.',
            'Add a credit card under Billing to activate the API.',
        ],
        link: 'https://console.anthropic.com/keys',
        linkLabel: 'Open Anthropic Console',
    },
    llm_gemini_default: {
        title: 'Google Gemini API Setup',
        steps: [
            'Go to aistudio.google.com and sign in with a Google account.',
            'Click "Get API key" → "Create API key in new project".',
            'Copy the generated API key.',
        ],
        link: 'https://aistudio.google.com/app/apikey',
        linkLabel: 'Open Google AI Studio',
    },
    sms_twilio_default: {
        title: 'Twilio SMS Setup',
        steps: [
            'Sign up at twilio.com and verify your account.',
            'From the Console Dashboard, copy your Account SID and Auth Token.',
            'Go to Phone Numbers → Buy a Number with SMS capability.',
            'Enter the Account SID, Auth Token, and the Twilio phone number.',
        ],
        link: 'https://console.twilio.com',
        linkLabel: 'Open Twilio Console',
    },
    sms_nexmo_default: {
        title: 'Vonage (Nexmo) SMS Setup',
        steps: [
            'Sign up at vonage.com.',
            'From the API Dashboard, copy your API Key and API Secret.',
            'Purchase a virtual number under Numbers → Buy Numbers.',
        ],
        link: 'https://dashboard.nexmo.com',
        linkLabel: 'Open Vonage Dashboard',
    },
    sms_messagebird_default: {
        title: 'MessageBird SMS Setup',
        steps: [
            'Sign up at messagebird.com.',
            'Go to Developers → API access keys → Add access key.',
            'Copy the live API key.',
            'Optionally buy a virtual number under Numbers.',
        ],
        link: 'https://dashboard.messagebird.com/en/developers/access',
        linkLabel: 'Open MessageBird Dashboard',
    },
    google_places: {
        title: 'Google Places API Setup',
        steps: [
            'Go to console.cloud.google.com and create or select a project.',
            'Navigate to APIs & Services → Library → search "Places API" → Enable.',
            'Go to APIs & Services → Credentials → Create Credentials → API Key.',
            'Optionally restrict the key to Places API only for security.',
        ],
        link: 'https://console.cloud.google.com/apis/library/places-backend.googleapis.com',
        linkLabel: 'Open Google Cloud Console',
    },
    qdrant: {
        title: 'Qdrant Vector Store Setup',
        steps: [
            'Option A — Qdrant Cloud: Sign up at cloud.qdrant.io, create a cluster, copy the cluster URL and API key.',
            'Option B — Self-hosted: Run with Docker: docker run -p 6333:6333 qdrant/qdrant',
            'For self-hosted, the URL is typically http://localhost:6333 (no API key needed).',
            'Paste the URL and API key (if applicable) into the credentials form.',
        ],
        link: 'https://cloud.qdrant.io',
        linkLabel: 'Open Qdrant Cloud',
    },
};


function PermBadge({ p }) {
    return (
        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-mono font-medium bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
            {p}
        </span>
    );
}

function CallbackUrlCard({ url }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    if (!url) return null;
    const copy = () => {
        navigator.clipboard?.writeText(url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };
    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5">
            <div className="flex items-center gap-2 mb-1">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 shrink-0">
                    <Link2 className="h-4 w-4" />
                </div>
                <div>
                    <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('integrations.callback_url_title')}</p>
                    <p className="text-xs text-neutral-400">{t('integrations.callback_url_desc')}</p>
                </div>
            </div>
            <div className="mt-3 flex items-center gap-2 rounded-lg bg-neutral-50 dark:bg-neutral-800 px-3 py-2">
                <code className="flex-1 truncate text-xs text-neutral-600 dark:text-neutral-300">{url}</code>
                <button type="button" onClick={copy}
                    className="flex items-center gap-1 rounded-md border border-neutral-200 dark:border-neutral-700 px-2 py-1 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:bg-white dark:hover:bg-neutral-700 shrink-0">
                    {copied ? <><Check className="h-3.5 w-3.5 text-green-500" /> {t('integrations.copied')}</> : <><Copy className="h-3.5 w-3.5" /> {t('integrations.copy')}</>}
                </button>
            </div>
        </div>
    );
}

function SetupGuide({ provider }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const guide = SETUP_GUIDES[provider];
    if (!guide) return null;
    const hasSections = Array.isArray(guide.sections);
    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5">
            <button type="button" onClick={() => setOpen(v => !v)}
                className="flex items-center gap-2 w-full text-left">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 shrink-0">
                    <BookOpen className="h-4 w-4" />
                </div>
                <div className="flex-1 min-w-0">
                    <span className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('integrations.setup_guide')}</span>
                    <p className="text-xs text-neutral-400 mt-0.5 truncate">{guide.title}</p>
                </div>
                {open ? <ChevronUp className="h-4 w-4 text-neutral-400 shrink-0" /> : <ChevronDown className="h-4 w-4 text-neutral-400 shrink-0" />}
            </button>
            {open && (
                <div className="mt-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-4 py-3 space-y-4">
                    {guide.subtitle && (
                        <p className="text-xs text-blue-600 dark:text-blue-400 font-medium">{guide.subtitle}</p>
                    )}
                    {hasSections ? (
                        <div className="space-y-4">
                            {guide.sections.map((sec, si) => (
                                <div key={si} className="space-y-2">
                                    <p className="text-xs font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300 border-b border-blue-200 dark:border-blue-800 pb-1">{sec.heading}</p>
                                    {sec.note && (
                                        <div className="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-2.5 py-1.5">
                                            <span className="text-amber-600 dark:text-amber-400 text-[10px] font-bold uppercase tracking-wide shrink-0 mt-0.5">{t('integrations.note')}</span>
                                            <p className="text-[11px] text-amber-700 dark:text-amber-300">{sec.note}</p>
                                        </div>
                                    )}
                                    {sec.steps && (
                                        <ol className="space-y-1.5">
                                            {sec.steps.map((s, i) => (
                                                <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                                    <span className="shrink-0 w-4 text-right font-semibold text-blue-500">{i + 1}.</span>
                                                    <span>{s}</span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                    {sec.permissions && (
                                        <div>
                                            <p className="text-[10px] font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wide mb-1">{t('integrations.required_permissions')}</p>
                                            <div className="flex flex-wrap gap-1">
                                                {sec.permissions.map(p => <PermBadge key={p} p={p} />)}
                                            </div>
                                        </div>
                                    )}
                                    {sec.webhookFields && (
                                        <div>
                                            <p className="text-[10px] font-semibold text-purple-600 dark:text-purple-400 uppercase tracking-wide mb-1">{t('integrations.webhook_fields')}</p>
                                            <div className="flex flex-wrap gap-1">
                                                {sec.webhookFields.map(f => (
                                                    <span key={f} className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-mono font-medium bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800">{f}</span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    {sec.steps2 && (
                                        <ol className="space-y-1.5">
                                            {sec.steps2.map((s, i) => (
                                                <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                                    <span className="shrink-0 w-4 text-right font-semibold text-blue-500">{(sec.steps ? sec.steps.length : 0) + i + 1}.</span>
                                                    <span>{s}</span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <ol className="space-y-1.5">
                            {guide.steps.map((step, i) => (
                                <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                    <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                    <span>{step}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                    {guide.links ? (
                        <div className="flex flex-wrap gap-4 pt-2 border-t border-blue-200 dark:border-blue-800">
                            {guide.links.map(l => (
                                <a key={l.href} href={l.href} target="_blank" rel="noopener noreferrer"
                                    className="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                    {l.label} →
                                </a>
                            ))}
                        </div>
                    ) : guide.link ? (
                        <a href={guide.link} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline pt-1">
                            {guide.linkLabel} →
                        </a>
                    ) : null}
                </div>
            )}
        </div>
    );
}
const BRAND = {
    storage_local:           { bg: 'bg-sky-100 dark:bg-sky-900/40', color: '#0ea5e9', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M2 7a2 2 0 012-2h12a2 2 0 012 2v1H2V7zm0 3h16v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4zm3 2a1 1 0 100 2 1 1 0 000-2zm3 0a1 1 0 100 2 1 1 0 000-2z" /></svg> },
    storage_s3:              { bg: 'bg-orange-100 dark:bg-orange-900/30', color: '#FF9900', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2L4 5.8v8.4L10 18l6-3.8V5.8L10 2zm0 2.3l3.9 2.46-3.9 2.36L6.1 6.76 10 4.3zM5.5 8.1l3.9 2.36v4.7L5.5 12.8V8.1zm5.1 2.36l3.9-2.36V12.8l-3.9 2.36v-4.7z" /></svg> },
    storage_do:              { bg: 'bg-blue-100 dark:bg-blue-900/30', color: '#0080FF', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2a8 8 0 110 16A8 8 0 0110 2zm0 2.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zm0 2a3.5 3.5 0 110 7 3.5 3.5 0 010-7zm0 2a1.5 1.5 0 100 3 1.5 1.5 0 000-3zM6 15.5H4.5V14H6v1.5zm-2-2.5H2.5V11.5H4V13z" /></svg> },
    storage_wasabi:          { bg: 'bg-green-100 dark:bg-green-900/30', color: '#3CBA54', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M2.5 14.5L5 7l2.5 5 2.5-5 2.5 5 2.5-5 2.5 7.5h-2l-1-3-2 4-2-4-1 3h-2zm14-9A1.5 1.5 0 1114 7a1.5 1.5 0 012.5-1.5z" /></svg> },
    meta_app:                { bg: 'bg-blue-100 dark:bg-blue-900/30', color: '#0866FF', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M2.5 10C2.5 7.24 4.74 5 7.5 5c1.32 0 2.52.5 3.42 1.32A5 5 0 0114.5 5C17.26 5 19.5 7.24 19.5 10v.5c0 2.76-2.24 5-5 5a5 5 0 01-3.58-1.5A5 5 0 017.5 15.5C4.74 15.5 2.5 13.26 2.5 10.5V10zm5 3.5c1.38 0 2.5-1.12 2.5-2.5v-.5c0-1.38-1.12-2.5-2.5-2.5S5 9.12 5 10.5V11c0 1.38 1.12 2.5 2.5 2.5zm7 0c1.38 0 2.5-1.12 2.5-2.5V10c0-1.38-1.12-2.5-2.5-2.5S12 8.62 12 10v.5c0 1.38 1.12 2.5 2.5 2.5z" /></svg> },
    oauth_linkedin:          { bg: 'bg-sky-100 dark:bg-sky-900/30', color: '#0A66C2', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M4.5 3C3.67 3 3 3.67 3 4.5S3.67 6 4.5 6 6 5.33 6 4.5 5.33 3 4.5 3zM3 7.5h3V17H3V7.5zm4.5 0H10v1.3c.45-.78 1.45-1.5 2.75-1.5 2.95 0 3.5 1.94 3.5 4.47V17H13v-4.73c0-1.13-.02-2.58-1.57-2.58-1.57 0-1.81 1.23-1.81 2.5V17H7.5V7.5z" /></svg> },
    oauth_twitter:           { bg: 'bg-neutral-200 dark:bg-neutral-700/60', color: '#0f0f0f', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M3.5 3h3.1l2.9 4.1L12.6 3h2.3l-4 5 4.6 6h-3.1L9.2 9.7 5.9 14H3.6l4.3-5.3L3.5 3zm2.3 1.2l8 10.4h1.2L7 4.2H5.8z" /></svg> },
    oauth_youtube:           { bg: 'bg-red-100 dark:bg-red-900/30', color: '#FF0000', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M17.5 6.2S17.25 4.8 16.6 4.1c-.67-.72-1.43-.73-1.78-.77C12.6 3.2 10 3.2 10 3.2s-2.6 0-4.82.13c-.35.04-1.1.05-1.78.77C2.75 4.8 2.5 6.2 2.5 6.2S2.25 7.86 2.25 9.5v1.5c0 1.64.25 3.3.25 3.3s.25 1.4.9 2.1c.68.72 1.57.7 1.97.77 1.43.14 6.08.19 6.08.19s2.6 0 4.82-.15c.35-.04 1.1-.05 1.78-.77.65-.7.9-2.1.9-2.1S17.75 12.64 17.75 11V9.5c0-1.64-.25-3.3-.25-3.3zM8.5 12.75v-5.5l5 2.75-5 2.75z" /></svg> },
    oauth_tiktok:            { bg: 'bg-neutral-200 dark:bg-neutral-700/60', color: '#000000', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M13.5 2h-2.25v9.5A2.25 2.25 0 119 9.2V7a4.5 4.5 0 104.5 4.5V6.25A6.24 6.24 0 0017 7V4.75A4.25 4.25 0 0113.5 2z" /></svg> },
    llm_openai_default:      { bg: 'bg-emerald-100 dark:bg-emerald-900/30', color: '#10a37f', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2a3.9 3.9 0 00-3.68 2.6A3.9 3.9 0 003.6 8.32a3.9 3.9 0 000 3.36 3.9 3.9 0 002.72 3.72A3.9 3.9 0 0010 18a3.9 3.9 0 003.68-2.6 3.9 3.9 0 002.72-3.72 3.9 3.9 0 000-3.36A3.9 3.9 0 0013.68 4.6 3.9 3.9 0 0010 2zm0 4a2 2 0 110 4 2 2 0 010-4z" /></svg> },
    llm_anthropic_default:   { bg: 'bg-orange-100 dark:bg-orange-900/30', color: '#d4793b', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2.5L3.5 17h3.2l1.1-2.8h4.4l1.1 2.8h3.2L10 2.5zm0 4.3l1.6 4.2H8.4L10 6.8z" /></svg> },
    llm_gemini_default:      { bg: 'bg-blue-100 dark:bg-blue-900/30', color: '#4285F4', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2l2 6h6l-5 3.6 1.9 5.9L10 14l-4.9 3.5L7 11.6 2 8h6l2-6z" /></svg> },
    sms_twilio_default:      { bg: 'bg-red-100 dark:bg-red-900/30', color: '#F22F46', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm-2.5 4.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm5 0a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm-5 5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm5 0a1.5 1.5 0 110 3 1.5 1.5 0 010-3z" /></svg> },
    sms_nexmo_default:       { bg: 'bg-purple-100 dark:bg-purple-900/30', color: '#6E3697', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M3.5 3h3.3L10 9.5 13.2 3h3.3L10 17 3.5 3z" /></svg> },
    sms_messagebird_default: { bg: 'bg-blue-100 dark:bg-blue-900/30', color: '#2481CC', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M18 9c0-3.31-3.58-6-8-6S2 5.69 2 9c0 2.12 1.32 4 3.38 5.14-.1.48-.46 1.74-.88 2.36 0 0 2.04-.42 3.52-1.56.64.1 1.3.16 1.98.16 4.42 0 8-2.69 8-6zM6 8.5a1 1 0 110 2 1 1 0 010-2zm4 0a1 1 0 110 2 1 1 0 010-2zm4 0a1 1 0 110 2 1 1 0 010-2z" /></svg> },
    sms_smsbd_default:       { bg: 'bg-teal-100 dark:bg-teal-900/30', color: '#0d9488', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M3 5a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V5zm2 1.5v5h2.5c1.1 0 2-.9 2-2v-1c0-1.1-.9-2-2-2H5zm5 0v5h2.5c.83 0 1.5-.67 1.5-1.5v-2c0-.83-.67-1.5-1.5-1.5H10zm-3.5 1.5h1c.28 0 .5.22.5.5v1c0 .28-.22.5-.5.5h-1v-2zm5 0h1c.28 0 .5.22.5.5v2c0 .28-.22.5-.5.5h-1V8z" /></svg> },
    sms_reve_default:        { bg: 'bg-indigo-100 dark:bg-indigo-900/30', color: '#4f46e5', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M3 5a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V5zm2 1.5v5h1.5v-2h1l1 2H10l-1.1-2.1c.67-.26 1.1-.9 1.1-1.65 0-1-.8-1.75-1.75-1.75H5zm5 0L12.5 11 15 6.5h-1.7L12 9.3l-1.3-2.8H10zm-3.5 1.4h.75c.28 0 .5.22.5.5s-.22.5-.5.5H6.5V7.9z" /></svg> },
    google_places:           { bg: 'bg-red-100 dark:bg-red-900/30', color: '#EA4335', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2a6 6 0 016 6c0 4.5-6 11-6 11S4 12.5 4 8a6 6 0 016-6zm0 3.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5z" /></svg> },
    qdrant:                  { bg: 'bg-rose-100 dark:bg-rose-900/30', color: '#DC143C', icon: <svg viewBox="0 0 20 20" fill="currentColor" className="h-5 w-5"><path d="M10 2l5 3v3.5L10 11.5 5 8.5V5l5-3zm0 11L5 10v3l5 5 5-5v-3l-5 3zm-4.5-4.7L10 11l4.5-2.7V5.7L10 3 5.5 5.7v2.6z" /></svg> },
};

function SecretField({ label, fieldKey, value, onChange, required, hint }) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);
    const isMasked = value && /^•+/.test(value);

    return (
        <div>
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <div className="relative">
                <input
                    type={visible ? 'text' : 'password'}
                    value={value}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    placeholder={isMasked ? t('integrations.unchanged_placeholder') : t('integrations.enter_value')}
                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
                <button
                    type="button"
                    onClick={() => setVisible(v => !v)}
                    className="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                >
                    {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
            </div>
            {hint && <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{hint}</p>}
        </div>
    );
}

function PlainField({ label, fieldKey, value, onChange, required, hint }) {
    return (
        <div>
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <input
                type="text"
                value={value}
                onChange={e => onChange(fieldKey, e.target.value)}
                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
            {hint && <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{hint}</p>}
        </div>
    );
}

const STORAGE_PROVIDERS = ['storage_local', 'storage_s3', 'storage_do', 'storage_wasabi'];

function StorageBanner({ provider }) {
    const { t } = useTranslation();
    if (!STORAGE_PROVIDERS.includes(provider)) return null;

    const isLocal = provider === 'storage_local';

    return (
        <div className="flex gap-3 rounded-xl border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/20 px-4 py-3 text-sm text-sky-800 dark:text-sky-200">
            <HardDrive className="h-5 w-5 shrink-0 mt-0.5 text-sky-500" />
            <div className="space-y-1">
                <p className="font-medium">{t('integrations.storage_provider')}</p>
                {isLocal ? (
                    <p className="text-xs opacity-80">{t('integrations.storage_local_banner')}</p>
                ) : (
                    <p className="text-xs opacity-80">
                        <Trans
                            i18nKey="integrations.storage_remote_banner"
                            components={{ strong: <strong />, code: <code className="bg-sky-100 dark:bg-sky-800 px-1 rounded" /> }}
                        />
                    </p>
                )}
            </div>
        </div>
    );
}

export default function IntegrationsEdit({ provider, label, category, fields, config, callbackUrl = null }) {
    const { t } = useTranslation();
    const adminTz = usePage().props.timezone || 'Asia/Dhaka';
    const { data, setData, put, processing, errors } = useForm({
        enabled: config.enabled,
        mode: config.mode,
        credentials: { ...config.credentials },
    });

    const [testResult, setTestResult] = useState(null);
    const [testing, setTesting] = useState(false);
    const [rotating, setRotating] = useState(false);

    const setCredential = (key, val) => {
        setData('credentials', { ...data.credentials, [key]: val });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.integrations.update', provider), { preserveScroll: true });
    };

    const handleTest = async () => {
        setTesting(true);
        setTestResult(null);
        try {
            const { data: res } = await axios.post(route('admin.integrations.test', provider));
            setTestResult(res);
        } catch (e) {
            setTestResult({ ok: false, message: e?.response?.data?.message || t('integrations.request_failed') });
        } finally {
            setTesting(false);
        }
    };

    const handleRotate = async () => {
        if (! confirm(t('integrations.rotate_confirm'))) return;
        setRotating(true);
        try {
            await axios.post(route('admin.integrations.rotate', provider));
            router.reload({ only: ['config'] });
        } finally {
            setRotating(false);
        }
    };

    return (
        <AdminLayout title={`${t('integrations.title')} · ${label}`}>
            <Head title={`${label} · ${t('integrations.title')} · ${t('head.admin')}`} />
            <div className="max-w-2xl space-y-6">
                <div className="flex items-center gap-3">
                    <a
                        href={route('admin.integrations.index')}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    {BRAND[provider] && (
                        <div
                            className={`flex items-center justify-center w-10 h-10 rounded-xl shrink-0 ${BRAND[provider].bg}`}
                            style={{ color: BRAND[provider].color }}
                        >
                            {BRAND[provider].icon}
                        </div>
                    )}
                    <div>
                        <p className="text-xs text-neutral-400 uppercase tracking-wider">{category}</p>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{label}</h2>
                    </div>
                </div>

                <StorageBanner provider={provider} />

                <CallbackUrlCard url={callbackUrl} />

                <SetupGuide provider={provider} />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Status toggles */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
                        <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('integrations.status')}</h3>
                        <div className="flex flex-wrap gap-6">
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.enabled}
                                    onChange={e => setData('enabled', e.target.checked)}
                                    className="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="text-sm text-neutral-700 dark:text-neutral-300">{t('common.enabled')}</span>
                            </label>
                            <div className="flex items-center gap-2">
                                <span className="text-sm text-neutral-500 dark:text-neutral-400">{t('integrations.mode_label')}</span>
                                <select
                                    value={data.mode}
                                    onChange={e => setData('mode', e.target.value)}
                                    className="rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2 py-1 text-sm text-neutral-800 dark:text-neutral-200"
                                >
                                    <option value="live">{t('integrations.mode_live')}</option>
                                    <option value="test">{t('integrations.mode_test')}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Credential fields */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
                        <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('integrations.credentials')}</h3>
                        {provider === 'storage_local' ? (
                            <div className="flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                                <Info className="h-4 w-4 shrink-0" />
                                {t('integrations.no_credentials_local')}
                            </div>
                        ) : (
                            <>
                                <p className="text-xs text-neutral-400">
                                    {t('integrations.encrypted_note')}
                                </p>
                                {fields.map(f => (
                                    f.type === 'password'
                                        ? <SecretField
                                            key={f.key}
                                            label={f.label}
                                            fieldKey={f.key}
                                            value={data.credentials[f.key] ?? ''}
                                            onChange={setCredential}
                                            required={f.required}
                                            hint={f.hint}
                                        />
                                        : <PlainField
                                            key={f.key}
                                            label={f.label}
                                            fieldKey={f.key}
                                            value={data.credentials[f.key] ?? ''}
                                            onChange={setCredential}
                                            required={f.required}
                                            hint={f.hint}
                                        />
                                ))}
                                {provider === 'meta_app' &&
                                    data.credentials.config_id_whatsapp &&
                                    data.credentials.config_id_social &&
                                    data.credentials.config_id_whatsapp === data.credentials.config_id_social && (
                                    <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                        <span>
                                            <Trans
                                                i18nKey="integrations.config_id_conflict"
                                                components={{ strong: <strong />, em: <em /> }}
                                            />
                                        </span>
                                    </div>
                                )}
                            </>
                        )}
                        {errors && Object.values(errors).map((e, i) => (
                            <p key={i} className="text-xs text-red-500">{e}</p>
                        ))}
                    </div>

                    {/* Test result */}
                    {testResult && (
                        <div className={`flex items-start gap-2 rounded-lg px-4 py-3 text-sm ${testResult.ok ? 'bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200' : 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200'}`}>
                            {testResult.ok ? <CheckCircle className="h-4 w-4 mt-0.5 shrink-0" /> : <XCircle className="h-4 w-4 mt-0.5 shrink-0" />}
                            {testResult.message}
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                        >
                            {processing ? t('integrations.saving') : t('common.save')}
                        </button>
                        <button
                            type="button"
                            disabled={testing}
                            onClick={handleTest}
                            className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-60 transition"
                        >
                            <FlaskConical className="h-4 w-4" />
                            {testing ? t('integrations.testing') : t('integrations.test_connection')}
                        </button>
                        {!STORAGE_PROVIDERS.includes(provider) && (
                            <button
                                type="button"
                                disabled={rotating}
                                onClick={handleRotate}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-60 transition"
                            >
                                <RotateCcw className="h-4 w-4" />
                                {rotating ? t('integrations.rotating') : t('integrations.rotate_webhook_secret')}
                            </button>
                        )}
                    </div>
                </form>

                {/* Last test status */}
                {config.last_test_status !== 'untested' && (
                    <div className="text-xs text-neutral-400">
                        {t('integrations.last_tested')}: {config.last_tested_at ? formatInTz(config.last_tested_at, adminTz) : t('integrations.never')}
                        {' · '}
                        <span className={config.last_test_status === 'ok' ? 'text-green-500' : 'text-red-500'}>
                            {config.last_test_status}
                        </span>
                        {config.last_test_message && ` · ${config.last_test_message}`}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
