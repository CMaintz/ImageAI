// Shared data helpers for formatting and small transforms
export const aiDataHelpers = {
        truncate(text, limit = 60)
        {
            if (!text && text !== '') {
                return '-';
            }
            const s = String(text);
            return s.length > limit ? s.slice(0, limit) + '…' : s;
    },

        truncateMultiline(text, maxLines = 2)
        {
            if (!text && text !== '') {
                return '-';
            }
            const s = String(text);
            const lines = s.split(/\r?\n/).filter(Boolean);
            if (lines.length <= maxLines) {
                // also collapse long single lines
                if (s.length > maxLines * 100) {
                    return s.slice(0, maxLines * 100) + '…';
                }
                return s;
            }
            return lines.slice(0, maxLines).join(' ') + '…';
    },

        limitOptions(options = [], limit = 3)
        {
            return Array.isArray(options) ? options.slice(0, limit) : [];
    },

        showComma(idx, allOptions = [], limit = 3)
        {
            return idx < Math.min(allOptions.length, limit) - 1;
    },

        formatDate(dateStr)
        {
            if (!dateStr) {
                return '-';
            }
            try {
                return new Date(dateStr).toLocaleString();
            } catch (e) {
                return dateStr;
            }
    },

        getTranslatedField(item = {}, field)
        {
            if (!item) {
                return '';
            }
            // common shape: item.translations may be array keyed by language; fallback to field on root
            if (item.translations && Array.isArray(item.translations) && item.translations.length) {
                const first = item.translations[0];
                if (first && first[field]) {
                    return first[field];
                }
            }
            return item[field] ?? '';
    },

        /**
         * Returnerer en kort concatenated preview af en tekst eller et array.
         * - Hvis input er array: join med ', ' og trim til limit chars.
         * - Hvis input er string: trim til limit chars.
         */
        concatPreview(input, limit = 80)
        {
            if (input === null || input === undefined) {
                return '-';
            }
            let s = '';

            if (Array.isArray(input)) {
                s = input.join(', ');
            } else {
                s = String(input);
            }

            if (s.length <= limit) {
                return s;
            }
            return s.slice(0, limit).trim() + '…';
    },

    escapeHtml(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

        formatPropertyKey(key) {
            return key ? key.replace(/_/g, ' ') : '';
    },

        propertiesToHtml(propObj = {})
        {
            if (!propObj || typeof propObj !== 'object') {
                return '';
            }

            let html = '<div class="ap-hover">';
            for (const [groupName, value] of Object.entries(propObj)) {
                const options = Array.isArray(value.options) ? value.options : (value ? [value] : []);
                const displayName = this.formatPropertyKey(groupName);
                html += `<div class="ap-hover-group"><div class="ap-hover-group__title">${this.escapeHtml(displayName)}:</div><div class="ap-hover-group__options">`;
                options.forEach(opt => {
                    html += `<span class="ap-pill">${this.escapeHtml(opt)}</span>`;
                });
                html += '</div></div>';
            }
            html += '</div>';
            return html;
    }
    };
