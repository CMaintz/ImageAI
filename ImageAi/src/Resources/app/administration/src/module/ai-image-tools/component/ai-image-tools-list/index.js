import template from './ai-image-tools-list.html.twig';
import './ai-image-tools-list.scss';
import languageMixin from '../../../../mixins/ai-analysis-language.mixin';
import listMixin from "../../../../mixins/ai-analysis-list.mixin";
import renderMixin from "../../../../mixins/ai-analysis-render.mixin";
import hoverMixin from "../../../../mixins/ai-hover.mixin";
import { createListColumns } from "../../../../services/ai-analysis-column-factory";

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.register('ai-image-tools-list', {
    template,

    inject: ['repositoryFactory', 'systemConfigApiService'],

    mixins: [
        Mixin.getByName('notification'),
        languageMixin,
        listMixin,
        renderMixin,
        hoverMixin
    ],

    data() {
        return {
            selectedStatus: null,
            total: 0,
            sortBy: 'createdAt',
            sortDirection: 'DESC',

            // Modal states for custom bulk actions
            showMarkAsFailedModal: false,
            markAsFailedSelection: []
        };
    },

    metaInfo() {
        return {
            title: this.$tc('ai-image-tools.analysisHistory.title')
        };
    },

    async created() {
        // Wait for mixin's language initialization to complete
        await this.initializeLanguages();
        await this.getList();
    },

    computed: {
        aiAnalysisRepository() {
            return this.repositoryFactory.create('ai_analysis_result');
        },

        columns() {
            return createListColumns(this.$tc);
        },

        entitySearchable() {
            return this.analysisTotal > 0;
        },

        statusOptions() {
            return [
                { value: null, label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.allStatuses') },
                { value: 'processing', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.processing') },
                { value: 'pending_review', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.pendingReview') },
                { value: 'approved', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.approved') },
                { value: 'auto_approved', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.autoApplied') },
                { value: 'rejected', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.rejected') },
                { value: 'failed', label: this.$tc('ai-image-tools.analysisHistory.statusPicker.filters.failed') },
            ];
        }
    },

    methods: {

        async getList() {
            this.isLoading = true;
            try {
                const builder = (criteria) => {
                    if (this.selectedStatus) {
                        criteria.addFilter(Shopware.Data.Criteria.equals('status', this.selectedStatus));
                    }
                    criteria.addAssociation('product.cover.media');
                    criteria.addAssociation('translations');
                    criteria.addSorting(Shopware.Data.Criteria.sort(this.sortBy || 'createdAt', this.sortDirection || 'DESC'));
                    return criteria;
                };
                await this.performListFetch(this.aiAnalysisRepository, builder);
            } catch (e) {
                this.createNotificationError({ message: this.$tc('ai-image-tools.analysisHistory.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        onStatusFilterChange(value) {
            this.selectedStatus = value;
            this.getList();
        },

        onLanguagePickerChanged(value) {
            this.selectedLanguage = value;
        },

        getStatusVariant(status) {
            switch (status) {
                case 'approved':
                case 'auto_approved':
                    return 'success';
                case 'rejected':
                case 'failed':
                    return 'danger';
                case 'pending_review':
                    return 'warning';
                case 'processing':
                    return 'info';
                default:
                    return 'neutral';
            }
        },

        getStatusLabel(status) {
            const option = this.statusOptions.find(opt => opt.value === status);
            return option ? option.label : status;
        },

        onStatusMouseEnter(item, evt) {
            if (item.status === 'failed' && item.errorMessage) {
                this.onCellMouseEnterFullText(item.errorMessage, evt, 200);
            }
        },

        /**
         * Mark a stuck processing item as failed.
         */
        async markAsFailed(item) {
            if (item.status !== 'processing') {
                return;
            }

            try {
                item.status = 'failed';
                item.errorMessage = 'Manually marked as failed by administrator';
                await this.aiAnalysisRepository.save(item, Shopware.Context.api);

                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.analysisHistory.markAsFailed.success')
                });

                await this.getList();
            } catch (e) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.analysisHistory.markAsFailed.error')
                });
            }
        },

        /**
         * Check if the item can be marked as failed (only processing items).
         */
        canMarkAsFailed(item) {
            return item.status === 'processing';
        },

        /**
         * Get current selection from grid ref.
         */
        getGridSelection() {
            const grid = this.$refs.aiImageToolsListGrid;
            if (!grid?.selection) {
                return [];
            }
            return Object.values(grid.selection);
        },

        /**
         * Open mark as failed confirmation modal.
         */
        openMarkAsFailedModal() {
            const items = this.getGridSelection();
            const processingItems = items.filter(item => item?.status === 'processing');

            if (processingItems.length === 0) {
                this.createNotificationWarning({
                    message: this.$tc('ai-image-tools.analysisHistory.bulkActions.noProcessingSelected')
                });
                return;
            }

            this.markAsFailedSelection = processingItems;
            this.showMarkAsFailedModal = true;
        },

        closeMarkAsFailedModal() {
            this.showMarkAsFailedModal = false;
        },

        /**
         * Confirm and execute bulk mark as failed.
         */
        async confirmMarkAsFailed() {
            if (this.isLoading) {
                return;
            }

            const count = this.markAsFailedSelection.length;

            this.isLoading = true;

            try {
                for (const item of this.markAsFailedSelection) {
                    item.status = 'failed';
                    item.errorMessage = 'Manually marked as failed by administrator';
                }

                await this.aiAnalysisRepository.saveAll(this.markAsFailedSelection, Shopware.Context.api);

                if (this.$refs.aiImageToolsListGrid) {
                    this.$refs.aiImageToolsListGrid.selectAll(false);
                }

                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.analysisHistory.bulkActions.markAsFailedSuccess', count, { count })
                });
            } catch (e) {
                console.error('[AI Analysis] Mark as failed error:', e);
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.analysisHistory.bulkActions.markAsFailedError')
                });
            } finally {
                this.markAsFailedSelection = [];
                this.closeMarkAsFailedModal();
                this.isLoading = false;
                await this.getList();
            }
        },

    }
});
