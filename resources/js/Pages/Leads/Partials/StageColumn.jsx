import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Pencil, Trophy, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import LeadCard from './LeadCard';

export const STAGE_COLORS = {
    neutral: 'bg-neutral-400',
    blue:    'bg-blue-500',
    violet:  'bg-violet-500',
    emerald: 'bg-emerald-500',
    amber:   'bg-amber-500',
    coral:   'bg-coral-500',
};

export default function StageColumn({ stage, onOpenLead, onEditStage }) {
    const { t } = useTranslation();
    const { setNodeRef, isOver } = useDroppable({ id: `stage-${stage.id}`, data: { type: 'stage', stage } });
    const leadIds = stage.leads.map((l) => l.id);
    const truncated = Math.max(0, (stage.total ?? stage.leads.length) - stage.leads.length);

    return (
        <div className="flex w-72 shrink-0 flex-col">
            <div className="mb-2 flex items-center justify-between gap-2 px-1">
                <div className="flex min-w-0 items-center gap-2">
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${STAGE_COLORS[stage.color] ?? STAGE_COLORS.neutral}`} />
                    <h3 className="truncate text-sm font-semibold text-neutral-800 dark:text-neutral-200">{stage.name}</h3>
                    {stage.is_won && <Trophy className="h-3.5 w-3.5 shrink-0 text-emerald-500" aria-label={t('leads.stage_won')} />}
                    {stage.is_lost && <XCircle className="h-3.5 w-3.5 shrink-0 text-coral-500" aria-label={t('leads.stage_lost')} />}
                    <span className="shrink-0 rounded-full bg-neutral-100 px-1.5 text-xs text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                        {stage.total ?? stage.leads.length}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={() => onEditStage(stage)}
                    className="shrink-0 rounded p-1 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                    aria-label={t('leads.edit_stage_named', { name: stage.name })}
                >
                    <Pencil className="h-3.5 w-3.5" />
                </button>
            </div>

            <div
                ref={setNodeRef}
                className={[
                    'flex-1 space-y-2 rounded-soft border border-dashed p-2 transition-colors',
                    'min-h-[8rem]',
                    isOver
                        ? 'border-brand-400 bg-brand-50/60 dark:border-brand-600 dark:bg-brand-900/20'
                        : 'border-neutral-200 bg-neutral-50/60 dark:border-neutral-800 dark:bg-neutral-900/40',
                ].join(' ')}
            >
                <SortableContext items={leadIds} strategy={verticalListSortingStrategy}>
                    {stage.leads.map((lead) => (
                        <LeadCard key={lead.id} lead={lead} onOpen={onOpenLead} />
                    ))}
                </SortableContext>

                {stage.leads.length === 0 && (
                    <p className="py-6 text-center text-xs text-neutral-400 dark:text-neutral-600">
                        {t('leads.stage_empty')}
                    </p>
                )}

                {/* Say so when the column is truncated, rather than quietly showing
                    a slice as if it were everything. */}
                {truncated > 0 && (
                    <p className="pt-1 text-center text-xs text-neutral-400 dark:text-neutral-500">
                        {t('leads.more_in_stage', { count: truncated })}
                    </p>
                )}
            </div>
        </div>
    );
}
