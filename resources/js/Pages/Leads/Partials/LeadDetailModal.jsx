import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import { Phone, Globe, MapPin, Star, History, BarChart3 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ScoreBadge from './ScoreBadge';
import FollowUpBadge from './FollowUpBadge';
import ActivityTimeline from './ActivityTimeline';
import LogActivityForm from './LogActivityForm';

/**
 * Lead detail: the follow-up history first, because "what happened with this
 * lead" is the question people open the card to answer. The score breakdown
 * lives behind a tab rather than competing with it.
 */
export default function LeadDetailModal({ detail, stages, activityTypes, callOutcomes, loading, onClose }) {
    const { t } = useTranslation();
    const [tab, setTab] = useState('history');

    const lead = detail?.lead;
    const open = !!lead || loading;

    const TABS = [
        { key: 'history', label: t('leads.tab_history'), Icon: History },
        { key: 'score', label: t('leads.tab_score'), Icon: BarChart3 },
    ];

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            {loading && !lead ? (
                <div className="p-10 text-center text-sm text-neutral-400">{t('common.loading')}</div>
            ) : lead ? (
                <div className="flex max-h-[85vh] flex-col">
                    <div className="border-b border-soft p-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h2 className="truncate text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {lead.name || t('leads.unnamed')}
                                </h2>
                                {lead.category && (
                                    <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{lead.category}</p>
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-1.5">
                                <FollowUpBadge date={lead.next_follow_up_at} />
                                <ScoreBadge band={lead.score_band} score={lead.score} />
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-600 dark:text-neutral-300">
                            {lead.phone && (
                                <a href={`tel:${lead.phone}`} className="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400">
                                    <Phone className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {lead.phone}
                                </a>
                            )}
                            {lead.website && (
                                <a href={lead.website} target="_blank" rel="noreferrer noopener" className="inline-flex min-w-0 items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400">
                                    <Globe className="h-3.5 w-3.5 shrink-0 text-neutral-400" aria-hidden="true" />
                                    <span className="truncate">{lead.website}</span>
                                </a>
                            )}
                            {lead.rating > 0 && (
                                <span className="inline-flex items-center gap-1.5">
                                    <Star className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {t('leads.rating_summary', { rating: lead.rating, count: lead.review_count })}
                                </span>
                            )}
                            {lead.address && (
                                <span className="inline-flex items-center gap-1.5">
                                    <MapPin className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {lead.address}
                                </span>
                            )}
                        </div>

                        <div className="mt-4 flex gap-1" role="tablist">
                            {TABS.map(({ key, label, Icon }) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === key}
                                    onClick={() => setTab(key)}
                                    className={`inline-flex items-center gap-1.5 rounded-soft px-2.5 py-1 text-xs font-medium transition-colors ${
                                        tab === key
                                            ? 'bg-brand-500 text-white'
                                            : 'text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                                    }`}
                                >
                                    <Icon className="h-3.5 w-3.5" />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto p-5">
                        {tab === 'history' ? (
                            <div className="space-y-4">
                                <LogActivityForm
                                    lead={lead}
                                    stages={stages}
                                    types={activityTypes}
                                    outcomes={callOutcomes}
                                />
                                <ActivityTimeline activities={detail.activities} />
                            </div>
                        ) : (
                            <ScoreBreakdown breakdown={lead.score_breakdown ?? []} />
                        )}
                    </div>

                    <div className="flex justify-end border-t border-soft p-4">
                        <Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>
                    </div>
                </div>
            ) : null}
        </Modal>
    );
}

function ScoreBreakdown({ breakdown }) {
    const { t } = useTranslation();

    if (!breakdown.length) {
        return <p className="py-6 text-center text-sm text-neutral-400">{t('leads.not_scored_yet')}</p>;
    }

    return (
        <div>
            <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('leads.why_this_score')}</h3>
            <ul className="mt-3 space-y-3">
                {breakdown.map((row) => (
                    <li key={row.rule}>
                        <div className="flex items-center justify-between gap-2 text-xs">
                            <span className="text-neutral-600 dark:text-neutral-300">{row.detail}</span>
                            <span className="shrink-0 font-medium tabular-nums text-neutral-500 dark:text-neutral-400">
                                {row.points} / {row.max}
                            </span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                            <div
                                className="h-full rounded-full bg-brand-500 transition-all"
                                style={{ width: `${row.max > 0 ? (row.points / row.max) * 100 : 0}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
