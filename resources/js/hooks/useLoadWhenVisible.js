import { useEffect, useRef } from 'react';

/**
 * Calls `onVisible` when the element behind `ref` scrolls into view inside its
 * scroll container (its parent). `enabled` switches the watch off (no more pages,
 * or a load already running).
 */
export default function useLoadWhenVisible(ref, enabled, onVisible) {
    const callback = useRef(onVisible);

    useEffect(() => {
        callback.current = onVisible;
    });

    useEffect(() => {
        const el = ref.current;
        if (!enabled || !el || !('IntersectionObserver' in window)) return undefined;

        const observer = new window.IntersectionObserver(
            (entries) => { if (entries[0]?.isIntersecting) callback.current(); },
            { root: el.parentElement, rootMargin: '200px 0px' },
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, [ref, enabled]);
}
