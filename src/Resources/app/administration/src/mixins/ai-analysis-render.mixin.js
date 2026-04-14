// Render helpers for templates; thin wrapper around aiDataHelpers
import { aiDataHelpers } from '../services/ai-analysis-data-helpers';

export default {
    methods: {
        truncate(text, limit = 60) { return aiDataHelpers.truncate(text, limit); },
        truncateMultiline(text, lines = 2) { return aiDataHelpers.truncateMultiline(text, lines); },
        limitOptions(options, limit = 3) { return aiDataHelpers.limitOptions(options, limit); },
        showComma(idx, options, limit = 3) { return aiDataHelpers.showComma(idx, options, limit); },
        propertiesToHtml(propObj) { return aiDataHelpers.propertiesToHtml(propObj); },
        concatPreview(input, limit) { return aiDataHelpers.concatPreview(input, limit); },
        escapeHtml(str) { return aiDataHelpers.escapeHtml(str); },
        formatPropertyKey(key) { return aiDataHelpers.formatPropertyKey(key); },

        /**
         * Get confidence level based on score
         * @param {number} score - Confidence score (0.0 to 1.0)
         * @returns {string} 'high' | 'medium' | 'low'
         */
        getConfidenceLevel(score) {
            if (score >= 0.8) {
                return 'high';
            }
            if (score >= 0.6) {
                return 'medium';
            }
            return 'low';
        },

        /**
         * Format confidence score as percentage
         * @param {number} score - Confidence score (0.0 to 1.0)
         * @returns {string} Formatted percentage
         */
        formatConfidenceScore(score) {
            if (score === null || score === undefined) {
                return '-';
            }
            return `${Math.round(score * 100)}%`;
        },

        /**
         * Check if item has confidence warnings
         * @param {object} item - Analysis result item
         * @returns {boolean}
         */
        hasConfidenceWarnings(item) {
            return item?.confidenceWarnings && Array.isArray(item.confidenceWarnings) && item.confidenceWarnings.length > 0;
        },

        /**
         * Build HTML for confidence warnings hover
         * @param {string[]} warnings - Array of warning messages
         * @returns {string} HTML content
         */
        confidenceWarningsToHtml(warnings) {
            if (!warnings || warnings.length === 0) {
                return '';
            }

            const title = this.$tc ? this.$tc('ai-image-tools.gridShared.confidenceWarnings.title') : 'Quality Warnings';
            const items = warnings.map(w => `<li class="confidence-warnings__item">${this.escapeHtml(w)}</li>`).join('');

            return `<div class="confidence-warnings">
                <div class="confidence-warnings__title">${this.escapeHtml(title)}</div>
                <ul class="confidence-warnings__list">${items}</ul>
            </div>`;
        }
    }
};
