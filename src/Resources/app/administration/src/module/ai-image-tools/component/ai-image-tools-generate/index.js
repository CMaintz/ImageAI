import template from './ai-image-tools-generate.html.twig';
import './ai-image-tools-generate.scss';
import '../ai-image-tools-config-modal';

const { Component, Mixin } = Shopware;

const STORAGE_KEY = 'imageAi.sceneGeneration.lastUsed';

Component.register('ai-image-tools-generate', {
    template,

    inject: ['systemConfigApiService', 'sceneGenerationApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            configLoading: true,
            optionsLoading: true,
            apiKey: null,

            // Scene generation options from database
            sceneTypeOptions: [],
            cameraLensOptions: [],
            perspectiveOptions: [],
            cameraAngleOptions: [],
            interiorStyleOptions: [],
            lightingOptions: [],
            styleOptions: [],
            stylingOptions: [],
            aspectRatioOptions: [],
            moodOptions: [],
            colorPaletteOptions: [],
            compositionOptions: [],

            // User selections
            selectedSceneTypes: [],
            selectedCameraLens: null,
            selectedPerspective: null,
            selectedCameraAngle: null,
            selectedInteriorStyle: null,
            selectedLighting: null,
            selectedStyle: null,
            selectedStyling: null,
            selectedAspectRatio: null,
            selectedMood: null,
            selectedColorPalette: null,
            selectedComposition: null,
            additionalDetails: '',

            // Results
            generatedImages: [],

            // Async generation state
            isGenerating: false,
            batchJobId: null,
            stopPolling: null,
            progress: {
                current: 0,
                total: 0,
                percentage: 0,
                successCount: 0,
                failureCount: 0
            },

            // Prompt preview
            promptPreview: null,
            isLoadingPreview: false,
            showPromptPreview: false,
            previewDebounceTimer: null,

            // Config modal
            showConfigModal: false
        };
    },

    created() {
        this.loadConfig();
        this.loadOptions();
    },

    beforeUnmount() {
        if (this.previewDebounceTimer) {
            clearTimeout(this.previewDebounceTimer);
        }
        // Clean up polling on component destroy
        if (this.stopPolling) {
            this.stopPolling();
            this.stopPolling = null;
        }
    },

    watch: {
        // Save to localStorage and update preview whenever selections change
        selectedSceneTypes: { handler: 'onSelectionChange', deep: true },
        selectedCameraLens: 'onSelectionChange',
        selectedPerspective: 'onSelectionChange',
        selectedCameraAngle: 'onSelectionChange',
        selectedInteriorStyle: 'onSelectionChange',
        selectedLighting: 'onSelectionChange',
        selectedStyle: 'onSelectionChange',
        selectedStyling: 'onSelectionChange',
        selectedAspectRatio: 'onSelectionChange',
        selectedMood: 'onSelectionChange',
        selectedColorPalette: 'onSelectionChange',
        selectedComposition: 'onSelectionChange',
        additionalDetails: 'onSelectionChange'
    },

    computed: {
        canGenerate() {
            return !this.isLoading &&
                   !this.isGenerating &&
                   this.apiKey &&
                   this.selectedSceneTypes.length > 0 &&
                   this.selectedCameraLens &&
                   this.selectedPerspective &&
                   this.selectedCameraAngle &&
                   this.selectedInteriorStyle &&
                   this.selectedLighting &&
                   this.selectedStyle &&
                   this.selectedStyling &&
                   this.selectedAspectRatio &&
                   this.selectedMood &&
                   this.selectedComposition;
        },

        sceneTypeSelectOptions() {
            return this.sceneTypeOptions.map(opt => ({
                value: opt.label,
                label: opt.label
            }));
        },

        cameraLensSelectOptions() {
            return this.cameraLensOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        perspectiveSelectOptions() {
            return this.perspectiveOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        cameraAngleSelectOptions() {
            return this.cameraAngleOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        interiorStyleSelectOptions() {
            return this.interiorStyleOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        lightingSelectOptions() {
            return this.lightingOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        styleSelectOptions() {
            return this.styleOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        stylingSelectOptions() {
            return this.stylingOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        aspectRatioSelectOptions() {
            return this.aspectRatioOptions.map(opt => ({
                value: opt.value,
                label: opt.label
            }));
        },

        moodSelectOptions() {
            return this.moodOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        colorPaletteSelectOptions() {
            return this.colorPaletteOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

        compositionSelectOptions() {
            return this.compositionOptions.map(opt => ({
                value: opt.description,
                label: opt.label
            }));
        },

    },

    methods: {
        async loadConfig() {
            this.configLoading = true;
            try {
                const config = await this.systemConfigApiService.getValues('CMaintzImageAi.config');
                this.apiKey = config['CMaintzImageAi.config.apiKey'];

                if (!this.apiKey) {
                    this.createNotificationWarning({
                        message: 'Please configure your Gemini API key in the Configuration tab first'
                    });
                }
            } catch (error) {
                console.error('Error loading config:', error);
                this.createNotificationError({
                    message: 'Error loading configuration'
                });
            } finally {
                this.configLoading = false;
            }
        },

        async loadOptions() {
            this.optionsLoading = true;
            try {
                // Load all generation options from database config (including scene types)
                const optionsResponse = await this.sceneGenerationApiService.getGenerationOptions();
                if (optionsResponse.success) {
                    const opts = optionsResponse.options;
                    this.sceneTypeOptions = opts.sceneTypeOptions || [];
                    this.cameraLensOptions = opts.cameraLensOptions || [];
                    this.perspectiveOptions = opts.perspectiveOptions || [];
                    this.cameraAngleOptions = opts.cameraAngleOptions || [];
                    this.interiorStyleOptions = opts.interiorStyleOptions || [];
                    this.lightingOptions = opts.lightingOptions || [];
                    this.styleOptions = opts.styleOptions || [];
                    this.stylingOptions = opts.stylingOptions || [];
                    this.aspectRatioOptions = opts.aspectRatioOptions || [];
                    this.moodOptions = opts.moodOptions || [];
                    this.colorPaletteOptions = opts.colorPaletteOptions || [];
                    this.compositionOptions = opts.compositionOptions || [];
                }

                // Apply saved selections or defaults after options are loaded
                this.applySelectionsOrDefaults();
            } catch (error) {
                console.error('Error loading options:', error);
                this.createNotificationError({
                    message: 'Error loading generation options'
                });
            } finally {
                this.optionsLoading = false;
            }
        },

        /**
         * Load saved selections from localStorage
         */
        loadSelectionsFromStorage() {
            try {
                const saved = localStorage.getItem(STORAGE_KEY);
                return saved ? JSON.parse(saved) : null;
            } catch (e) {
                console.warn('Failed to load saved selections:', e);
                return null;
            }
        },

        /**
         * Save current selections to localStorage
         */
        saveSelectionsToStorage() {
            // Don't save while still loading options
            if (this.optionsLoading) {
                return;
            }

            try {
                const selections = {
                    selectedSceneTypes: this.selectedSceneTypes,
                    selectedCameraLens: this.selectedCameraLens,
                    selectedPerspective: this.selectedPerspective,
                    selectedCameraAngle: this.selectedCameraAngle,
                    selectedInteriorStyle: this.selectedInteriorStyle,
                    selectedLighting: this.selectedLighting,
                    selectedStyle: this.selectedStyle,
                    selectedStyling: this.selectedStyling,
                    selectedAspectRatio: this.selectedAspectRatio,
                    selectedMood: this.selectedMood,
                    selectedColorPalette: this.selectedColorPalette,
                    selectedComposition: this.selectedComposition,
                    additionalDetails: this.additionalDetails
                };
                localStorage.setItem(STORAGE_KEY, JSON.stringify(selections));
            } catch (e) {
                console.warn('Failed to save selections:', e);
            }
        },

        /**
         * Apply saved selections from localStorage, or default to first option
         */
        applySelectionsOrDefaults() {
            const saved = this.loadSelectionsFromStorage();

            // Helper to get saved value or first option's value
            const getValueOrFirst = (savedValue, options, valueKey = 'description') => {
                if (savedValue && options.some(opt => opt[valueKey] === savedValue)) {
                    return savedValue;
                }
                return options.length > 0 ? options[0][valueKey] : null;
            };

            // Apply saved or defaults for each field
            this.selectedCameraLens = getValueOrFirst(saved?.selectedCameraLens, this.cameraLensOptions);
            this.selectedPerspective = getValueOrFirst(saved?.selectedPerspective, this.perspectiveOptions);
            this.selectedCameraAngle = getValueOrFirst(saved?.selectedCameraAngle, this.cameraAngleOptions);
            this.selectedInteriorStyle = getValueOrFirst(saved?.selectedInteriorStyle, this.interiorStyleOptions);
            this.selectedLighting = getValueOrFirst(saved?.selectedLighting, this.lightingOptions);
            this.selectedStyle = getValueOrFirst(saved?.selectedStyle, this.styleOptions);
            this.selectedStyling = getValueOrFirst(saved?.selectedStyling, this.stylingOptions);
            this.selectedAspectRatio = getValueOrFirst(saved?.selectedAspectRatio, this.aspectRatioOptions, 'value');
            this.selectedMood = getValueOrFirst(saved?.selectedMood, this.moodOptions);
            this.selectedColorPalette = getValueOrFirst(saved?.selectedColorPalette, this.colorPaletteOptions);
            this.selectedComposition = getValueOrFirst(saved?.selectedComposition, this.compositionOptions);

            // Scene types - restore saved if valid, otherwise leave empty (user must choose)
            if (saved?.selectedSceneTypes?.length > 0) {
                const validSceneTypes = saved.selectedSceneTypes.filter(
                    name => this.sceneTypeOptions.some(opt => opt.label === name)
                );
                this.selectedSceneTypes = validSceneTypes;
            }

            // Additional details - restore if saved
            if (saved?.additionalDetails) {
                this.additionalDetails = saved.additionalDetails;
            }
        },

        async generateScenes() {
            if (!this.canGenerate) {
                this.createNotificationWarning({
                    message: 'Please fill all required fields'
                });
                return;
            }

            this.isLoading = true;
            this.isGenerating = true;

            try {
                const config = this.buildGenerationConfig();

                const response = await this.sceneGenerationApiService.generateSceneImages(config);

                if (response.success && response.batchJobId) {
                    this.batchJobId = response.batchJobId;

                    // Initialize progress
                    this.progress = {
                        current: 0,
                        total: response.totalItems || this.selectedSceneTypes.length,
                        percentage: 0,
                        successCount: 0,
                        failureCount: 0
                    };

                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.generate.generationQueued', 0, {
                            count: this.progress.total
                        })
                    });

                    // Start polling for progress
                    this.startBatchJobPolling();
                } else {
                    this.isGenerating = false;
                    this.createNotificationError({
                        message: 'Generation failed: ' + (response.error || 'Unknown error')
                    });
                }
            } catch (error) {
                this.isGenerating = false;
                this.createNotificationError({
                    message: 'Generation error'
                });
                console.error('Generation error:', error);
            } finally {
                this.isLoading = false;
            }
        },

        startBatchJobPolling() {
            if (!this.batchJobId) {
                return;
            }

            this.stopPolling = this.sceneGenerationApiService.pollBatchJobStatus(
                this.batchJobId,
                {
                    onProgress: (job) => {
                        this.progress = {
                            current: job.processedItems || 0,
                            total: job.totalItems || this.progress.total,
                            percentage: job.percentage || 0,
                            successCount: job.successCount || 0,
                            failureCount: job.failureCount || 0
                        };
                    },
                    onComplete: (job) => {
                        this.progress = {
                            current: job.processedItems || job.totalItems,
                            total: job.totalItems,
                            percentage: 100,
                            successCount: job.successCount || 0,
                            failureCount: job.failureCount || 0
                        };

                        this.isGenerating = false;
                        this.batchJobId = null;
                        this.stopPolling = null;

                        this.createNotificationSuccess({
                            message: this.$tc('ai-image-tools.generate.generationComplete', 0, {
                                count: this.progress.successCount
                            })
                        });

                        // Emit event to notify parent/siblings (e.g., to refresh approval tab)
                        this.$emit('generation-complete');
                    },
                    onError: (errorMessage) => {
                        this.isGenerating = false;
                        this.batchJobId = null;
                        this.stopPolling = null;

                        this.createNotificationError({
                            message: errorMessage || this.$tc('ai-image-tools.generate.generationError')
                        });
                    },
                    interval: 3000 // Poll every 3 seconds
                }
            );
        },

        /**
         * Handler for selection changes - saves to storage and updates preview
         */
        onSelectionChange() {
            this.saveSelectionsToStorage();
            this.updatePromptPreviewDebounced();
        },

        /**
         * Debounced prompt preview update (300ms delay)
         */
        updatePromptPreviewDebounced() {
            if (this.previewDebounceTimer) {
                clearTimeout(this.previewDebounceTimer);
            }

            // Only update if preview is visible
            if (!this.showPromptPreview) {
                return;
            }

            this.previewDebounceTimer = setTimeout(() => {
                this.fetchPromptPreview();
            }, 300);
        },

        /**
         * Toggle prompt preview visibility
         */
        togglePromptPreview() {
            this.showPromptPreview = !this.showPromptPreview;

            // Always fetch fresh preview when toggling on (selections may have changed)
            if (this.showPromptPreview) {
                this.fetchPromptPreview();
            }
        },

        /**
         * Fetch prompt preview from backend
         */
        async fetchPromptPreview() {
            // Only fetch if we have at least one scene type selected
            if (this.selectedSceneTypes.length === 0) {
                this.promptPreview = null;
                return;
            }

            this.isLoadingPreview = true;

            try {
                const config = this.buildGenerationConfig();
                const response = await this.sceneGenerationApiService.getPromptPreview(config);

                if (response.success) {
                    this.promptPreview = response.preview;
                } else {
                    console.error('Failed to fetch prompt preview:', response.error);
                }
            } catch (error) {
                console.error('Error fetching prompt preview:', error);
            } finally {
                this.isLoadingPreview = false;
            }
        },

        /**
         * Build generation config object from current selections
         */
        buildGenerationConfig() {
            // Build scene types with their descriptions for prompt generation
            const sceneTypesWithDescriptions = this.selectedSceneTypes.map(label => {
                const opt = this.sceneTypeOptions.find(o => o.label === label);
                return {
                    label: label,
                    description: opt?.description || ''
                };
            });

            return {
                sceneTypes: sceneTypesWithDescriptions,
                cameraLens: this.selectedCameraLens,
                perspective: this.selectedPerspective,
                cameraAngle: this.selectedCameraAngle,
                interiorStyle: this.selectedInteriorStyle,
                lighting: this.selectedLighting,
                style: this.selectedStyle,
                styling: this.selectedStyling,
                aspectRatio: this.selectedAspectRatio,
                mood: this.selectedMood,
                colorPalette: this.selectedColorPalette,
                composition: this.selectedComposition,
                additionalDetails: this.additionalDetails
            };
        },

        openConfigModal() {
            this.showConfigModal = true;
        },

        closeConfigModal() {
            this.showConfigModal = false;
        },

        onConfigSaved() {
            // Reload options after config is saved
            this.loadOptions();
        }
    }
});
