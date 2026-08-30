import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import CampaignForm from './CampaignForm';

export default function CampaignWizard({
    whatsappTemplates = [],
    whatsappPhoneNumbers = [],
    segments = [],
    tags = [],
    contactTokens = [],
}) {
    const { t } = useTranslation();
    return (
        <ClientLayout title={t('campaign.new_campaign')}>
            <Head title={t('campaign.new_head_title')} />
            <div className="space-y-5">
                <div className="flex items-center gap-3">
                    <Link
                        href={route('client.campaigns.index')}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('campaign.new_campaign')}
                    </h2>
                </div>

                <CampaignForm
                    mode="create"
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
