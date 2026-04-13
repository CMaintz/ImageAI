const { Data: { Criteria } } = Shopware;

/**
 * Mixin for AI Analysis components that need language selection and translation support
 */
export default {
    inject: ['repositoryFactory', 'systemConfigApiService'],

        data() {
            return {
                selectedLanguage: null,
                isLoadingLanguages: true,
                languages: [],
                configLanguages: [],
                _languageInitPromise: null,
            };
    },

    computed: {
        languageRepository() {
            return this.repositoryFactory.create('language');
        },

        languageOptions() {
            if (!this.languages || !Array.isArray(this.languages)) {
                return [];
            }
            return this.languages
                .filter(lang => lang?.locale?.code)
                .map(lang => ({
                    value: lang.locale.code,
                    label: lang.name || this.getLanguageName(lang.locale.code)
                }));
        },

        currentLanguageId() {
            return Shopware.Context.api.languageId;
        }
    },

    watch: {
        currentLanguageId() {
            this.updateSelectedLanguageFromContext();
        }
    },

    async created() {
        await this.initializeLanguages();
    },

    beforeUnmount() {
        this.selectedLanguage = null;
        this.languages = [];
        this.configLanguages = [];
    },

    methods: {
        /**
         * Initialize languages - idempotent, can be safely called multiple times.
         * Loads config languages, then loads language entities, then sets default selection.
         * Returns a promise that resolves when languages are ready.
         */
        async initializeLanguages() {
            // Return existing promise if already initializing (idempotent)
            if (this._languageInitPromise) {
                return this._languageInitPromise;
            }

            this._languageInitPromise = this._doInitializeLanguages();
            return this._languageInitPromise;
        },

        async _doInitializeLanguages() {
            this.isLoadingLanguages = true;
            try {
                await this.loadConfigLanguages();
                await this.loadLanguages();

                // Default to da-DK if available, otherwise first configured language
                if (this.configLanguages.length > 0 && this.languages.length > 0) {
                    if (this.configLanguages.includes('da-DK')) {
                        this.selectedLanguage = 'da-DK';
                    } else {
                        this.selectedLanguage = this.configLanguages[0];
                    }
                } else {
                    this.selectedLanguage = null;
                }
            } catch (e) {
                console.error('Error initializing languages:', e);
                this.selectedLanguage = null;
            } finally {
                this.isLoadingLanguages = false;
            }
        },

        updateSelectedLanguageFromContext() {
            if (!this.languages || !Array.isArray(this.languages)) {
                return;
            }
            const languageId = Shopware.Context.api.languageId;
            const language = this.languages.find(lang => lang?.id === languageId);

            if (language?.locale?.code) {
                this.selectedLanguage = language.locale.code;
            }
        },

        /**
         * Load language entities from repository based on configured languages.
         * Note: Use initializeLanguages() instead for full initialization with loading state.
         */
        async loadLanguages() {
            try {
                if (this.configLanguages.length > 0) {
                    const criteria = new Criteria();
                    criteria.addAssociation('locale');
                    criteria.addFilter(Criteria.equalsAny('locale.code', this.configLanguages));

                    const result = await this.languageRepository.search(criteria, Shopware.Context.api);
                    this.languages = Array.from(result);
                } else {
                    this.languages = [];
                }
            } catch (e) {
                console.error('Error loading languages:', e);
                this.languages = [];
            }
        },

        /**
         * Load ALL available languages from repository (not filtered by config)
         * Useful for configuration components that need to show all available languages
         */
        async loadAllAvailableLanguages() {
            try {
                const criteria = new Criteria(1, 500);
                criteria.addAssociation('locale');
                criteria.addAssociation('locale.translations');

                const result = await this.languageRepository.search(criteria, Shopware.Context.api);
                return Array.from(result);
            } catch (e) {
                console.error('Error loading all available languages:', e);
                return [];
            }
        },

        /**
         * Load configured analysis languages from system config
         */
        async loadConfigLanguages() {
            try {
                const config = await this.systemConfigApiService.getValues('IlluxImageAi.config');
                const analysisLanguages = config['IlluxImageAi.config.analysisLanguages'];

                if (Array.isArray(analysisLanguages)) {
                    this.configLanguages = analysisLanguages;
                } else if (typeof analysisLanguages === 'string') {
                    this.configLanguages = analysisLanguages
                        .split(',')
                        .map(code => code.trim())
                        .filter(code => code.length > 0);
                } else {
                    this.configLanguages = ['da-DK', 'en-GB', 'nn-NO', 'sv-SE'];
                }
            } catch (e) {
                this.configLanguages = ['da-DK', 'en-GB', 'nn-NO', 'sv-SE'];
            }
        },

        /**
         * Get language ID by locale code
         */
        getLanguageIdByCode(code) {
            if (!this.languages || !Array.isArray(this.languages)) {
                return null;
            }
            const language = this.languages.find(lang => {
                return lang?.locale?.code === code;
            });
            return language?.id || null;
        },

        /**
         * Get language name by locale code
         */
        getLanguageName(code) {
            if (this.languages && Array.isArray(this.languages)) {
                const language = this.languages.find(lang => lang?.locale?.code === code);
                if (language) {
                    //tries language.name first, then locale.name, then fallback
                    return language.name || language.locale?.name || code;
                }
            }

            const names = {
                'en-GB': 'English',
                'da-DK': 'Danish',
                'de-DE': 'Deutsch',
                'nn-NO': 'Norwegian (Nynorsk)',
                'sv-SE': 'Swedish'
            };
            return names[code] || code;
        },

        /**
         * Get translated field value for the currently selected language.
         * Returns fallback gracefully if languages aren't loaded yet.
         */
        getTranslatedField(item, fieldName) {
            if (!item) {
                return '-';
            }

            // Guard: return fallback if languages are still loading
            if (this.isLoadingLanguages) {
                return item[fieldName] || '-';
            }

            if (!item.translations || !Array.isArray(item.translations)) {
                return item[fieldName] || '-';
            }

            if (!this.selectedLanguage) {
                return item[fieldName] || '-';
            }

            const languageId = this.getLanguageIdByCode(this.selectedLanguage);
            if (!languageId) {
                return item[fieldName] || '-';
            }

            const translation = item.translations.find(t => t && t.languageId === languageId);
            return translation?.[fieldName] || item[fieldName] || '-';
        },

        /**
         * Handle language change event
         */
        onLanguageChange(languageCode) {
            this.selectedLanguage = languageCode;
        },

        /**
         * Format date for display
         */
        formatDate(date) {
            if (!date) {
                return '-';
            }
            return new Date(date).toLocaleDateString('en-GB', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        /**
         * Get English language ID
         */
        getEnglishLanguageId() {
            const englishLanguage = this.languages.find(lang => lang.locale?.code === 'en-GB');
            return englishLanguage?.id || null;
        },

        /**
         * Get English translation for a translatable entity field
         * @param {Object} entity - Entity with translations array
         * @param {string} fieldName - Field name to get translation for
         * @param {*} fallback - Fallback value if no translation found
         */
        getEnglishTranslation(entity, fieldName, fallback = null) {
            if (!entity) {
                return fallback;
            }

            const englishLanguageId = this.getEnglishLanguageId();
            if (!englishLanguageId || !entity.translations) {
                return entity[fieldName] || fallback;
            }

            const translation = entity.translations.find(t => t.languageId === englishLanguageId);
            return translation?.[fieldName] || entity[fieldName] || fallback;
        }
    }
};
