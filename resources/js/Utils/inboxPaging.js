import axios from 'axios';

/** Items of `extra` whose ids are not in `base`, appended after `base` (base order kept). */
export function mergeById(base = [], extra = []) {
    const seen = new Set(base.map((item) => item.id));
    return [...base, ...extra.filter((item) => !seen.has(item.id))];
}

/**
 * Merge an older page into the messages already on screen. Oldest first by sent
 * time, then id. When an id is in both, the one in `current` wins (it is fresher).
 */
export function mergeMessages(older = [], current = []) {
    const byId = new Map();
    for (const message of [...older, ...current]) {
        byId.set(message.id, message);
    }

    const at = (m) => (m.sent_at ? Date.parse(m.sent_at) : 0);
    return [...byId.values()].sort((a, b) => {
        const byTime = at(a) - at(b);
        return byTime !== 0 ? byTime : a.id - b.id;
    });
}

/** GET a JSON page (the list's next page, or older messages). Rejects on HTTP errors. */
export function fetchPage(url, params) {
    return axios.get(url, { params, headers: { Accept: 'application/json' } }).then((response) => response.data);
}
