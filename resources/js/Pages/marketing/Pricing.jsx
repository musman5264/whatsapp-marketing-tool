import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import { useTranslation } from 'react-i18next';

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-500/15 text-brand-500 text-xs font-semibold px-3 py-1 border border-brand-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-500 inline-block" />
            {text}
        </span>
    );
}

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

export default function Pricing({ landing = {}, plans = [], canRegister }) {
    const { t } = useTranslation();
    const [yearly, setYearly] = useState(false);
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const faqs = [1, 2, 3, 4, 5].map((i) => ({
        q: s(`faq_${i}_q`),
        a: s(`faq_${i}_a`),
    })).filter((f) => f.q && f.a);

    const testimonials = [1, 2, 3, 4, 5, 6].map((i) => ({
        name: s(`testimonial_${i}_name`),
        role: s(`testimonial_${i}_role`),
        text: s(`testimonial_${i}_text`),
    })).filter((t) => t.name && t.text);

    const [openFaq, setOpenFaq] = useState(null);

    return (
        <LandingLayout>
            <Head>
                <title>{t('pricing.head_title')}</title>
                <meta name="description" content={t('pricing.choose_plan_anytime')} />
            </Head>

            {/* ── Page hero ── */}
            <section
                className="relative overflow-hidden py-20 text-center"
                style={{ background: 'radial-gradient(ellipse 60% 60% at 50% 0%, rgb(var(--brand-400) / 0.18) 0%, transparent 70%), rgb(var(--brand-950))' }}
            >
                <div
                    className="pointer-events-none absolute inset-0"
                    style={{
                        backgroundImage: `linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px)`,
                        backgroundSize: '60px 60px',
                        maskImage: 'radial-gradient(ellipse 80% 100% at 50% 0%, black 50%, transparent 100%)',
                        WebkitMaskImage: 'radial-gradient(ellipse 80% 100% at 50% 0%, black 50%, transparent 100%)',
                    }}
                />
                <div className="relative max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                    <Badge text={t('welcome.badge_pricing')} />
                    <h1 className="mt-4 text-4xl sm:text-5xl font-bold text-white tracking-tight">{t('pricing.simple_transparent')}</h1>
                    <p className="mt-4 text-lg text-neutral-300 max-w-xl mx-auto">
                        {t('pricing.choose_plan_no_fees')}
                    </p>

                    {/* Billing toggle */}
                    <div className="mt-8 inline-flex items-center gap-1 rounded-xl p-1" style={{ background: 'rgba(255,255,255,0.08)' }}>
                        <button
                            onClick={() => setYearly(false)}
                            className={`rounded-lg px-5 py-2 text-sm font-semibold transition-all ${!yearly ? 'bg-white text-neutral-900 shadow' : 'text-white/70 hover:text-white'}`}
                        >
                            {t('welcome.monthly')}
                        </button>
                        <button
                            onClick={() => setYearly(true)}
                            className={`rounded-lg px-5 py-2 text-sm font-semibold transition-all flex items-center gap-2 ${yearly ? 'bg-white text-neutral-900 shadow' : 'text-white/70 hover:text-white'}`}
                        >
                            {t('welcome.yearly')}
                            <span className="rounded-full text-xs px-1.5 py-0.5 font-bold" style={{ background: 'rgb(var(--brand-500))', color: '#ffffff' }}>-20%</span>
                        </button>
                    </div>
                </div>
            </section>

            {/* ── Plans grid ── */}
            <section className="py-16 bg-neutral-50 dark:bg-neutral-900/30">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                    {plans.length === 0 ? (
                        <p className="text-center text-neutral-500 dark:text-neutral-400 py-16">{t('pricing.no_plans_yet')}</p>
                    ) : (
                        <div className={`grid gap-6 ${plans.length <= 2 ? 'sm:grid-cols-2 max-w-2xl mx-auto' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                            {plans.map((plan) => {
                                const price = yearly ? plan.price_yearly : plan.price_monthly;
                                return (
                                    <div
                                        key={plan.id}
                                        className={`relative rounded-2xl border p-7 flex flex-col ${
                                            plan.is_featured
                                                ? 'border-brand-500/50 shadow-2xl shadow-brand-500/10 scale-105'
                                                : 'border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900'
                                        }`}
                                        style={plan.is_featured ? { background: 'rgb(var(--brand-950))' } : {}}
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
                                        {canRegister && (
                                            <Link
                                                href={route('register')}
                                                className={`block text-center rounded-xl py-3 text-sm font-bold transition-all duration-200 ${
                                                    plan.is_featured ? 'text-white hover:opacity-90' : 'border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-500/50 hover:text-brand-600 dark:hover:text-brand-500'
                                                }`}
                                                style={plan.is_featured ? { background: 'rgb(var(--brand-500))' } : {}}
                                            >
                                                {price === 0 ? t('welcome.get_started_free') : t('welcome.upgrade')}
                                            </Link>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Money-back */}
                    <p className="mt-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        {t('pricing.money_back')}
                    </p>
                </div>
            </section>

            {/* ── Testimonials ── */}
            {testimonials.length > 0 && (
                <section className="py-20">
                    <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                        <div className="text-center mb-12">
                            <Badge text={t('welcome.badge_customers')} />
                            <h2 className="mt-4 text-3xl font-bold text-neutral-900 dark:text-white tracking-tight">
                                {s('testimonials_title', 'What Our Customers Say')}
                            </h2>
                        </div>
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {testimonials.map((t, idx) => (
                                <div key={idx} className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 flex flex-col gap-4">
                                    <StarRating />
                                    <blockquote className="text-sm text-neutral-700 dark:text-neutral-300 leading-relaxed flex-1">&ldquo;{t.text}&rdquo;</blockquote>
                                    <div className="flex items-center gap-3 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                                        <div className="h-9 w-9 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0" style={{ background: 'rgb(var(--brand-500))' }}>
                                            {t.name.charAt(0)}
                                        </div>
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
            )}

            {/* ── FAQ ── */}
            {faqs.length > 0 && (
                <section className="py-20 bg-neutral-50 dark:bg-neutral-900/30">
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                        <div className="text-center mb-12">
                            <Badge text={t('welcome.badge_faq')} />
                            <h2 className="mt-4 text-3xl font-bold text-neutral-900 dark:text-white tracking-tight">{t('pricing.pricing_faq')}</h2>
                        </div>
                        <div className="space-y-2">
                            {faqs.map((faq, idx) => (
                                <div key={idx} className="rounded-xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 overflow-hidden">
                                    <button
                                        className="w-full flex items-center justify-between px-5 py-4 text-left gap-4"
                                        onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
                                    >
                                        <span className="font-medium text-neutral-900 dark:text-white text-sm">{faq.q}</span>
                                        <svg className={`h-5 w-5 flex-shrink-0 text-neutral-400 transition-transform duration-200 ${openFaq === idx ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                    {openFaq === idx && (
                                        <div className="px-5 pb-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
                                            <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{faq.a}</p>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* ── CTA ── */}
            {canRegister && (
                <section className="py-16">
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                        <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 dark:text-white">{t('pricing.ready_to_start')}</h2>
                        <p className="mt-3 text-neutral-500 dark:text-neutral-400">{t('pricing.ready_to_start_desc')}</p>
                        <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
                            <Link
                                href={route('register')}
                                className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                                style={{ background: 'rgb(var(--brand-500))' }}
                            >
                                {t('pricing.start_free_trial')}
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </Link>
                            <Link href="/contact" className="inline-flex items-center gap-2 rounded-xl border border-neutral-300 dark:border-neutral-700 px-7 py-3.5 text-base font-semibold text-neutral-700 dark:text-neutral-300 hover:border-neutral-400 transition-all duration-200">
                                {t('pricing.talk_to_sales')}
                            </Link>
                        </div>
                    </div>
                </section>
            )}
        </LandingLayout>
    );
}
