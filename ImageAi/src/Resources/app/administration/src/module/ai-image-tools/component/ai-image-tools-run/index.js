import template from './ai-image-tools-run.html.twig';
import './ai-image-tools-run.scss';

const { Component, Mixin } = Shopware;

Component.register('ai-image-tools-run', {
    template,

    inject: ['illuxAiAnalysisApiService', 'systemConfigApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            showConfirmModal: false,
            analysisRunning: false,
            productCount: 0,
            batchJobId: null,
            stopPolling: null,
            progress: {
                current: 0,
                total: 0,
                percentage: 0,
                successCount: 0,
                failureCount: 0,
                processingCount: 0
            },
            filters: {
                includeAnalyzed: false,
                includeDescription: false,
                includeSeo: false,
            }
        };
    },

    async created() {
        await this.loadConfigDefaults();
        await this.checkForActiveJob();
    },

    beforeDestroy() {
        // Clean up polling on component destroy
        if (this.stopPolling) {
            this.stopPolling();
            this.stopPolling = null;
        }
    },

    computed: {
        canStartAnalysis() {
            return !this.isLoading && !this.analysisRunning;
        }
    },

    methods: {
        async loadConfigDefaults() {
            try {
                const config = await this.systemConfigApiService.getValues('IlluxImageAi.config');

                // Set filter defaults from config
                this.filters.includeDescription = config['IlluxImageAi.config.includeProductDescription'] ?? true;
                this.filters.includeSeo = config['IlluxImageAi.config.includeSeoAnalysis'] ?? true;
            } catch (error) {
                console.error('Failed to load config defaults:', error);
            }
        },

        async checkForActiveJob() {
            try {
                const response = await this.illuxAiAnalysisApiService.getActiveJob();
                const data = response?.data || response;

                if (data?.hasActiveJob && data.job) {
                    // Resume tracking the active job
                    this.batchJobId = data.job.id;
                    this.analysisRunning = true;
                    this.isLoading = true;
                    this.productCount = data.job.totalItems || 0;

                    // Set current progress from job
                    this.progress = {
                        current: data.job.processedItems || 0,
                        total: data.job.totalItems || 0,
                        percentage: data.job.percentage || 0,
                        successCount: data.job.successCount || 0,
                        failureCount: data.job.failureCount || 0
                    };

                    // Resume polling
                    this.startBatchJobPolling();
                }
            } catch (error) {
                console.error('Failed to check for active job:', error);
            }
        },

        openConfirmModal() {
            this.showConfirmModal = true;
        },

        closeConfirmModal() {
            this.showConfirmModal = false;
        },

        async startAnalysis() {
            this.closeConfirmModal();
            this.analysisRunning = true;
            this.isLoading = true;

            try {
                const response = await this.illuxAiAnalysisApiService.analyzeAllProducts(
                    this.filters
                );

                const data = response?.data || response;

                if (data?.success) {
                    this.productCount = data.totalProducts || 0;

                    // Initialize progress with total from response
                    this.progress = {
                        current: 0,
                        total: this.productCount,
                        percentage: 0,
                        successCount: 0,
                        failureCount: 0
                    };

                    if (this.productCount === 0) {
                        this.createNotificationInfo({
                            message: this.$tc('ai-image-tools.run.noProductsFound')
                        });
                        this.analysisRunning = false;
                        this.isLoading = false;
                        return;
                    }

                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.run.analysisStarted', 0, {
                            count: this.productCount
                        })
                    });

                    // Start polling for batch job progress
                    if (data.batchJobId) {
                        this.batchJobId = data.batchJobId;
                        this.startBatchJobPolling();
                    } else {
                        this.analysisRunning = false;
                        this.isLoading = false;
                    }
                } else {
                    throw new Error(data?.message || 'Analysis failed');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('ai-image-tools.run.startError')
                });
                this.analysisRunning = false;
                this.isLoading = false;
                console.error(error);
            }
        },

        startBatchJobPolling() {
            if (!this.batchJobId) {
                return;
            }

            this.stopPolling = this.illuxAiAnalysisApiService.pollBatchJobStatus(
                this.batchJobId,
                {
                    onProgress: (job) => {
                        this.progress = {
                            current: job.processedItems || 0,
                            total: job.totalItems || this.productCount,
                            percentage: job.percentage || 0,
                            successCount: job.successCount || 0,
                            failureCount: job.failureCount || 0,
                            processingCount: job.processingCount || 0
                        };
                    },
                    onComplete: (job) => {
                        this.progress = {
                            current: job.processedItems || job.totalItems,
                            total: job.totalItems,
                            percentage: 100,
                            successCount: job.successCount || 0,
                            failureCount: job.failureCount || 0,
                            processingCount: job.processingCount || 0
                        };

                        this.analysisRunning = false;
                        this.isLoading = false;
                        this.batchJobId = null;
                        this.stopPolling = null;

                        this.createNotificationSuccess({
                            message: this.$tc('ai-image-tools.run.analysisComplete', 0, {
                                success: this.progress.successCount,
                                failed: this.progress.failureCount
                            })
                        });

                        // Emit event to switch to results tab
                        this.$emit('analysis-complete');
                    },
                    onError: (errorMessage) => {
                        this.analysisRunning = false;
                        this.isLoading = false;
                        this.batchJobId = null;
                        this.stopPolling = null;

                        this.createNotificationError({
                            message: errorMessage || this.$tc('ai-image-tools.run.progressError')
                        });
                    },
                    interval: 3000 // Poll every 3 seconds
                }
            );
        },

        async stopAnalysis() {
            if (!this.batchJobId) {
                this.createNotificationError({
                    message: 'No active analysis to stop'
                });
                return;
            }

            try {
                // Stop the polling if active
                if (this.stopPolling) {
                    this.stopPolling();
                    this.stopPolling = null;
                }

                await this.illuxAiAnalysisApiService.stopAnalysis(this.batchJobId);

                this.analysisRunning = false;
                this.isLoading = false;
                this.batchJobId = null;

                this.createNotificationInfo({
                    message: this.$tc('ai-image-tools.run.analysisStopped')
                });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.run.stopError')
                });
            }
        }
    }
});
