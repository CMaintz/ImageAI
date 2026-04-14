import template from './ai-image-tools-approval.html.twig';
import './ai-image-tools-approval.scss';
import languageMixin from '../../../../mixins/ai-analysis-language.mixin';
import listMixin from "../../../../mixins/ai-analysis-list.mixin";
import renderMixin from "../../../../mixins/ai-analysis-render.mixin";
import hoverMixin from "../../../../mixins/ai-hover.mixin";
import { createApprovalColumns } from "../../../../services/ai-analysis-column-factory";

const {Component, Mixin, Data: {Criteria}} = Shopware;

Component.register('ai-image-tools-approval', {
    template,

    inject: ['repositoryFactory', 'imageAiAnalysisApiService', 'sceneGenerationApiService', 'systemConfigApiService'],

    mixins: [
        Mixin.getByName('notification'),
        languageMixin,
        listMixin,
        renderMixin,
        hoverMixin
    ],

    data() {
        return {
            showApproveModal: false,
            showRejectModal: false,
            approveSelection: [],
            rejectSelection: [],
            displayMode: 'analysis',

            selectedSceneImages: [],
            showSceneApproveModal: false,
            showSceneRejectModal: false,

            lightboxOpen: false,
            lightboxImage: null,
            lightboxSceneType: null
        }
    },

    async created() {
        // Wait for mixin's language initialization to complete
        await this.initializeLanguages();
        await this.getList();
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        aiAnalysisRepository() {
            return this.repositoryFactory.create('ai_analysis_result');
        },

        analysisColumns() {
            return createApprovalColumns(this.$tc);
        },
        currentTotal() {
            return this.displayMode === 'analysis' ? this.analysisTotal : this.environmentImageTotal
        },
        currentTitle() {
            return this.displayMode === 'analysis' ? this.$tc('ai-image-tools.approval.analysisTitle') : this.$tc('ai-image-tools.approval.imagesTitle')
        },
        entitySearchable() {
            return this.currentTotal > 0;
        },

        allSceneImagesSelected() {
            const total = this.environmentImageItems.length;
            const selected = this.selectedSceneImages.length;
            return total > 0 && selected === total;
        },

        someSceneImagesSelected() {
            const total = this.environmentImageItems.length;
            const selected = this.selectedSceneImages.length;
            return selected > 0 && selected < total;
        },

        hasSceneSelection() {
            return this.selectedSceneImages.length > 0;
        }
    },

    methods: {
        async getList() {
            this.isLoading = true;
            try {
                await this.performListFetch(this.aiAnalysisRepository, (criteria) => {
                    criteria.addFilter(Shopware.Data.Criteria.equals('status', 'pending_review'));
                    criteria.addAssociation('product.cover.media');
                    criteria.addAssociation('translations');
                    return criteria;
                });
            } catch (e) {
                this.createNotificationError({ message: this.$tc('ai-image-tools.approval.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        openApproveModal() {
            const selectionProxy = this.$refs.aiImageToolsApprovalGrid.selection;
            this.approveSelection = Object.values(selectionProxy);
            this.showApproveModal = true;
        },

        closeApproveModal() {
            this.showApproveModal = false;
        },

        async confirmApprove() {
            this.isLoading = true;

            try {
                const ids = this.approveSelection.map(item => item.id);
                const response = await this.imageAiAnalysisApiService.approveAnalysisResults(ids);

                if (this.$refs.aiImageToolsApprovalGrid) {
                    this.$refs.aiImageToolsApprovalGrid.selectAll(false);
                }

                this.approveSelection = [];
                this.closeApproveModal();

                if (response.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.approval.approveSuccess', response.successCount, {
                            count: response.successCount
                        })
                    });

                    if (response.failureCount > 0) {
                        this.createNotificationWarning({
                            message: this.$tc('ai-image-tools.approval.approvePartialError', response.failureCount, {
                                count: response.failureCount,
                                errors: response.errors.join(', ')
                            })
                        });
                    }
                } else {
                    this.createNotificationError({
                        message: this.$tc('ai-image-tools.approval.approveError')
                    });
                }

                await this.getList();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.approval.approveError')
                });
            } finally {
                this.isLoading = false;
            }
        },

        openRejectModal() {
            const selectionProxy = this.$refs.aiImageToolsApprovalGrid.selection;
            this.rejectSelection = Object.values(selectionProxy);
            this.showRejectModal = true;
        },

        closeRejectModal() {
            this.showRejectModal = false;
        },

        async confirmReject() {
            this.isLoading = true;

            try {
                const ids = this.rejectSelection.map(item => item.id);
                const response = await this.imageAiAnalysisApiService.rejectAnalysisResults(ids);

                if (this.$refs.aiImageToolsApprovalGrid) {
                    this.$refs.aiImageToolsApprovalGrid.selectAll(false);
                }

                this.rejectSelection = [];

                this.closeRejectModal();

                if (response.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.approval.rejectSuccess', response.rejectedCount, {
                            count: response.rejectedCount
                        })
                    });
                } else {
                    this.createNotificationError({
                        message: this.$tc('ai-image-tools.approval.rejectError')
                    });
                }

                await this.getList();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.approval.rejectError')
                });
            } finally {
                this.isLoading = false;
            }
        },

        onLanguagePickerChanged(value) {
            this.selectedLanguage = value;
        },

        async loadPendingSceneImages() {
            this.isLoading = true;
            try {
                const response = await this.sceneGenerationApiService.getPendingImages();

                if (response.success) {
                    this.environmentImageItems = response.images || [];
                    this.environmentImageTotal = this.environmentImageItems.length;
                } else {
                    this.createNotificationError({
                        message: 'Failed to load pending scene images'
                    });
                }
            } catch (error) {
                console.error('Error loading pending scene images:', error);
                this.createNotificationError({
                    message: 'Error loading pending scene images'
                });
            } finally {
                this.isLoading = false;
            }
        },

        async approveSceneImage(imageId) {
            this.isLoading = true;
            try {
                const response = await this.sceneGenerationApiService.approveImage(imageId, null);

                if (response.success) {
                    this.createNotificationSuccess({
                        message: 'Scene image approved successfully'
                    });
                    await this.loadPendingSceneImages();
                } else {
                    this.createNotificationError({
                        message: response.error || 'Failed to approve scene image'
                    });
                }
            } catch (error) {
                console.error('Error approving scene image:', error);
                this.createNotificationError({
                    message: 'Error approving scene image'
                });
            } finally {
                this.isLoading = false;
            }
        },

        async rejectSceneImage(imageId) {
            this.isLoading = true;
            try {
                const response = await this.sceneGenerationApiService.rejectImage(imageId);

                if (response.success) {
                    this.createNotificationSuccess({
                        message: 'Scene image rejected'
                    });

                    await this.loadPendingSceneImages();
                } else {
                    this.createNotificationError({
                        message: 'Failed to reject scene image'
                    });
                }
            } catch (error) {
                console.error('Error rejecting scene image:', error);
                this.createNotificationError({
                    message: 'Error rejecting scene image'
                });
            } finally {
                this.isLoading = false;
            }
        },

        // Scene batch selection methods
        toggleSceneImageSelection(imageId, checked) {
            if (checked) {
                if (!this.selectedSceneImages.includes(imageId)) {
                    this.selectedSceneImages.push(imageId);
                }
            } else {
                const index = this.selectedSceneImages.indexOf(imageId);
                if (index !== -1) {
                    this.selectedSceneImages.splice(index, 1);
                }
            }
        },

        toggleAllSceneImages(checked) {
            if (checked) {
                this.selectedSceneImages = this.environmentImageItems.map(img => img.id);
            } else {
                this.selectedSceneImages = [];
            }
        },

        isSceneImageSelected(imageId) {
            return this.selectedSceneImages.includes(imageId);
        },

        openSceneApproveModal() {
            if (this.selectedSceneImages.length === 0) {
                return;
            }
            this.showSceneApproveModal = true;
        },

        closeSceneApproveModal() {
            this.showSceneApproveModal = false;
        },

        async confirmSceneBatchApprove() {
            this.isLoading = true;
            try {
                const approvals = this.selectedSceneImages.map(imageId => ({
                    pendingImageId: imageId,
                    targetFolderId: null
                }));

                const response = await this.sceneGenerationApiService.batchApproveImages(approvals);

                this.closeSceneApproveModal();
                this.selectedSceneImages = [];

                if (response.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.approval.sceneBatchApproveSuccess', response.successCount, {
                            count: response.successCount
                        })
                    });

                    if (response.failureCount > 0) {
                        this.createNotificationWarning({
                            message: this.$tc('ai-image-tools.approval.sceneBatchApprovePartialError', response.failureCount, {
                                count: response.failureCount
                            })
                        });
                    }
                } else {
                    this.createNotificationError({
                        message: this.$tc('ai-image-tools.approval.sceneBatchApproveError')
                    });
                }

                await this.loadPendingSceneImages();
            } catch (error) {
                console.error('Error batch approving scene images:', error);
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.approval.sceneBatchApproveError')
                });
            } finally {
                this.isLoading = false;
            }
        },

        openSceneRejectModal() {
            if (this.selectedSceneImages.length === 0) {
                return;
            }
            this.showSceneRejectModal = true;
        },

        closeSceneRejectModal() {
            this.showSceneRejectModal = false;
        },

        async confirmSceneBatchReject() {
            this.isLoading = true;
            try {
                const response = await this.sceneGenerationApiService.batchRejectImages(this.selectedSceneImages);

                this.closeSceneRejectModal();
                this.selectedSceneImages = [];

                if (response.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.approval.sceneBatchRejectSuccess', response.rejectedCount, {
                            count: response.rejectedCount
                        })
                    });
                } else {
                    this.createNotificationError({
                        message: this.$tc('ai-image-tools.approval.sceneBatchRejectError')
                    });
                }

                await this.loadPendingSceneImages();
            } catch (error) {
                console.error('Error batch rejecting scene images:', error);
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.approval.sceneBatchRejectError')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async setMode(mode) {
            this.displayMode = mode;

            if (mode === 'images') {
                await this.loadPendingSceneImages();
            } else {
                await this.getList();
            }
        },

        formatPropertyKey(key) {
            return key.replace(/_/g, ' ');
        },

        // Lightbox methods
        openLightbox(image) {
            this.lightboxImage = image.imageData;
            this.lightboxSceneType = image.sceneType;
            this.lightboxOpen = true;
        },

        closeLightbox() {
            this.lightboxOpen = false;
            this.lightboxImage = null;
            this.lightboxSceneType = null;
        }
    }
});

