import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Phone, Globe, Star, UserCheck, GripVertical } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ScoreBadge from './ScoreBadge';
import FollowUpBadge from './FollowUpBadge';

/**
 * The card's looks, with no dnd wiring at all.
 *
 * DragOverlay renders this directly: giving the overlay its own useSortable would
 * register a second node under the same lead id, and dnd-kit would then measure
 * the drag against the floating overlay instead of the real card — drops silently
 * stop resolving.
 */
export function LeadCardView({ lead, onOpen, dragging = false, overlay = false, grip = null }) {
    const { t } = useTranslation();

    return (
        <div
            className={[
                'group rounded-soft border border-soft bg-white p-3 dark:bg-neutral-900',
                'shadow-sm transition-shadow',
                dragging ? 'opacity-40' : 'hover:shadow-md',
                overlay ? 'rotate-2 shadow-lg ring-2 ring-brand-400' : '',
            ].join(' ')}
        >
            <div className="flex items-start justify-between gap-2">
                <button
                    type="button"
                    onClick={() => onOpen?.(lead)}
                    className="min-w-0 flex-1 text-left"
                >
                    <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">
                        {lead.name || t('leads.unnamed')}
                    </p>
                    {lead.category && (
                        <p className="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{lead.category}</p>
                    )}
                </button>

                <div className="flex shrink-0 items-center gap-1">
                    <ScoreBadge band={lead.score_band} score={lead.score} />
                    {grip ?? (
                        <span className="text-neutral-300 dark:text-neutral-600" aria-hidden="true">
                            <GripVertical className="h-4 w-4" />
                        </span>
                    )}
                </div>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                {lead.phone && (
                    <span className="inline-flex items-center gap-1">
                        <Phone className="h-3 w-3" aria-hidden="true" />
                        {lead.phone}
                    </span>
                )}
                {lead.rating > 0 && (
                    <span className="inline-flex items-center gap-1">
                        <Star className="h-3 w-3" aria-hidden="true" />
                        {lead.rating} ({lead.review_count})
                    </span>
                )}
                {lead.website && <Globe className="h-3 w-3" aria-label={t('leads.has_website')} />}
                {lead.contact && (
                    <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                        <UserCheck className="h-3 w-3" aria-hidden="true" />
                        {t('leads.in_contacts')}
                    </span>
                )}
            </div>

            {lead.next_follow_up_at && (
                <div className="mt-2">
                    <FollowUpBadge date={lead.next_follow_up_at} />
                </div>
            )}
        </div>
    );
}

/**
 * A lead on the board.
 *
 * Pointer drag works from anywhere on the card (listeners on the root), which is
 * what people actually try first. The sensor's 5px activation distance is what
 * still lets a plain click through to the title button underneath.
 *
 * Keyboard drag lives on the grip, registered via setActivatorNodeRef: it stays a
 * real <button>, so the keyboard affordance is a genuine control rather than a
 * role="button" div wrapped around another button.
 */
export default function LeadCard({ lead, onOpen }) {
    const { t } = useTranslation();
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: lead.id, data: { type: 'lead', lead } });

    const grip = (
        <button
            type="button"
            ref={setActivatorNodeRef}
            className="cursor-grab touch-none rounded p-0.5 text-neutral-300 transition-colors hover:text-neutral-600 focus:text-neutral-600 dark:text-neutral-600 dark:hover:text-neutral-300"
            aria-label={t('leads.drag_handle', { name: lead.name || t('leads.unnamed') })}
            {...attributes}
            {...listeners}
        >
            <GripVertical className="h-4 w-4" />
        </button>
    );

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            className="touch-none"
            {...listeners}
        >
            <LeadCardView lead={lead} onOpen={onOpen} dragging={isDragging} grip={grip} />
        </div>
    );
}
