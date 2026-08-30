import { Head, useForm, router, usePage, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { Search, MapPin, UserPlus, Trash2, Star, KanbanSquare } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ScoreBadge from './Partials/ScoreBadge';

const JOB_STATUS = {
    pending:  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    running:  'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    done:     'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    failed:   'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300',
};

const TABLE_HEADERS = [
    { labelKey: 'leads.col_business' },
    { labelKey: 'leads.col_phone' },
    { labelKey: 'leads.col_category' },
    { labelKey: 'leads.col_rating' },
    { labelKey: 'leads.col_score' },
    { labelKey: null },
];

export default function LeadsIndex({ leads, scrapeJobs }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [selected, setSelected] = useState([]);
    const [showScraper, setShowScraper] = useState(false);

    const { data, setData, post, processing, reset } = useForm({
        keyword: '',
        location: '',
        radius_meters: 5000,
    });

    const handleScrape = (e) => {
        e.preventDefault();
        post(route('client.leads.scrape'), { onSuccess: () => { reset(); setShowScraper(false); } });
    };

    const toggleSelect = (id) => {
        setSelected(prev => prev.includes(id) ? prev.filter(s => s !== id) : [...prev, id]);
    };

    const pushToContacts = () => {
        router.post(route('client.leads.push-to-contacts'), { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const handleDelete = (id) => {
        if (confirm(t('leads.confirm_delete'))) {
            router.delete(route('client.leads.destroy', id), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout title={t('leads.title')}>
            <Head title={t('leads.title')} />
            <div className="space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('leads.title')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('leads.subtitle')}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('client.leads.pipeline.index')}
                            className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            <KanbanSquare className="h-4 w-4" /> {t('leads.view_pipeline')}
                        </Link>
                        <button onClick={() => setShowScraper(true)} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                            <Search className="h-4 w-4" /> {t('leads.new_search')}
                        </button>
                    </div>
                </div>

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}
                {flash.error   && <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">{flash.error}</div>}

                {/* Scrape Jobs */}
                {scrapeJobs.length > 0 && (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
                        <p className="text-xs font-semibold text-neutral-500 uppercase mb-2">{t('leads.recent_searches')}</p>
                        <div className="space-y-1.5">
                            {scrapeJobs.map(job => (
                                <div key={job.id} className="flex items-center gap-3 text-sm">
                                    <span className="font-medium text-neutral-800 dark:text-neutral-200">{t('leads.keyword_in_location', { keyword: job.keyword, location: job.location })}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${JOB_STATUS[job.status] ?? ''}`}>{t(`leads.job_status_${job.status}`, job.status)}</span>
                                    {job.leads_found > 0 && <span className="text-neutral-400 text-xs">{t('leads.found_count', { count: job.leads_found })}</span>}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Actions */}
                {selected.length > 0 && (
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-neutral-600 dark:text-neutral-400">{t('leads.selected_count', { count: selected.length })}</span>
                        {(
                            <button onClick={pushToContacts} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700 transition">
                                <UserPlus className="h-3.5 w-3.5" /> {t('leads.push_to_contacts')}
                            </button>
                        )}
                    </div>
                )}

                {/* Leads table */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-3 py-3 w-8"><input type="checkbox" onChange={e => setSelected(e.target.checked ? leads.data.map(l => l.id) : [])} className="rounded" /></th>
                                {TABLE_HEADERS.map((h, i) => (
                                    <th key={i} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{h.labelKey ? t(h.labelKey) : ''}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {leads.data.map(lead => (
                                    <tr key={lead.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td className="px-3 py-3"><input type="checkbox" checked={selected.includes(lead.id)} onChange={() => toggleSelect(lead.id)} className="rounded" /></td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-start gap-1.5">
                                                <MapPin className="h-3.5 w-3.5 text-neutral-400 mt-0.5 shrink-0" />
                                                <div>
                                                    <p className="font-medium text-neutral-900 dark:text-neutral-100">{lead.name}</p>
                                                    {lead.address && <p className="text-xs text-neutral-400 truncate max-w-xs">{lead.address}</p>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{lead.phone ?? '—'}</td>
                                        <td className="px-4 py-3 text-neutral-500 dark:text-neutral-400 text-xs">{lead.category ?? '—'}</td>
                                        <td className="px-4 py-3">{lead.rating ? <span className="inline-flex items-center gap-1">{lead.rating}<Star className="h-3.5 w-3.5 text-yellow-400 fill-yellow-400" /></span> : '—'}</td>
                                        <td className="px-4 py-3">{lead.score_band ? <ScoreBadge band={lead.score_band} score={lead.score} /> : '—'}</td>
                                        <td className="px-4 py-3">
                                            <button onClick={() => handleDelete(lead.id)} className="text-neutral-400 hover:text-red-500 transition"><Trash2 className="h-4 w-4" /></button>
                                        </td>
                                    </tr>
                                )
                            )}
                            {leads.data.length === 0 && (
                                <tr>
                                    <td colSpan={7}>
                                        <EmptyState
                                            icon={<MapPin className="h-8 w-8" />}
                                            title={t('leads.no_leads_title')}
                                            description={t('leads.no_leads_description')}
                                            action={{ label: t('leads.new_search'), onClick: () => setShowScraper(true) }}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Scraper Modal */}
            {showScraper && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('leads.search_businesses')}</h3>
                        <form onSubmit={handleScrape} className="space-y-3">
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.business_type_keyword')}</label>
                                <input type="text" value={data.keyword} onChange={e => setData('keyword', e.target.value)} required placeholder={t('leads.keyword_placeholder')} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.city_location')}</label>
                                <input type="text" value={data.location} onChange={e => setData('location', e.target.value)} required placeholder={t('leads.location_placeholder')} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.radius_meters')}</label>
                                <input type="number" min={100} max={50000} value={data.radius_meters} onChange={e => setData('radius_meters', Number(e.target.value))} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div className="flex gap-2 pt-2">
                                <button type="submit" disabled={processing} className="flex-1 flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    <Search className="h-4 w-4" /> {processing ? t('leads.queuing') : t('leads.start_scrape')}
                                </button>
                                <button type="button" onClick={() => setShowScraper(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
