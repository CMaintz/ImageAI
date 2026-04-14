import template from './ai-image-tools-properties.html.twig';
import './ai-image-tools-properties.scss';
import languageMixin from '../../../../mixins/ai-analysis-language.mixin';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('ai-image-tools-properties', {
    template,

    inject: ['repositoryFactory', 'systemConfigApiService', 'imageAiAnalysisApiService'],

    mixins: [
        Mixin.getByName('notification'),
        languageMixin
    ],

    data() {
        return {
            isLoading: true,
            isSaveSuccessful: false,
            properties: [],
            languages: [],
            configLanguages: [],
            editingProperty: null,
            showModal: false,
            currentTab: 'basic',
            newProperty: {
                name: '',
                displayType: 'text',
                sortingType: 'alphanumeric',
                filterable: true,
                visibleOnProductDetailPage: true,
                position: 0,
                options: [],
                translations: {}
            },
            validationErrors: {
                name: null,
                options: null,
                translations: {}
            },
            suggestedOptions: {},
            suggestedOptionsLoading: false,
            totalSuggestions: 0,
            selectedSuggestions: [],
            showSuggestionApprovalModal: false,
            suggestionApprovalTab: 'summary',
            suggestionTranslations: {},
            suggestionValidationErrors: {}
        };
    },

    computed: {
        propertyGroupRepository() {
            return this.repositoryFactory.create('property_group');
        },

        propertyGroupOptionRepository() {
            return this.repositoryFactory.create('property_group_option');
        },

        languageRepository() {
            return this.repositoryFactory.create('language');
        },

        displayTypeOptions() {
            return [
                { value: 'text', label: this.$tc('ai-image-tools.properties.displayType.text') },
                { value: 'color', label: this.$tc('ai-image-tools.properties.displayType.color') },
                { value: 'image', label: this.$tc('ai-image-tools.properties.displayType.image') }
            ];
        },

        sortingTypeOptions() {
            return [
                { value: 'alphanumeric', label: this.$tc('ai-image-tools.properties.sortingType.alphanumeric') },
                { value: 'position', label: this.$tc('ai-image-tools.properties.sortingType.position') }
            ];
        },

        groupedSelectedSuggestions() {
            const grouped = {};
            this.selectedSuggestions.forEach(suggestion => {
                if (!grouped[suggestion.propertyGroup]) {
                    grouped[suggestion.propertyGroup] = [];
                }
                grouped[suggestion.propertyGroup].push(suggestion);
            });
            return grouped;
        }
    },

    async created() {
        await this.loadConfigLanguages();
        await this.loadLanguages();
        await this.loadProperties();
        await this.loadSuggestedOptions();
    },

    watch: {
        'newProperty.options': {
            handler(newOptions, oldOptions) {
                if (!this.showModal) {
                    return;
                }

                // Sync translations when options change
                this.configLanguages.forEach(langCode => {
                    if (!this.newProperty.translations[langCode]) {
                        this.newProperty.translations[langCode] = {
                            name: '',
                            options: {}
                        };
                    }

                    const optionTranslations = this.newProperty.translations[langCode].options;

                    newOptions.forEach(option => {
                        if (!optionTranslations.hasOwnProperty(option)) {
                            optionTranslations[option] = '';
                        }
                    });

                    Object.keys(optionTranslations).forEach(key => {
                        if (!newOptions.includes(key)) {
                            delete optionTranslations[key];
                        }
                    });
                });
            },
            deep: true
        }
    },

    methods: {
        async loadProperties() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('customFields.illux_ai_managed', true));
            criteria.addSorting(Criteria.sort('position', 'ASC'));
            criteria.addAssociation('options');
            criteria.addAssociation('options.translations');
            criteria.addAssociation('translations');

            try {
                const result = await this.propertyGroupRepository.search(criteria, Shopware.Context.api);
                this.properties = Array.from(result);
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.notifications.loadError')
                });
                console.error('Load error:', error);
            } finally {
                this.isLoading = false;
            }
        },

        openCreateModal() {
            this.editingProperty = null;
            this.currentTab = 'basic';
            this.validationErrors = {
                name: null,
                options: null,
                translations: {}
            };

            this.newProperty = {
                name: '',
                displayType: 'text',
                sortingType: 'alphanumeric',
                filterable: true,
                visibleOnProductDetailPage: true,
                position: this.properties.length,
                options: [],
                translations: {}
            };

            this.configLanguages.forEach(langCode => {
                this.newProperty.translations[langCode] = {
                    name: '',
                    options: {}
                };
            });

            this.showModal = true;
        },

        async openEditModal(property) {
            this.editingProperty = property.id;
            this.currentTab = 'basic';
            this.validationErrors = {
                name: null,
                options: null,
                translations: {}
            };

            const optionNames = [];
            if (property.options) {
                property.options.forEach(option => {
                    optionNames.push(option.name);
                });
            }

            this.newProperty = {
                id: property.id,
                name: property.name,
                displayType: property.displayType || 'text',
                sortingType: property.sortingType || 'alphanumeric',
                filterable: property.filterable !== undefined ? property.filterable : true,
                visibleOnProductDetailPage: property.visibleOnProductDetailPage !== undefined ? property.visibleOnProductDetailPage : true,
                position: property.position || 0,
                options: optionNames,
                translations: {}
            };

            this.configLanguages.forEach(langCode => {
                const languageId = this.getLanguageIdByCode(langCode);

                const groupTranslation = property.translations?.find(t => t.languageId === languageId);

                const optionTranslations = {};
                if (property.options) {
                    property.options.forEach(option => {
                        const optionTranslation = option.translations?.find(t => t.languageId === languageId);
                        optionTranslations[option.name] = optionTranslation?.name || '';
                    });
                }

                this.newProperty.translations[langCode] = {
                    name: groupTranslation?.name || '',
                    options: optionTranslations
                };
            });

            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.editingProperty = null;
            this.currentTab = 'basic';
            this.validationErrors = {
                name: null,
                options: null,
                translations: {}
            };
        },

        onTabChange(tabName) {
            if (typeof tabName === 'object' && tabName !== null) {
                this.currentTab = tabName.name || tabName;
            } else {
                this.currentTab = tabName;
            }
        },

        validateProperty() {
            this.validationErrors = {
                name: null,
                options: null,
                translations: {}
            };

            let isValid = true;

            if (this.newProperty.name.length < 3) {
                this.validationErrors.name = this.$tc('ai-image-tools.properties.validation.nameTooShort');
                isValid = false;
            }

            if (!this.newProperty.options || this.newProperty.options.length === 0) {
                this.validationErrors.options = this.$tc('ai-image-tools.properties.validation.noOptions');
                isValid = false;
            }

            // Ensure English translations are populated from base values before validation
            if (!this.newProperty.translations['en-GB']) {
                this.newProperty.translations['en-GB'] = { name: '', options: {} };
            }
            this.newProperty.translations['en-GB'].name = this.newProperty.name;
            this.newProperty.options.forEach(option => {
                this.newProperty.translations['en-GB'].options[option] = option;
            });

            // Validate that all configured languages have translations (excluding English which uses base values)
            this.configLanguages.filter(code => code !== 'en-GB').forEach(langCode => {
                const translation = this.newProperty.translations[langCode];
                const missingOptions = [];

                if (!translation || !translation.name || translation.name.trim() === '') {
                    if (!this.validationErrors.translations[langCode]) {
                        this.validationErrors.translations[langCode] = [];
                    }
                    this.validationErrors.translations[langCode].push(
                        this.$tc('ai-image-tools.properties.validation.missingGroupName')
                    );
                    isValid = false;
                }

                // Check all options have translations
                this.newProperty.options.forEach(option => {
                    if (!translation?.options?.[option] ||
                        translation.options[option].trim() === '') {
                        missingOptions.push(option);
                    }
                });

            if (missingOptions.length > 0) {
                if (!this.validationErrors.translations[langCode]) {
                    this.validationErrors.translations[langCode] = [];
                }
                this.validationErrors.translations[langCode].push(
                    this.$tc('ai-image-tools.properties.validation.missingOptionTranslations', 0, {
                        options: missingOptions.join(', ')
                        })
                );
                isValid = false;
            }
            });

            return isValid;
        },

        async saveProperty() {
            // Validate before saving
            if (!this.validateProperty()) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.validation.errorTitle')
                });

                // Switch to first tab with errors
                if (this.validationErrors.name || this.validationErrors.options) {
                    this.currentTab = 'basic';
                } else {
                    // Find first language tab with errors
                    const langWithError = this.configLanguages.find(
                        lang =>
                        this.validationErrors.translations[lang]
                    );
                    if (langWithError) {
                        this.currentTab = langWithError;
                    }
                }

                return;
            }

            this.isLoading = true;
            this.isSaveSuccessful = false;

            try {
                if (this.editingProperty) {
                    await this.updatePropertyGroup();
                } else {
                    await this.createPropertyGroup();
                }

                this.isSaveSuccessful = true;
                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.properties.notifications.saveSuccess')
                });

                await this.loadProperties();
                this.closeModal();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.notifications.saveError')
                });
                console.error('Save error:', error);
            } finally {
                this.isLoading = false;
            }
        },

        async createPropertyGroup() {
            // Build translations object keyed by language ID
            const translations = {};
            this.configLanguages.forEach(langCode => {
                const languageId = this.getLanguageIdByCode(langCode);
                if (languageId && this.newProperty.translations[langCode]) {
                    translations[languageId] = {
                        name: this.newProperty.translations[langCode].name
                    };
                }
            });

            // Build options array with translations
            const options = this.newProperty.options.map(optionName => {
                const optionTranslations = {};
                this.configLanguages.forEach(langCode => {
                    const languageId = this.getLanguageIdByCode(langCode);
                    if (languageId) {
                        optionTranslations[languageId] = {
                            name: this.newProperty.translations[langCode]?.options?.[optionName] || optionName
                        };
                    }
                });

                return {
                    name: optionName,
                    translations: optionTranslations
                };
            });

            // Call backend API to create property group
            await this.imageAiAnalysisApiService.createPropertyGroup({
                name: this.newProperty.name,
                displayType: this.newProperty.displayType,
                sortingType: this.newProperty.sortingType,
                filterable: this.newProperty.filterable !== undefined ? this.newProperty.filterable : true,
                visibleOnProductDetailPage: this.newProperty.visibleOnProductDetailPage,
                position: this.newProperty.position,
                translations,
                options
            });
        },

        async updatePropertyGroup() {
            const criteria = new Criteria();
            criteria.addAssociation('translations');

            const propertyGroup = await this.propertyGroupRepository.get(
                this.editingProperty,
                Shopware.Context.api,
                criteria
            );

            propertyGroup.name = this.newProperty.name;
            propertyGroup.displayType = this.newProperty.displayType;
            propertyGroup.sortingType = this.newProperty.sortingType;
            propertyGroup.filterable = this.newProperty.filterable;
            propertyGroup.visibleOnProductDetailPage = this.newProperty.visibleOnProductDetailPage;
            propertyGroup.position = this.newProperty.position;
            propertyGroup.customFields = { illux_ai_managed: true };

            this.configLanguages.forEach(langCode => {
                const languageId = this.getLanguageIdByCode(langCode);
                if (languageId && this.newProperty.translations[langCode]) {
                    propertyGroup.translations[languageId] = {
                        name: this.newProperty.translations[langCode].name
                    };
                }
            });

            await this.propertyGroupRepository.save(propertyGroup, Shopware.Context.api);

            await this.updatePropertyOptions();
        },

        async updatePropertyOptions() {
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('id', this.editingProperty));
            criteria.addAssociation('options');

            const propertyGroup = await this.propertyGroupRepository.search(criteria, Shopware.Context.api);
            const currentProperty = propertyGroup.first();

            const currentOptionNames = [];
            if (currentProperty.options) {
                currentProperty.options.forEach(option => {
                    currentOptionNames.push(option.name);
                });
            }

            const toDelete = currentOptionNames.filter(name => !this.newProperty.options.includes(name));
            const toCreate = this.newProperty.options.filter(name => !currentOptionNames.includes(name));
            const toUpdate = this.newProperty.options.filter(name => currentOptionNames.includes(name));

            if (toDelete.length > 0 && currentProperty.options) {
                for (const optionName of toDelete) {
                    const option = currentProperty.options.find(opt => opt.name === optionName);
                    if (option) {
                        await this.propertyGroupOptionRepository.delete(option.id, Shopware.Context.api);
                    }
                }
            }

            for (const optionName of toCreate) {
                const option = this.propertyGroupOptionRepository.create(Shopware.Context.api);
                option.groupId = this.editingProperty;
                option.name = optionName;

                this.configLanguages.forEach(langCode => {
                    const languageId = this.getLanguageIdByCode(langCode);
                    if (languageId) {
                        option.translations[languageId] = {
                            name: this.newProperty.translations[langCode]?.options?.[optionName] || optionName
                        };
                    }
                });

                await this.propertyGroupOptionRepository.save(option, Shopware.Context.api);
            }

            for (const optionName of toUpdate) {
                const existingOption = currentProperty.options.find(opt => opt.name === optionName);
                if (existingOption) {
                    const optionCriteria = new Criteria();
                    optionCriteria.addAssociation('translations');
                    const option = await this.propertyGroupOptionRepository.get(
                        existingOption.id,
                        Shopware.Context.api,
                        optionCriteria
                    );

                    this.configLanguages.forEach(langCode => {
                        const languageId = this.getLanguageIdByCode(langCode);
                        if (languageId) {
                            option.translations[languageId] = {
                                name: this.newProperty.translations[langCode]?.options?.[optionName] || optionName
                            };
                        }
                    });

                    await this.propertyGroupOptionRepository.save(option, Shopware.Context.api);
                }
            }
        },

        async deleteProperty(propertyId) {
            if (!confirm(this.$tc('ai-image-tools.properties.prompts.confirmDelete'))) {
                return;
            }

            this.isLoading = true;

            try {
                await this.propertyGroupRepository.delete(propertyId, Shopware.Context.api);
                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.properties.notifications.deleteSuccess')
                });
                await this.loadProperties();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.notifications.deleteError')
                });
                console.error('Delete error:', error);
            } finally {
                this.isLoading = false;
            }
        },

        getOptionNames(property) {
            if (!property.options || property.options.length === 0) {
                return [];
            }

            const englishLanguage = this.languages.find(lang => lang.locale?.code === 'en-GB');
            const englishLanguageId = englishLanguage?.id;

            return property.options.map(option => {
                if (englishLanguageId && option.translations) {
                    const englishTranslation = option.translations.find(t => t.languageId === englishLanguageId);
                    if (englishTranslation?.name) {
                        return englishTranslation.name;
                    }
                }
                return option.name || '';
            }).filter(name => name !== '');
        },

        getEnglishOptionName(optionName) {
            // Get English translation from newProperty.translations if available
            const englishTranslation = this.newProperty.translations['en-GB'];
            if (englishTranslation && englishTranslation.options && englishTranslation.options[optionName]) {
                return englishTranslation.options[optionName];
            }
            // Fall back to the option name itself
            return optionName;
        },

        async loadSuggestedOptions() {
            this.suggestedOptionsLoading = true;
            try {
                const response = await this.imageAiAnalysisApiService.getSuggestedOptions();
                const data = response?.data || response;

                if (data?.success) {
                    this.suggestedOptions = data.suggestions || {};
                    this.totalSuggestions = data.totalSuggestions || 0;
                }
            } catch (error) {
                console.error('Failed to load suggested options:', error);
            } finally {
                this.suggestedOptionsLoading = false;
            }
        },

        isSuggestionSelected(propertyGroup, optionName) {
            return this.selectedSuggestions.some(
                s => s.propertyGroup === propertyGroup && s.optionName === optionName
            );
        },

        toggleSuggestionSelection(propertyGroup, optionName) {
            const index = this.selectedSuggestions.findIndex(
                s => s.propertyGroup === propertyGroup && s.optionName === optionName
            );

        if (index > -1) {
            this.selectedSuggestions.splice(index, 1);
        } else {
            this.selectedSuggestions.push({ propertyGroup, optionName });
        }
        },

        selectAllSuggestions() {
            this.selectedSuggestions = [];
            Object.entries(this.suggestedOptions).forEach(([propertyGroup, options]) => {
                options.forEach(option => {
                    this.selectedSuggestions.push({
                        propertyGroup,
                        optionName: option.name
                    });
                });
            });
        },

        deselectAllSuggestions() {
            this.selectedSuggestions = [];
        },

        openSuggestionApprovalModal() {
            if (this.selectedSuggestions.length === 0) {
                this.createNotificationWarning({
                    message: this.$tc('ai-image-tools.properties.suggestions.noSelections')
                });
                return;
            }

            // Initialize translations structure for each selected suggestion
            this.suggestionTranslations = {};
            this.suggestionValidationErrors = {};
            this.selectedSuggestions.forEach(suggestion => {
                const key = `${suggestion.propertyGroup}::${suggestion.optionName}`;
                this.suggestionTranslations[key] = {};

                // Initialize empty translation for each configured language
                this.configLanguages.forEach(langCode => {
                    // English uses the original suggestion name as default
                    if (langCode === 'en-GB') {
                        this.suggestionTranslations[key][langCode] = suggestion.optionName;
                    } else {
                        this.suggestionTranslations[key][langCode] = '';
                    }
                });
            });

            this.suggestionApprovalTab = 'summary';
            this.showSuggestionApprovalModal = true;
        },

        closeSuggestionApprovalModal() {
            this.showSuggestionApprovalModal = false;
            this.suggestionTranslations = {};
            this.suggestionValidationErrors = {};
            this.suggestionApprovalTab = 'summary';
        },

        onSuggestionApprovalTabChange(tabName) {
            if (typeof tabName === 'object' && tabName !== null) {
                this.suggestionApprovalTab = tabName.name || tabName;
            } else {
                this.suggestionApprovalTab = tabName;
            }
        },

        validateSuggestionTranslations() {
            this.suggestionValidationErrors = {};
            let isValid = true;

            // Check each suggestion has translations for all languages
            this.selectedSuggestions.forEach(suggestion => {
                const key = `${suggestion.propertyGroup}::${suggestion.optionName}`;
                const translations = this.suggestionTranslations[key] || {};

                this.configLanguages.forEach(langCode => {
                    const translation = translations[langCode];
                    if (!translation || translation.trim() === '') {
                        if (!this.suggestionValidationErrors[langCode]) {
                            this.suggestionValidationErrors[langCode] = [];
                        }
                        this.suggestionValidationErrors[langCode].push(suggestion.optionName);
                        isValid = false;
                    }
                });
            });

            return isValid;
        },

        async submitSuggestionApproval() {
            if (!this.validateSuggestionTranslations()) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.suggestions.validationError')
                });

                // Switch to first tab with errors (skip summary)
                const langWithError = this.configLanguages.find(
                    lang => this.suggestionValidationErrors[lang]
                );
                if (langWithError) {
                    this.suggestionApprovalTab = langWithError;
                }
                return;
            }

            this.suggestedOptionsLoading = true;
            try {
                const optionsWithTranslations = this.selectedSuggestions.map(suggestion => {
                    const key = `${suggestion.propertyGroup}::${suggestion.optionName}`;
                    return {
                        propertyGroup: suggestion.propertyGroup,
                        optionName: suggestion.optionName,
                        translations: this.suggestionTranslations[key]
                    };
                });

                const response = await this.imageAiAnalysisApiService.approveSuggestedOptions(
                    optionsWithTranslations
                );
                const data = response?.data || response;

                if (data?.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.properties.suggestions.approveSuccess', 0, {
                            created: data.created,
                            failed: data.failed
                        })
                    });

                    this.selectedSuggestions = [];
                    this.closeSuggestionApprovalModal();
                    await this.loadSuggestedOptions();
                    await this.loadProperties();
                } else {
                    throw new Error(data?.error || 'Approval failed');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.message || this.$tc('ai-image-tools.properties.suggestions.approveError')
                });
            } finally {
                this.suggestedOptionsLoading = false;
            }
        },

        async rejectSelectedSuggestions() {
            if (this.selectedSuggestions.length === 0) {
                return;
            }

            this.suggestedOptionsLoading = true;
            let successCount = 0;
            let failCount = 0;

            try {
                for (const suggestion of [...this.selectedSuggestions]) {
                    try {
                        const response = await this.imageAiAnalysisApiService.rejectSuggestedOption(
                            suggestion.propertyGroup,
                            suggestion.optionName
                        );
                        const data = response?.data || response;

                        if (data?.success) {
                            successCount++;
                        } else {
                            failCount++;
                        }
                    } catch {
                        failCount++;
                    }
                }

                this.selectedSuggestions = [];
                await this.loadSuggestedOptions();

                if (successCount > 0) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.properties.suggestions.rejectSelectedSuccess', successCount, { count: successCount })
                    });
                }
                if (failCount > 0) {
                    this.createNotificationWarning({
                        message: this.$tc('ai-image-tools.properties.suggestions.rejectSelectedPartialError', failCount, { count: failCount })
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.suggestions.rejectError')
                });
            } finally {
                this.suggestedOptionsLoading = false;
            }
        }
    }
});
