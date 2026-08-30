import { useState } from 'react';
import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { BrandMark } from '@/Components/BrandIcons';
import { useTranslation } from 'react-i18next';
import { useBranding } from '@/hooks/useBranding';

// ─── Icon map ─────────────────────────────────────────────────────────────────

function FeatureIcon({ name, className = 'h-6 w-6' }) {
    const icons = {
        'cpu': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z" />
            </svg>
        ),
        'message-square': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
            </svg>
        ),
        'bar-chart-2': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
            </svg>
        ),
        'users': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
        ),
        'share-2': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
            </svg>
        ),
        'shield-check': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
        ),
        'zap': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
            </svg>
        ),
        'star': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
            </svg>
        ),
        'layout': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
        ),
        'arrow-right': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
            </svg>
        ),
        'globe': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
            </svg>
        ),
        'trending-up': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
        ),
        'check-circle': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
        'server': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 17.25v.75a2.25 2.25 0 01-2.25 2.25H4.5a2.25 2.25 0 01-2.25-2.25v-.75m16.5 0A2.25 2.25 0 0021.75 15V9.75A2.25 2.25 0 0019.5 7.5H4.5A2.25 2.25 0 002.25 9.75V15a2.25 2.25 0 002.25 2.25h15zM12 12.75h.008v.008H12v-.008z" />
            </svg>
        ),
    };
    return icons[name] || icons['zap'];
}

// ─── Channel Icon map ──────────────────────────────────────────────────────────

function ChannelIcon({ name, className = 'h-6 w-6' }) {
    const icons = {
        'whatsapp': (
            <svg className={className} fill="currentColor" viewBox="0 0 24 24">
                <path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z" />
            </svg>
        ),
        'messenger': (
            <svg className={className} fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.654V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.975 12-11.111C24 4.974 18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26L10.732 8.1l3.131 3.259L19.752 8.1l-6.561 6.863z" />
            </svg>
        ),
        'instagram': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z" />
                <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" strokeLinecap="round" />
            </svg>
        ),
        'sms': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M8 10.5h8M8 14h5m-9 6.5l1.5-3A8.38 8.38 0 013 11.5C3 6.81 7.03 3 12 3s9 3.81 9 8.5-4.03 8.5-9 8.5a9.7 9.7 0 01-3.2-.54L4 20.5z" />
            </svg>
        ),
        'email': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
            </svg>
        ),
    };
    return icons[name] || icons['email'];
}

const CHANNEL_STYLES = {
    'whatsapp':  { bg: 'bg-[#25D366]/12', text: 'text-[#25D366]' },
    'messenger': { bg: 'bg-[#0084FF]/12', text: 'text-[#0084FF]' },
    'instagram': { bg: 'bg-[#E1306C]/12', text: 'text-[#E1306C]' },
    'sms':       { bg: 'bg-brand-500/15', text: 'text-brand-600 dark:text-brand-500' },
    'email':     { bg: 'bg-[#F59E0B]/12', text: 'text-[#F59E0B]' },
};

// ─── Section Badge ─────────────────────────────────────────────────────────────

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-500/20 text-brand-300 text-xs font-semibold px-3 py-1 border border-brand-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-400 inline-block" />
            {text}
        </span>
    );
}

// ─── Hero Section ─────────────────────────────────────────────────────────────

function HeroSection({ landing, canLogin, canRegister, auth }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('hero_enabled') !== '1') return null;

    return (
        <section
            className="relative overflow-hidden"
            style={{
                background: 'radial-gradient(ellipse 70% 65% at 62% 45%, rgb(var(--brand-400) / 0.22) 0%, rgb(var(--brand-400) / 0.08) 40%, transparent 70%), rgb(var(--brand-950))',
            }}
        >
            {/* Background grid */}
            <div
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage: `
                        linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px)
                    `,
                    backgroundSize: '60px 60px',
                    maskImage: 'radial-gradient(ellipse 80% 80% at 50% 0%, black 40%, transparent 100%)',
                    WebkitMaskImage: 'radial-gradient(ellipse 80% 80% at 50% 0%, black 40%, transparent 100%)',
                }}
            />

            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-16 sm:pt-24 sm:pb-20 text-center relative">
                {s('hero_badge') && (
                    <div className="mb-6 flex justify-center">
                        <Badge text={s('hero_badge')} />
                    </div>
                )}

                <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-white max-w-4xl mx-auto leading-tight">
                    {s('hero_title')}
                </h1>

                <p className="mt-6 text-lg sm:text-xl text-neutral-300 max-w-2xl mx-auto leading-relaxed">
                    {s('hero_subtitle')}
                </p>

                <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
                    {auth?.user ? (
                        <Link
                            href={route('client.dashboard')}
                            className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                            style={{ background: 'rgb(var(--brand-500))' }}
                        >
                            {t('welcome.goToDashboard')}
                        </Link>
                    ) : (
                        <>
                            {canRegister && s('hero_cta_primary') && (
                                <Link
                                    href={route('register')}
                                    className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                                    style={{ background: 'rgb(var(--brand-500))' }}
                                >
                                    {s('hero_cta_primary')}
                                </Link>
                            )}
                            {s('hero_cta_secondary') && (
                                <Link
                                    href={route('pricing')}
                                    className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 backdrop-blur-sm px-7 py-3.5 text-base font-semibold text-white hover:bg-white/15 transition-all duration-200"
                                >
                                    {s('hero_cta_secondary')}
                                </Link>
                            )}
                        </>
                    )}
                </div>

                {/* Trust badges */}
                {(() => {
                    const badges = [1, 2, 3].map((i) => s(`hero_trust_${i}`)).filter(Boolean);
                    if (!badges.length) return null;
                    return (
                        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                            {badges.map((badge, idx) => (
                                <span
                                    key={idx}
                                    className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/8 backdrop-blur-sm px-4 py-2 text-sm font-medium text-white/90"
                                >
                                    <svg className="h-4 w-4 flex-shrink-0 text-brand-500" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {badge}
                                </span>
                            ))}
                        </div>
                    );
                })()}
            </div>
        </section>
    );
}

// ─── Trusted By Section ───────────────────────────────────────────────────────

function TrustedBySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('stats_enabled') !== '1') return null;

    const brands = [1, 2, 3, 4, 5, 6].map((i) => s(`stats_${i}_label`)).filter(Boolean);
    if (!brands.length) return null;

    const heading = s('stats_heading', 'Trusted by thousands of businesses across the world');

    return (
        <section className="border-y border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-900/50 py-6 overflow-hidden">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                {heading && (
                    <p className="text-center text-xs font-medium text-neutral-400 dark:text-neutral-500 uppercase tracking-widest mb-5">
                        {heading}
                    </p>
                )}
                <div className="flex items-center justify-center flex-wrap gap-8">
                    {brands.map((brand, idx) => (
                        <span
                            key={idx}
                            className="text-lg font-bold text-neutral-300 dark:text-neutral-600 tracking-tight select-none hover:text-neutral-400 dark:hover:text-neutral-500 transition-colors"
                        >
                            {brand}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── Problem / Solution Section ───────────────────────────────────────────────

function ProblemSolutionSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('problems_enabled') !== '1') return null;

    const problems = [1, 2, 3, 4].map((i) => s(`problem_${i}`)).filter(Boolean);
    const solutions = [1, 2, 3, 4].map((i) => s(`solution_${i}`)).filter(Boolean);
    const problemTitle = s('problems_title', 'The Problem');
    const solutionTitle = s('solution_title', 'The Solution');
    const solutionDesc = s('solution_desc', '');

    if (!problems.length && !solutions.length) return null;

    return (
        <section className="py-14 sm:py-20">
            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="grid gap-6 md:grid-cols-2">
                    {/* Problem */}
                    <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-8">
                        <h3 className="text-xl font-bold text-neutral-900 dark:text-white mb-6">{problemTitle}</h3>
                        <ul className="space-y-4">
                            {problems.map((item, idx) => (
                                <li key={idx} className="flex items-start gap-3">
                                    <span className="mt-0.5 flex-shrink-0 h-5 w-5 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                        <svg className="h-3 w-3 text-red-500" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </span>
                                    <span className="text-sm text-neutral-600 dark:text-neutral-400">{item}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Solution */}
                    <div
                        className="rounded-2xl p-8"
                        style={{ background: 'linear-gradient(135deg, rgb(var(--brand-400) / 0.15) 0%, rgb(var(--brand-400) / 0.05) 100%)', border: '1px solid rgb(var(--brand-400) / 0.3)' }}
                    >
                        <h3 className="text-xl font-bold text-neutral-900 dark:text-white mb-2">{solutionTitle}</h3>
                        {solutionDesc && (
                            <p className="text-sm text-neutral-600 dark:text-neutral-400 mb-6">{solutionDesc}</p>
                        )}
                        <ul className="space-y-4">
                            {solutions.map((item, idx) => (
                                <li key={idx} className="flex items-start gap-3">
                                    <span className="mt-0.5 flex-shrink-0 h-5 w-5 rounded-full flex items-center justify-center" style={{ background: 'rgb(var(--brand-400) / 0.2)' }}>
                                        <svg className="h-3 w-3" style={{ color: 'rgb(var(--brand-500))' }} fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </span>
                                    <span className="text-sm text-neutral-700 dark:text-neutral-300">{item}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── Features Section ─────────────────────────────────────────────────────────

function FeaturesSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('features_enabled') !== '1') return null;

    const features = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => ({
        icon: s(`feature_${i}_icon`, 'zap'),
        title: s(`feature_${i}_title`),
        desc: s(`feature_${i}_desc`),
    })).filter((f) => f.title);

    return (
        <section id="features" className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('features_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('features_title')}
                    </h2>
                    {s('features_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">
                            {s('features_subtitle')}
                        </p>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((feat, idx) => (
                        <div
                            key={idx}
                            className="group relative overflow-hidden rounded-2xl border border-neutral-200/80 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-7 transition-all duration-300 hover:-translate-y-1 hover:border-brand-500/40 hover:shadow-xl hover:shadow-brand-500/10"
                        >
                            {/* Soft glow that fades in on hover */}
                            <div
                                className="pointer-events-none absolute -right-12 -top-12 h-40 w-40 rounded-full opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100"
                                style={{ background: 'radial-gradient(circle, rgb(var(--brand-400) / 0.18) 0%, transparent 70%)' }}
                            />

                            <div className="relative">
                                <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 dark:text-brand-500 ring-1 ring-inset ring-brand-500/15 transition-all duration-300 group-hover:bg-brand-500 group-hover:text-white group-hover:ring-brand-500 group-hover:shadow-lg group-hover:shadow-brand-500/30">
                                    <FeatureIcon name={feat.icon} className="h-6 w-6" />
                                </div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-2 transition-colors group-hover:text-brand-600 dark:group-hover:text-brand-500">{feat.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{feat.desc}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── How It Works Section ─────────────────────────────────────────────────────

function HowItWorksSection({ landing, canRegister }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('howitworks_enabled') !== '1') return null;

    const steps = [1, 2, 3].map((i) => ({
        title: s(`step_${i}_title`),
        desc: s(`step_${i}_desc`),
    })).filter((st) => st.title);

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('howitworks_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('howitworks_title')}
                    </h2>
                    {s('howitworks_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{s('howitworks_subtitle')}</p>
                    )}
                </div>

                <div className="relative">
                    <div className="hidden lg:block absolute top-12 left-1/6 right-1/6 h-0.5 bg-gradient-to-r from-brand-500/20 via-brand-500/50 to-brand-500/20" />
                    <div className="grid gap-10 lg:grid-cols-3">
                        {steps.map((step, idx) => (
                            <div key={idx} className="relative text-center">
                                <div
                                    className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full font-bold text-xl shadow-lg ring-4 ring-white dark:ring-neutral-950 text-white"
                                    style={{ background: 'rgb(var(--brand-500))', boxShadow: '0 8px 24px rgb(var(--brand-400) / 0.3)' }}
                                >
                                    {idx + 1}
                                </div>
                                <h3 className="text-lg font-semibold text-neutral-900 dark:text-white mb-2">{step.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed max-w-xs mx-auto">{step.desc}</p>
                            </div>
                        ))}
                    </div>
                </div>

                {canRegister && (
                    <div className="mt-14 text-center">
                        <Link
                            href={route('register')}
                            className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                            style={{ background: 'rgb(var(--brand-500))' }}
                        >
                            {t('welcome.get_started_free')}
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                        </Link>
                    </div>
                )}
            </div>
        </section>
    );
}

// ─── Pricing Section ──────────────────────────────────────────────────────────

function PricingSection({ plans }) {
    const { t } = useTranslation();
    const [yearly, setYearly] = useState(false);
    if (!plans || !plans.length) return null;

    return (
        <section id="pricing" className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-12">
                    <Badge text={t('welcome.badge_pricing')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {t('pricing.simple_transparent')}
                    </h2>
                    <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">
                        {t('pricing.choose_plan_anytime')}
                    </p>

                    <div className="mt-8 inline-flex items-center gap-1 rounded-xl bg-neutral-100 dark:bg-neutral-800 p-1">
                        <button
                            onClick={() => setYearly(false)}
                            className={`rounded-lg px-5 py-2 text-sm font-semibold transition-all ${!yearly ? 'bg-white dark:bg-neutral-700 shadow-sm text-neutral-900 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'}`}
                        >
                            {t('welcome.monthly')}
                        </button>
                        <button
                            onClick={() => setYearly(true)}
                            className={`rounded-lg px-5 py-2 text-sm font-semibold transition-all ${yearly ? 'bg-white dark:bg-neutral-700 shadow-sm text-neutral-900 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'}`}
                        >
                            {t('welcome.yearly')}
                            <span className="ml-1.5 rounded-full bg-brand-500/20 text-brand-600 dark:text-brand-500 text-xs px-1.5 py-0.5 font-bold">-20%</span>
                        </button>
                    </div>
                </div>

                <div className={`grid gap-6 ${plans.length <= 2 ? 'sm:grid-cols-2 max-w-2xl mx-auto' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                    {plans.map((plan) => {
                        const price = yearly ? plan.price_yearly : plan.price_monthly;
                        return (
                            <div
                                key={plan.id}
                                className={`relative rounded-2xl border p-7 flex flex-col ${
                                    plan.is_featured
                                        ? 'border-brand-500/50 bg-brand-950 text-white shadow-2xl shadow-brand-500/10 lg:scale-105'
                                        : 'border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900'
                                }`}
                            >
                                {plan.is_featured && (
                                    <div className="absolute -top-3.5 left-1/2 -translate-x-1/2">
                                        <span className="rounded-full text-xs font-bold px-3 py-1 shadow" style={{ background: 'rgb(var(--brand-500))', color: '#ffffff' }}>{t('welcome.most_popular')}</span>
                                    </div>
                                )}

                                <div>
                                    <h3 className={`text-lg font-bold ${plan.is_featured ? 'text-white' : 'text-neutral-900 dark:text-white'}`}>{plan.name}</h3>
                                    {plan.description && (
                                        <p className={`mt-1 text-sm ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-500 dark:text-neutral-400'}`}>{plan.description}</p>
                                    )}
                                </div>

                                <div className="mt-5 mb-6">
                                    <span className={`text-4xl font-bold ${plan.is_featured ? 'text-white' : 'text-neutral-900 dark:text-white'}`}>
                                        {price === 0 ? t('welcome.free') : `$${parseFloat(price).toFixed(0)}`}
                                    </span>
                                    {price > 0 && (
                                        <span className={`text-sm ml-1 ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-400'}`}>{yearly ? t('welcome.per_year') : t('welcome.per_month')}</span>
                                    )}
                                    {plan.trial_days > 0 && (
                                        <p className={`text-xs mt-1 ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-400 dark:text-neutral-500'}`}>
                                            {t('pricing.trial_days', { days: plan.trial_days })}
                                        </p>
                                    )}
                                </div>

                                {plan.features && plan.features.length > 0 && (
                                    <ul className="space-y-2.5 flex-1 mb-7">
                                        {plan.features.map((feat, fi) => (
                                            <li key={fi} className="flex items-start gap-2.5 text-sm">
                                                <svg className="h-4 w-4 mt-0.5 flex-shrink-0" style={{ color: 'rgb(var(--brand-500))' }} fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                                <span className={plan.is_featured ? 'text-neutral-300' : 'text-neutral-700 dark:text-neutral-300'}>{feat}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <Link
                                    href={route('register')}
                                    className={`block text-center rounded-xl py-3 text-sm font-bold transition-all duration-200 ${
                                        plan.is_featured
                                            ? 'text-white hover:opacity-90'
                                            : 'border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-500/50 hover:text-brand-600 dark:hover:text-brand-500'
                                    }`}
                                    style={plan.is_featured ? { background: 'rgb(var(--brand-500))' } : {}}
                                >
                                    {plan.is_featured ? t('welcome.get_started_plan') : t('welcome.upgrade')}
                                </Link>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

// ─── Why Section ──────────────────────────────────────────────────────────────

function WhySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('why_enabled') !== '1') return null;

    const items = [1, 2, 3, 4, 5, 6].map((i) => ({
        icon: s(`why_${i}_icon`, 'zap'),
        title: s(`why_${i}_title`),
        desc: s(`why_${i}_desc`),
    })).filter((f) => f.title);

    if (!items.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('why_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('why_title')}
                    </h2>
                    {s('why_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">
                            {s('why_subtitle')}
                        </p>
                    )}
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((item, idx) => (
                        <div key={idx} className="flex gap-4 p-6 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 hover:border-brand-500/30 transition-colors">
                            <div className="flex-shrink-0 h-11 w-11 rounded-xl flex items-center justify-center" style={{ background: 'rgb(var(--brand-400) / 0.12)' }}>
                                <FeatureIcon name={item.icon} className="h-5 w-5" style={{ color: 'rgb(var(--brand-500))' }} />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-1">{item.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{item.desc}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── Testimonials Section ─────────────────────────────────────────────────────

function StarRating() {
    return (
        <div className="flex gap-0.5">
            {[...Array(5)].map((_, i) => (
                <svg key={i} className="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
            ))}
        </div>
    );
}

function TestimonialsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('testimonials_enabled') !== '1') return null;

    const testimonials = [1, 2, 3, 4, 5, 6].map((i) => ({
        name: s(`testimonial_${i}_name`),
        role: s(`testimonial_${i}_role`),
        text: s(`testimonial_${i}_text`),
        avatar: s(`testimonial_${i}_avatar`),
    })).filter((t) => t.name && t.text);

    if (!testimonials.length) return null;

    return (
        <section className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('testimonials_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('testimonials_title')}
                    </h2>
                    {s('testimonials_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{s('testimonials_subtitle')}</p>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {testimonials.map((t, idx) => (
                        <div
                            key={idx}
                            className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 flex flex-col gap-4"
                        >
                            <StarRating />
                            <blockquote className="text-sm text-neutral-700 dark:text-neutral-300 leading-relaxed flex-1">
                                &ldquo;{t.text}&rdquo;
                            </blockquote>
                            <div className="flex items-center gap-3 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                                {t.avatar ? (
                                    <img src={t.avatar} alt={t.name} className="h-9 w-9 rounded-full object-cover" />
                                ) : (
                                    <div className="h-9 w-9 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0" style={{ background: 'rgb(var(--brand-500))' }}>
                                        {t.name.charAt(0)}
                                    </div>
                                )}
                                <div>
                                    <p className="text-sm font-semibold text-neutral-900 dark:text-white">{t.name}</p>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{t.role}</p>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── FAQ Section ──────────────────────────────────────────────────────────────

function FaqSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    const [open, setOpen] = useState(null);
    if (s('faq_enabled') !== '1') return null;

    const faqs = [1, 2, 3, 4, 5].map((i) => ({
        q: s(`faq_${i}_q`),
        a: s(`faq_${i}_a`),
    })).filter((f) => f.q && f.a);

    if (!faqs.length) return null;

    return (
        <section id="faq" className="py-16 sm:py-24">
            <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-14">
                    <Badge text={s('faq_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('faq_title')}
                    </h2>
                    {s('faq_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{s('faq_subtitle')}</p>
                    )}
                </div>

                <div className="space-y-2">
                    {faqs.map((faq, idx) => (
                        <div
                            key={idx}
                            className="rounded-xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 overflow-hidden"
                        >
                            <button
                                className="w-full flex items-center justify-between px-5 py-4 text-left gap-4"
                                onClick={() => setOpen(open === idx ? null : idx)}
                            >
                                <span className="font-medium text-neutral-900 dark:text-white text-sm">{faq.q}</span>
                                <svg
                                    className={`h-5 w-5 flex-shrink-0 text-neutral-400 transition-transform duration-200 ${open === idx ? 'rotate-180' : ''}`}
                                    fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            {open === idx && (
                                <div className="px-5 pb-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{faq.a}</p>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── CTA Section ──────────────────────────────────────────────────────────────

function CtaSection({ landing, canRegister }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('cta_enabled') !== '1') return null;

    return (
        <section className="py-14 sm:py-20">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div
                    className="relative overflow-hidden rounded-3xl px-6 py-12 sm:px-8 sm:py-16 text-center"
                    style={{
                        background: 'radial-gradient(ellipse 60% 80% at 50% 50%, rgb(var(--brand-400) / 0.15) 0%, transparent 70%), rgb(var(--brand-950))',
                        border: '1px solid rgb(var(--brand-400) / 0.2)',
                    }}
                >
                    {/* Grid overlay */}
                    <div
                        className="pointer-events-none absolute inset-0 rounded-3xl"
                        style={{
                            backgroundImage: `
                                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px)
                            `,
                            backgroundSize: '50px 50px',
                        }}
                    />

                    <h2 className="relative text-3xl sm:text-4xl font-bold text-white tracking-tight max-w-2xl mx-auto">
                        {s('cta_title')}
                    </h2>
                    <p className="relative mt-4 text-lg text-neutral-400 max-w-xl mx-auto">
                        {s('cta_subtitle')}
                    </p>

                    <div className="relative mt-10 flex flex-wrap items-center justify-center gap-4">
                        {canRegister && s('cta_primary') && (
                            <Link
                                href={route('register')}
                                className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                                style={{ background: 'rgb(var(--brand-500))' }}
                            >
                                {s('cta_primary')}
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </Link>
                        )}
                        {s('cta_secondary') && (
                            <Link
                                href={route('contact')}
                                className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/8 text-white px-7 py-3.5 text-base font-semibold hover:bg-white/15 backdrop-blur-sm transition-all duration-200"
                            >
                                {s('cta_secondary')}
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── Metrics Section (numbers band) ───────────────────────────────────────────

function MetricsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('metrics_enabled') !== '1') return null;

    const metrics = [1, 2, 3, 4].map((i) => ({
        value: s(`metric_${i}_value`),
        label: s(`metric_${i}_label`),
    })).filter((m) => m.value);

    if (!metrics.length) return null;

    return (
        <section className="relative -mt-px border-b border-white/10" style={{ background: 'rgb(var(--brand-950))' }}>
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
                    {metrics.map((m, idx) => (
                        <div key={idx} className="text-center">
                            <p className="text-3xl sm:text-4xl font-bold tracking-tight" style={{ color: 'rgb(var(--brand-500))' }}>{m.value}</p>
                            <p className="mt-1 text-sm text-neutral-400">{m.label}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── Channels Section ─────────────────────────────────────────────────────────

function ChannelsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('channels_enabled') !== '1') return null;

    const channels = [1, 2, 3, 4, 5].map((i) => ({
        key: s(`channel_${i}_key`, 'email'),
        title: s(`channel_${i}_title`),
        desc: s(`channel_${i}_desc`),
    })).filter((c) => c.title);

    if (!channels.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('channels_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('channels_title')}
                    </h2>
                    {s('channels_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('channels_subtitle')}</p>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {channels.map((c, idx) => {
                        const style = CHANNEL_STYLES[c.key] || CHANNEL_STYLES['email'];
                        return (
                            <div
                                key={idx}
                                className="group rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 hover:shadow-lg transition-all duration-300"
                            >
                                <div className={`mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl ${style.bg} ${style.text}`}>
                                    <ChannelIcon name={c.key} className="h-6 w-6" />
                                </div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-2">{c.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{c.desc}</p>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

// ─── Integrations Strip Section ───────────────────────────────────────────────

function IntegrationsStripSection({ landing }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('integrations_strip_enabled') !== '1') return null;

    // Pull the integration names from the integrations-page settings to show a strip.
    const names = [1, 2, 3, 4, 5, 6]
        .flatMap((i) => (s(`intcat_${i}_items`) || '').split('\n'))
        .map((n) => n.trim())
        .filter(Boolean)
        .slice(0, 12);

    return (
        <section className="py-14 sm:py-20 bg-neutral-50 dark:bg-neutral-900/30 border-y border-neutral-200 dark:border-neutral-800">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 dark:text-white tracking-tight">
                    {s('integrations_strip_title')}
                </h2>
                {s('integrations_strip_subtitle') && (
                    <p className="mt-3 text-base text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('integrations_strip_subtitle')}</p>
                )}

                {names.length > 0 && (
                    <div className="mt-10 flex flex-wrap items-center justify-center gap-3">
                        {names.map((name, idx) => (
                            <span
                                key={idx}
                                className="inline-flex items-center gap-2 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300"
                            >
                                <BrandMark name={name} tileClassName="h-6 w-6 rounded-md" glyphClassName="h-4 w-4" monogramClassName="text-[0.65rem]" />
                                {name}
                            </span>
                        ))}
                    </div>
                )}

                <div className="mt-10">
                    <Link href="/integrations" className="inline-flex items-center gap-2 text-sm font-semibold text-brand-600 dark:text-brand-500 hover:underline">
                        {t('welcome.view_all_integrations', { defaultValue: 'View all integrations' })}
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </Link>
                </div>
            </div>
        </section>
    );
}

// ─── Security Section ─────────────────────────────────────────────────────────

function SecuritySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('security_enabled') !== '1') return null;

    const items = [1, 2, 3, 4].map((i) => ({
        icon: s(`security_${i}_icon`, 'shield-check'),
        title: s(`security_${i}_title`),
        desc: s(`security_${i}_desc`),
    })).filter((it) => it.title);

    if (!items.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('security_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('security_title')}
                    </h2>
                    {s('security_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('security_subtitle')}</p>
                    )}
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {items.map((item, idx) => (
                        <div key={idx} className="text-center p-6 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900">
                            <div className="mx-auto mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl" style={{ background: 'rgb(var(--brand-400) / 0.12)' }}>
                                <FeatureIcon name={item.icon} className="h-6 w-6" style={{ color: 'rgb(var(--brand-500))' }} />
                            </div>
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-1">{item.title}</h3>
                            <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{item.desc}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Welcome({ auth, canLogin, canRegister, landing = {}, plans = [] }) {
    const { appName } = useBranding();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const metaTitle = s('seo_title') || s('hero_title') || appName;
    const metaDesc  = s('seo_description') || s('hero_subtitle') || '';
    const origin = typeof window !== 'undefined' ? window.location.origin : '';

    // ── JSON-LD structured data ────────────────────────────────────────────────
    const faqs = [1, 2, 3, 4, 5]
        .map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) }))
        .filter((f) => f.q && f.a);

    const jsonLd = [
        {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: appName,
            url: origin || undefined,
            logo: origin ? `${origin}/favicon.ico` : undefined,
            description: metaDesc,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'SoftwareApplication',
            name: appName,
            applicationCategory: 'BusinessApplication',
            operatingSystem: 'Web',
            description: metaDesc,
            offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
            aggregateRating: { '@type': 'AggregateRating', ratingValue: '4.9', ratingCount: '1280' },
        },
    ];

    if (faqs.length) {
        jsonLd.push({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: faqs.map((f) => ({
                '@type': 'Question',
                name: f.q,
                acceptedAnswer: { '@type': 'Answer', text: f.a },
            })),
        });
    }

    return (
        <LandingLayout>
            <SeoHead
                title={metaTitle}
                description={metaDesc}
                keywords={s('seo_keywords')}
                image={s('seo_og_image') || undefined}
                jsonLd={jsonLd}
            />

            <HeroSection landing={landing} canLogin={canLogin} canRegister={canRegister} auth={auth} />
            <MetricsSection landing={landing} />
            <TrustedBySection landing={landing} />
            <ChannelsSection landing={landing} />
            <ProblemSolutionSection landing={landing} />
            <FeaturesSection landing={landing} />
            <HowItWorksSection landing={landing} canRegister={canRegister} />
            <IntegrationsStripSection landing={landing} />
            <WhySection landing={landing} />
            <SecuritySection landing={landing} />
            <PricingSection plans={plans} />
            <TestimonialsSection landing={landing} />
            <FaqSection landing={landing} />
            <CtaSection landing={landing} canRegister={canRegister} />
        </LandingLayout>
    );
}
