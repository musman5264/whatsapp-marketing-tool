import AdminLayout from '@/Layouts/AdminLayout';
import { Card } from '@/Components/ui';
import { Head } from '@inertiajs/react';
import { Brain, Database, Zap, AlertTriangle, CheckCircle, XCircle, Activity, Bot, BookOpen } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const PROVIDER_LABELS = { openai: 'OpenAI', anthropic: 'Anthropic', gemini: 'Google Gemini' };
const PROVIDER_COLORS = { openai: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', anthropic: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400', gemini: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' };

function StatCard({ icon: Icon, label, value, sub, color = 'brand' }) {
    const colors = { brand: 'text-brand-600', green: 'text-green-600', red: 'text-red-600', yellow: 'text-yellow-600', blue: 'text-blue-600' };
    return (
        <Card className="p-5">
            <div className="flex items-start gap-3">
                <div className={`mt-0.5 ${colors[color] ?? colors.brand}`}><Icon className="h-5 w-5" /></div>
                <div>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
                    <p className="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{value}</p>
                    {sub && <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{sub}</p>}
                </div>
            </div>
        </Card>
    );
}

function StatusBadge({ ok, label, sublabel }) {
    return (
        <div className="flex items-center gap-2">
            {ok ? <CheckCircle className="h-4 w-4 text-green-500 shrink-0" /> : <XCircle className="h-4 w-4 text-red-500 shrink-0" />}
            <div>
                <span className={`text-sm font-medium ${ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'}`}>{label}</span>
                {sublabel && <p className="text-xs text-neutral-500 dark:text-neutral-400">{sublabel}</p>}
            </div>
        </div>
    );
}

export default function AiDashboard({ providerStats = {}, configuredWorkspaces = 0, qdrant, usage, topModels = [], dailyUsage = [], kbCount = 0, documentStats = {}, chatbotCount = 0, activeChatbotCount = 0 }) {
    const { t } = useTranslation();

    const totalTokensFormatted = usage.total_tokens >= 1_000_000
        ? `${(usage.total_tokens / 1_000_000).toFixed(1)}M`
        : usage.total_tokens >= 1_000
        ? `${(usage.total_tokens / 1_000).toFixed(1)}K`
        : usage.total_tokens;

    const errorRate = usage.total_runs > 0 ? ((usage.error_runs / usage.total_runs) * 100).toFixed(1) : '0';
    const docIndexed = documentStats.indexed ?? 0;
    const docError = documentStats.error ?? 0;
    const docTotal = Object.values(documentStats).reduce((a, b) => a + b, 0);

    return (
        <AdminLayout title={t('ai_dashboard.title')}>
            <Head title={`${t('ai_dashboard.title')} · Admin`} />
            <div className="space-y-6">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ai_dashboard.title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-1">{t('ai_dashboard.subtitle')}</p>
                </div>

                {/* Top stats */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <StatCard icon={Zap} label={t('ai_dashboard.tokens_used_30d')} value={totalTokensFormatted} sub={`${usage.total_runs.toLocaleString()} ${t('ai_dashboard.runs')}`} color="brand" />
                    <StatCard icon={Activity} label={t('ai_dashboard.avg_latency')} value={`${usage.avg_latency_ms}ms`} sub={`${errorRate}% ${t('ai_dashboard.error_rate')}`} color={parseFloat(errorRate) > 5 ? 'red' : 'green'} />
                    <StatCard icon={Bot} label={t('ai_dashboard.chatbots')} value={chatbotCount} sub={`${activeChatbotCount} ${t('ai_dashboard.active')}`} color="blue" />
                    <StatCard icon={BookOpen} label={t('ai_dashboard.knowledge_bases')} value={kbCount} sub={`${docIndexed}/${docTotal} ${t('ai_dashboard.docs_indexed')}`} color={docError > 0 ? 'yellow' : 'green'} />
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Provider health */}
                    <Card className="p-5 space-y-4">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2"><Brain className="h-4 w-4" /> {t('ai_dashboard.ai_providers')}</h3>
                        <div className="space-y-3">
                            {['openai', 'anthropic', 'gemini'].map((p) => {
                                const count = providerStats[p] ?? 0;
                                const configured = count > 0;
                                return (
                                    <div key={p} className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            {configured
                                                ? <CheckCircle className="h-4 w-4 text-green-500" />
                                                : <AlertTriangle className="h-4 w-4 text-neutral-300 dark:text-neutral-600" />}
                                            <span className="text-sm text-neutral-800 dark:text-neutral-200">{PROVIDER_LABELS[p]}</span>
                                        </div>
                                        {configured
                                            ? <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${PROVIDER_COLORS[p]}`}>{t('ai_dashboard.workspace_count', { count })}</span>
                                            : <span className="text-xs text-neutral-400 dark:text-neutral-500">{t('ai_dashboard.not_configured')}</span>}
                                    </div>
                                );
                            })}
                        </div>
                        <div className="pt-2 border-t border-neutral-100 dark:border-neutral-800">
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                <span className="font-medium text-neutral-800 dark:text-neutral-200">{configuredWorkspaces}</span> {t('ai_dashboard.workspace_configured')}
                            </p>
                        </div>
                    </Card>

                    {/* Vector store */}
                    <Card className="p-5 space-y-4">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2"><Database className="h-4 w-4" /> {t('ai_dashboard.vector_store')}</h3>
                        <div className="space-y-3">
                            <StatusBadge ok={true} label={t('ai_dashboard.mysql_always_active')} sublabel={t('ai_dashboard.mysql_sublabel')} />
                            <StatusBadge
                                ok={qdrant.configured && qdrant.healthy}
                                label={qdrant.configured ? (qdrant.healthy ? t('ai_dashboard.qdrant_connected') : t('ai_dashboard.qdrant_unreachable')) : t('ai_dashboard.qdrant_not_configured')}
                                sublabel={qdrant.configured ? qdrant.url : t('ai_dashboard.qdrant_set_url')}
                            />
                        </div>
                        {!qdrant.configured && (
                            <div className="pt-2 border-t border-neutral-100 dark:border-neutral-800">
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('ai_dashboard.mysql_fallback')}</p>
                            </div>
                        )}
                    </Card>

                    {/* Document status */}
                    <Card className="p-5 space-y-4">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2"><BookOpen className="h-4 w-4" /> {t('ai_dashboard.document_status')}</h3>
                        {docTotal === 0 ? (
                            <p className="text-sm text-neutral-400 dark:text-neutral-500">{t('ai_dashboard.no_documents')}</p>
                        ) : (
                            <div className="space-y-2">
                                {Object.entries({
                                    indexed: t('ai_dashboard.indexed'),
                                    pending: t('ai_dashboard.pending'),
                                    indexing: t('ai_dashboard.indexing'),
                                    error: t('ai_dashboard.error'),
                                }).map(([status, label]) => {
                                    const count = documentStats[status] ?? 0;
                                    const pct = docTotal > 0 ? Math.round((count / docTotal) * 100) : 0;
                                    const barColor = { indexed: 'bg-green-500', pending: 'bg-yellow-400', indexing: 'bg-blue-400', error: 'bg-red-500' }[status];
                                    return (
                                        <div key={status}>
                                            <div className="flex justify-between text-xs mb-1">
                                                <span className="text-neutral-600 dark:text-neutral-400">{label}</span>
                                                <span className="font-medium text-neutral-800 dark:text-neutral-200">{count}</span>
                                            </div>
                                            <div className="h-1.5 bg-neutral-100 dark:bg-neutral-800 rounded-full">
                                                <div className={`h-1.5 rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Card>
                </div>

                {/* Daily usage chart (simple bar) */}
                {dailyUsage.length > 0 && (
                    <Card className="p-5">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{t('ai_dashboard.token_usage_14d')}</h3>
                        <div className="flex items-end gap-1 h-24">
                            {dailyUsage.map((d) => {
                                const max = Math.max(...dailyUsage.map((x) => x.tokens));
                                const height = max > 0 ? Math.max(4, Math.round((d.tokens / max) * 96)) : 4;
                                return (
                                    <div key={d.date} className="flex-1 flex flex-col items-center gap-1 group relative">
                                        <div className="absolute bottom-6 left-1/2 -translate-x-1/2 hidden group-hover:block bg-neutral-900 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                                            {d.date}: {Number(d.tokens).toLocaleString()} tokens
                                        </div>
                                        <div className="w-full bg-brand-500 rounded-t opacity-80 hover:opacity-100 transition-opacity" style={{ height: `${height}px` }} />
                                        <span className="text-[9px] text-neutral-400 rotate-45 origin-left hidden sm:block">{d.date.slice(5)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </Card>
                )}

                {/* Top models */}
                {topModels.length > 0 && (
                    <Card className="p-5">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{t('ai_dashboard.top_models_30d')}</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                        <th className="pb-2 pr-6 font-medium">{t('ai_dashboard.col_model')}</th>
                                        <th className="pb-2 pr-6 font-medium text-right">{t('ai_dashboard.col_runs')}</th>
                                        <th className="pb-2 font-medium text-right">{t('ai_dashboard.col_tokens')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {topModels.map((m) => (
                                        <tr key={m.model} className="border-b border-neutral-100 dark:border-neutral-800">
                                            <td className="py-2 pr-6 font-mono text-xs text-neutral-800 dark:text-neutral-200">{m.model}</td>
                                            <td className="py-2 pr-6 text-right text-neutral-600 dark:text-neutral-400">{Number(m.runs).toLocaleString()}</td>
                                            <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{Number(m.tokens).toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
