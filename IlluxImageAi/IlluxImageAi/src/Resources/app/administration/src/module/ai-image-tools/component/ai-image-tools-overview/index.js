import template from './ai-image-tools-overview.html.twig';
import './ai-image-tools-overview.scss';
import hoverMixin from '../../../../mixins/ai-hover.mixin';

const { Component, Mixin } = Shopware;

Component.register('ai-image-tools-overview', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        hoverMixin
    ],

    created() {
        this.refreshData();
    },

    computed: {
        // Store state
        storeState() {
            return Shopware.State.get('illuxAiAnalysis');
        },

        isLoading() {
            return this.storeState?.isLoading ?? false;
        },

        counts() {
            return Shopware.State.getters['illuxAiAnalysis/counts'] ?? {
                total: 0,
                pendingReview: 0,
                processing: 0,
                approved: 0,
                autoApproved: 0,
                rejected: 0,
                failed: 0,
                successful: 0
            };
        },

        statistics() {
            const counts = this.counts;
            const timeSaved = Shopware.State.getters['illuxAiAnalysis/timeSaved'] ?? {
                totalMinutes: 0,
                totalHours: 0,
                totalDays: 0,
                breakdown: { properties: 0, seo: 0, description: 0 }
            };
            const lastAnalysisDate = Shopware.State.getters['illuxAiAnalysis/lastAnalysisDate'] ?? null;
            const successRate = Shopware.State.getters['illuxAiAnalysis/successRate'] ?? 0;

            return {
                totalAnalyses: counts.total,
                pendingReview: counts.pendingReview,
                processing: counts.processing,
                approved: counts.approved,
                autoApproved: counts.autoApproved,
                rejected: counts.rejected,
                failed: counts.failed,
                successRate,
                lastAnalysisDate,
                timeSaved: {
                    minutes: timeSaved.totalMinutes,
                    hours: timeSaved.totalHours,
                    days: timeSaved.totalDays,
                    breakdown: timeSaved.breakdown
                }
            };
        },

        lastAnalysisFormatted() {
            if (!this.statistics.lastAnalysisDate) {
                return this.$tc('ai-image-tools.overview.neverRun');
            }
            return new Date(this.statistics.lastAnalysisDate).toLocaleString();
        },

        timeSavedFormatted() {
            const { days, hours, minutes } = this.statistics.timeSaved;

            if (minutes === 0) {
                return this.$tc('ai-image-tools.overview.noTimeSaved');
            }

            // If more than 1 day, show days and hours
            if (parseFloat(days) >= 1) {
                const wholeDays = Math.floor(parseFloat(days));
                const remainingHours = Math.floor((parseFloat(days) - wholeDays) * 24);
                return `${wholeDays} ${this.$tc('ai-image-tools.overview.days', wholeDays)} ${remainingHours} ${this.$tc('ai-image-tools.overview.hours', remainingHours)}`;
            }

            // If more than 1 hour, show hours and minutes
            if (parseFloat(hours) >= 1) {
                const wholeHours = Math.floor(parseFloat(hours));
                const remainingMinutes = Math.round((parseFloat(hours) - wholeHours) * 60);
                return `${wholeHours} ${this.$tc('ai-image-tools.overview.hours', wholeHours)} ${remainingMinutes} ${this.$tc('ai-image-tools.overview.minutes', remainingMinutes)}`;
            }

            // Just show minutes
            return `${minutes} ${this.$tc('ai-image-tools.overview.minutes', minutes)}`;
        }
    },

    methods: {
        async initializeStore() {
            if (!this.storeState) {
                console.warn('illuxAiAnalysis store not available');
                return;
            }
            try {
                await Shopware.State.dispatch('illuxAiAnalysis/initialize');
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.overview.errorLoadingStats')
                });
            }
        },

        async refreshData() {
            if (!this.storeState) {
                console.warn('illuxAiAnalysis store not available');
                return;
            }
            try {
                await Shopware.State.dispatch('illuxAiAnalysis/refresh');
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.overview.errorLoadingStats')
                });
            }
        },

        navigateToRun() {
            this.$emit('switch-tab', 'run');
        },

        navigateToList() {
            this.$emit('switch-tab', 'list');
        },

        showTimeSavedHover(evt) {
            const breakdown = this.statistics.timeSaved.breakdown;
            const html = `
                <div class="time-saved-breakdown">
                    <div class="time-saved-breakdown__title"> ${this.$tc('ai-image-tools.overview.timeSavedBreakdown.breakdown')}</div>
                    <div class="time-saved-breakdown__row">
                        <span>${this.$tc('ai-image-tools.overview.timeSavedBreakdown.properties')}:</span>
                        <strong>${breakdown.properties} min</strong>
                    </div>
                    <div class="time-saved-breakdown__row">
                        <span>${this.$tc('ai-image-tools.overview.timeSavedBreakdown.seo')}:</span>
                        <strong>${breakdown.seo} min</strong>
                    </div>
                    <div class="time-saved-breakdown__row">
                        <span>${this.$tc('ai-image-tools.overview.timeSavedBreakdown.descriptions')}:</span>
                        <strong>${breakdown.description} min</strong>
                    </div>
                </div>
            `;
            this.showHover(html, evt, 200);
        }
    }
});
