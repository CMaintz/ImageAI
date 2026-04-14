import template from './ai-image-tools-configuration.html.twig';
import './ai-image-tools-configuration.scss';
import languageMixin from '../../../../mixins/ai-analysis-language.mixin';

const { Component, Mixin } = Shopware;

Component.register(
    'ai-image-tools-configuration',
    {
        template,

        inject: ['systemConfigApiService', 'acl', 'repositoryFactory'],

        mixins: [
        Mixin.getByName('notification'),
        languageMixin
        ],

        data() {
            return {
                isLoading: false,
                isSaveSuccessful: false,
                availableLanguages: [],
                localConfig: {
                    // Analysis Configuration
                    eligibleProductTypes: 'Wexo Artwork',
                    metaTitleMaxLength: 56,
                    metaDescriptionMaxLength: 160,
                    keywordCount: 5,
                    keywordsMaxCharacterLength: 255,
                    includeSeoAnalysis: true,
                    descriptionMaxLength: 500,
                    tone: 'professional',
                    includeProductDescription: true,

                    // Language Configuration
                    analysisLanguages: 'da-DK,en-GB,nn-NO,sv-SE',

                    // Scheduled Analysis
                    scheduledTaskEnabled: true,
                    scheduledTaskInterval: 4,

                    // Gemini API
                    apiKey: '',
                    apiModel: 'gemini-2.5-flash',
                    imageGenerationModel: 'gemini-2.5-flash-image',
                    apiBaseUrl: 'https://generativelanguage.googleapis.com',
                    apiVersion: 'v1beta',

                    // Workflow / Confidence Settings
                    enableApprovalWorkflow: true,
                    enableConfidenceThreshold: true,
                    lowConfidenceThreshold: 80, // Stored as 0-100 percentage

                    // Field Weights (stored as 0-100 percentages)
                    fieldWeightMetaTitle: 25,
                    fieldWeightMetaDescription: 25,
                    fieldWeightProductDescription: 20,
                    fieldWeightSeoKeywords: 15,
                    fieldWeightProperties: 15,

                    // Content Patterns
                    genericPatterns: `/dette(er et |)smukt/i
                    /perfekt til enhver/i
                    /fantastisk tilføjelse/i
                    /høj.?kvalitet/i
                    /\\[.*\\]/i
                    /lorem ipsum/i
                    /\\{[^}]+\\}/i
                /<[^>]+>/i`,
                hedgingWords: `muligvis
                måske
                kunne være
                ser ud til at
                synes at
                sandsynligvis
                formentlig
                tilsyneladende`,

                // Property Settings (penalties as 0-100 percentages)
                idealOptionsPerProperty: 3,
                emptyPropertyPenalty: 15,
                excessOptionPenalty: 3,
                noPropertiesPenalty: 10,
                lowPropertyMatchPenalty: 5,

                // Length Settings (as 0-100 percentages)
                minLengthRatio: 33,
                shortTitlePenalty: 5,
                longTitlePenalty: 2,
                shortDescriptionPenalty: 5,
                fewKeywordsPenalty: 3,

                // Content Quality Penalties (as 0-100 percentages)
                genericContentPenalty: 8,
                hedgingPenaltyPerInstance: 3,
                hedgingPenaltyMax: 10,
                duplicateContentPenalty: 10,
                maxTotalPenalty: 40,

                // Time Tracking
                minutesSavedPerProperty: 2,
                minutesSavedPerSeo: 3,
                minutesSavedForBaseDescription: 5,
                minutesSavedPerAdditionalTranslation: 2
            },

            // UI state - card collapse toggles (true = expanded)
            showWorkflowConfig: true,
            showAnalysisConfig: true,
            showLanguageConfig: false,
            showScheduledAnalysis: false,
            showApiSettings: false,
            showAdvancedConfidence: false,
            showTimeTracking: false,

            // Required language that cannot be disabled
            requiredLanguage: 'en-GB'
        };
    },
    async created() {
        this.isLoading = true;
        await Promise.all([
            this.loadConfig(),
            this.loadAvailableLanguagesForConfig()
        ]);
        this.isLoading = false;
    },
    computed: {
        selectedLanguageCodes() {
            const langs = this.localConfig.analysisLanguages;
            if (!langs) {
                return [];
            }

            // Handle both string (new format) and array (legacy format)
            if (typeof langs === 'string') {
                return langs.split(',')
                    .map(code => code.trim())
                    .filter(code => code.length > 0);
            } else if (Array.isArray(langs)) {
                // Legacy array format - convert to string array
                return langs.map(code => String(code).trim()).filter(code => code.length > 0);
            }

            return [];
        },

        metaDescriptionLengthOptions() {
            return [
                { value: 100, label: this.$tc('ai-image-tools.configuration.options.metaDescriptionShort') },
                { value: 155, label: this.$tc('ai-image-tools.configuration.options.metaDescriptionMedium') },
                { value: 255, label: this.$tc('ai-image-tools.configuration.options.metaDescriptionLong') }
            ];
        },

        toneOptions() {
            return [
                { value: 'professional', label: this.$tc('ai-image-tools.configuration.options.toneProfessional') },
                { value: 'casual', label: this.$tc('ai-image-tools.configuration.options.toneCasual') },
                { value: 'artistic', label: this.$tc('ai-image-tools.configuration.options.toneArtistic') }
            ];
        },

        // Field Weight percentage fields (0-100, step 5)
        fieldWeightFields() {
            return [
                { key: 'fieldWeightMetaTitle', step: 5 },
                { key: 'fieldWeightMetaDescription', step: 5 },
                { key: 'fieldWeightProductDescription', step: 5 },
                { key: 'fieldWeightSeoKeywords', step: 5 },
                { key: 'fieldWeightProperties', step: 5 }
            ];
        },

        // Property penalty fields (mix of int and percentage)
        propertyPenaltyFields() {
            return [
                { key: 'idealOptionsPerProperty', isPercentage: false, min: 1, max: 10, step: 1 },
                { key: 'emptyPropertyPenalty', step: 1 },
                { key: 'excessOptionPenalty', step: 1 },
                { key: 'noPropertiesPenalty', step: 1 },
                { key: 'lowPropertyMatchPenalty', step: 1 }
            ];
        },

        // Content quality penalty fields (all percentages)
        contentPenaltyFields() {
            return [
                { key: 'minLengthRatio', step: 5 },
                { key: 'shortTitlePenalty', step: 1 },
                { key: 'longTitlePenalty', step: 1 },
                { key: 'shortDescriptionPenalty', step: 1 },
                { key: 'fewKeywordsPenalty', step: 1 },
                { key: 'genericContentPenalty', step: 1 },
                { key: 'hedgingPenaltyPerInstance', step: 1 },
                { key: 'hedgingPenaltyMax', step: 1 },
                { key: 'duplicateContentPenalty', step: 1 },
                { key: 'maxTotalPenalty', step: 5 }
            ];
        },

        // All percentage field keys (derived from above for load/save conversion)
        percentageFields() {
            const allFields = [
                'lowConfidenceThreshold',
                ...this.fieldWeightFields.map(f => f.key),
                ...this.propertyPenaltyFields.filter(f => f.isPercentage !== false).map(f => f.key),
                ...this.contentPenaltyFields.map(f => f.key)
            ];
            return allFields;
        }
    },
    methods: {
        async loadAvailableLanguagesForConfig() {
            try {
                this.availableLanguages = await this.loadAllAvailableLanguages();
            } catch (error) {
                console.error('Failed to load available languages:', error);
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.configuration.languageLoadError')
                });
            }
        },

        isLanguageSelected(localeCode) {
            return this.selectedLanguageCodes.includes(localeCode);
        },

        toggleLanguage(localeCode) {
            // Prevent removing required language
            if (this.isRequiredLanguage(localeCode) && this.isLanguageSelected(localeCode)) {
                return;
            }

            const codes = [...this.selectedLanguageCodes];
            const index = codes.indexOf(localeCode);

            if (index > -1) {
                codes.splice(index, 1);
            } else {
                codes.push(localeCode);
            }

            this.localConfig.analysisLanguages = codes.join(',');
        },

        isRequiredLanguage(localeCode) {
            return localeCode === this.requiredLanguage;
        },

        getLanguageLabel(language) {
            const name = language.name || this.getLanguageName(language.locale.code);
            const code = language.locale.code;
            const isRequired = this.isRequiredLanguage(code);

            return isRequired
                ? `${name} (${code}) - ${this.$tc('ai-image-tools.configuration.requiredLanguage')}`
                : `${name} (${code})`;
        },

        ensureRequiredLanguage() {
            // Ensure required language is always in the selected list
            if (!this.selectedLanguageCodes.includes(this.requiredLanguage)) {
                const codes = [this.requiredLanguage, ...this.selectedLanguageCodes];
                this.localConfig.analysisLanguages = codes.join(',');
            }
        },

        async loadConfig() {
            try {
                const raw = await this.systemConfigApiService.getValues('CMaintzImageAi.config');

                Object.keys(this.localConfig).forEach(key => {
                    const full = `CMaintzImageAi.config.${key}`;
                    let v = raw[full];

                    // Convert percentage fields from 0-1 decimals to 0-100 integers
                    if (v !== null && v !== undefined && this.percentageFields.includes(key)) {
                        v = Math.round(v * 100);
                    }

                    this.$set(this.localConfig, key, v ?? this.localConfig[key]);
                });

                // Ensure required language is always selected
                this.ensureRequiredLanguage();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.configuration.loadError')
                });
            }
        },

        async onSave() {
            this.isLoading = true;
            this.isSaveSuccessful = false;

            try {
                const payload = {};
                Object.keys(this.localConfig).forEach(key => {
                    const full = `CMaintzImageAi.config.${key}`;
                    let value = this.localConfig[key];

                    // Convert percentage fields from 0-100 integers back to 0-1 decimals for storage
                    if (this.percentageFields.includes(key)) {
                        value = value / 100;
                    }

                    payload[full] = value;
                });

                await this.systemConfigApiService.saveValues(payload);

                this.isSaveSuccessful = true;
                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.configuration.saveSuccess')
                });

                return true;
            } catch (error) {
                console.error('Configuration save error:', error);
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.configuration.saveError')
                });
                throw error;
            } finally {
                this.isLoading = false;
            }
        }
    }
    }
);
