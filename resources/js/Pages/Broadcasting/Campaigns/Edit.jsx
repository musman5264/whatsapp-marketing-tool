import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import CampaignForm from './CampaignForm';

const STATUS_LABEL_KEYS = {
    draft: 'campaign.status_draft',
    queued: 'campaign.status_queued',
    sending: 'campaign.status_sending',
    paused: 'campaign.status_paused',
    completed: 'campaign.status_completed',
    failed: 'campaign.status_failed',
};

export default function CampaignEdit({
    campaign,
    whatsappTemplates = [],
    whatsappPhoneNumbers = [],
    segments = [],
    tags = [],
    contactTokens = [],
}) {
    const { t } = useTranslation();
    return (
        <ClientLayout title={t('campaign.edit_layout_title', { name: campaign.name })}>
            <Head title={t('campaign.edit_head_title', { name: campaign.name })} />
            <div className="space-y-5">
                <div className="flex items-center gap-3">
                    <Link
                        href={route('client.campaigns.show', campaign.uuid)}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('campaign.edit_campaign')}
                    </h2>
                    <span className="rounded-full px-2 py-0.5 text-xs font-medium bg-neutral-100 dark:bg-neutral-700 text-neutral-700 dark:text-neutral-200">
                        {STATUS_LABEL_KEYS[campaign.status] ? t(STATUS_LABEL_KEYS[campaign.status]) : campaign.status}
                    </span>
                </div>

                <CampaignForm
                    mode="edit"
                    campaign={campaign}
                    whatsappTemplates={whatsappTemplates}
                    whatsappPhoneNumbers={whatsappPhoneNumbers}
                    segments={segments}
                    tags={tags}
                    contactTokens={contactTokens}
                />
            </div>
        </ClientLayout>
    );
}
