(function (root) {
    'use strict';
    function words(name) {
        return String(name).toLocaleLowerCase('en-GB').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(/\s+/)
            .filter(word => word.length > 2 && !['and', 'the'].includes(word))
            .map(word => word.endsWith('ies') ? `${word.slice(0, -3)}y` : word.replace(/s$/, ''));
    }
    function pairs(tags, limit = 20) {
        const buckets = new Map(); const seen = new Set(); const result = [];
        for (const tag of tags) {
            if (tag.protected) continue;
            const tokens = new Set(words(tag.name));
            for (const token of tokens) {
                for (const other of (buckets.get(token) || [])) {
                    const key = [Number(other.id), Number(tag.id)].sort((a, b) => a - b).join(':');
                    if (seen.has(key)) continue;
                    seen.add(key);
                    const previous = new Set(words(other.name));
                    const intersection = [...tokens].filter(word => previous.has(word)).length;
                    if (intersection / new Set([...tokens, ...previous]).size >= 0.5) {
                        result.push({ source: tag, target: other });
                        if (result.length >= limit) return result;
                    }
                }
                // Bound work even when many names share one word.
                const bucket = buckets.get(token) || [];
                if (bucket.length < 50) bucket.push(tag);
                buckets.set(token, bucket);
            }
        }
        return result;
    }
    root.TagCatalogueSuggestions = { pairs };
    if (typeof module !== 'undefined' && module.exports) module.exports = { pairs };
})(typeof window === 'undefined' ? globalThis : window);
