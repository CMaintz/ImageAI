import template from './ai-image-tools-detail.html.twig';
import './ai-image-tools-detail.scss';
import languageMixin from '../../../../mixins/ai-analysis-language.mixin';
import renderMixin from '../../../../mixins/ai-analysis-render.mixin';

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.register('ai-image-tools-detail', {
    template,

    inject: ['repositoryFactory', 'imageAiAnalysisApiService'],

    mixins: [
        Mixin.getByName('notification'),
        languageMixin,
        renderMixin
    ],

    data() {
        return {
            analysisResult: null,
            isLoading: true,
            isSaving: false,
            propertyGroups: [],
            editableProperties: {}
        };
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

        analysisId() {
            return this.$route.params.id;
        },

        productCoverUrl() {
            if (this.analysisResult && this.analysisResult.product && this.analysisResult.product.cover) {
                return this.analysisResult.product.cover.media.url;
            }
            return null;
        },

        canEdit() {
            return this.analysisResult &&
                   this.analysisResult.status === 'pending_review';
        },

        currentTranslation() {
            if (!this.analysisResult?.translations) {
                return null;
            }
            const languageId = this.getLanguageIdByCode(this.selectedLanguage);
            if (!languageId) {
                return null;
            }
            return this.analysisResult.translations.find(t => t.languageId === languageId);
        },

        metaTitle: {
            get() {
                return this.currentTranslation?.metaTitle || '';
            },
            set(value) {
                if (!this.currentTranslation) {
                    return;
                }
                this.currentTranslation.metaTitle = value;
            }
        },

        metaDescription: {
            get() {
                return this.currentTranslation?.metaDescription || '';
            },
            set(value) {
                if (!this.currentTranslation) {
                    return;
                }
                this.currentTranslation.metaDescription = value;
            }
        },

        seoKeywords: {
            get() {
                return this.currentTranslation?.seoKeywords || '';
            },
            set(value) {
                if (!this.currentTranslation) {
                    return;
                }
                this.currentTranslation.seoKeywords = value;
            }
        },

        productDescription: {
            get() {
                return this.currentTranslation?.productDescription || '';
            },
            set(value) {
                if (!this.currentTranslation) {
                    return;
                }
                this.currentTranslation.productDescription = value;
            }
        }
    },

    async created() {
        // Wait for mixin's language initialization to complete
        await this.initializeLanguages();
        await this.loadPropertyGroups();
        await this.loadAnalysisResult();
    },

    methods: {
        async loadAnalysisResult() {
            this.isLoading = true;

            try {
                const criteria = new Criteria();
                criteria.addAssociation('product.cover.media');
                criteria.addAssociation('translations');

                this.analysisResult = await this.aiAnalysisRepository.get(this.analysisId, Shopware.Context.api, criteria);

                // Initialize editable properties from analyzed properties
                if (this.analysisResult.analyzedProperties) {
                    this.editableProperties = JSON.parse(JSON.stringify(this.analysisResult.analyzedProperties));
                }

                this.isLoading = false;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.detail.loadError')
                });
                this.isLoading = false;
            }
        },

        async loadPropertyGroups() {
            try {
                const propertyGroupRepository = this.repositoryFactory.create('property_group');
                const criteria = new Criteria();
                criteria.addAssociation('options.translations');
                criteria.addAssociation('translations');
                criteria.addFilter(Criteria.equals('customFields.illux_ai_managed', true));

                this.propertyGroups = await propertyGroupRepository.search(criteria, Shopware.Context.api);
            } catch (error) {
                console.error('Failed to load property groups:', error);
            }
        },

        async onSave() {
            this.isSaving = true;

            try {
                // Update analyzed properties with edited values
                this.analysisResult.analyzedProperties = this.editableProperties;

                await this.aiAnalysisRepository.save(this.analysisResult);

                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.properties.detail.saveSuccess')
                });

                this.isSaving = false;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.detail.saveError')
                });
                this.isSaving = false;
            }
        },

        async onApprove() {
            // Validate before approving
            const validationErrors = this.validateAnalysisResult();
            if (validationErrors.length > 0) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.detail.validationError') + '\n' + validationErrors.join('\n')
                });
                return;
            }

            this.isSaving = true;

            try {
                // First, update analyzed properties with edited values
                this.analysisResult.analyzedProperties = this.editableProperties;

                // Save the analysis result with all edits
                await this.aiAnalysisRepository.save(this.analysisResult, Shopware.Context.api);

                // Then call the approval API to apply changes to the product
                const response = await this.imageAiAnalysisApiService.approveAnalysisResults([this.analysisId]);

                if (response.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('ai-image-tools.properties.detail.approveSuccess')
                    });

                    this.isSaving = false;
                    this.$router.push({ name: 'ai.image.tools.index', params: { activeTab: 'approval' } });
                } else {
                    this.createNotificationError({
                        message: this.$tc('ai-image-tools.properties.detail.approveError')
                    });
                    this.isSaving = false;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.detail.approveError')
                });
                this.isSaving = false;
            }
        },

        validateAnalysisResult() {
            const errors = [];

            // Validate property options are set
            if (this.editableProperties) {
                Object.entries(this.editableProperties).forEach(([propName, propData]) => {
                    if (!propData.options || propData.options.length === 0) {
                        errors.push(`${propName}: No options selected`);
                    }
                });
            }

            // Validate translations for all configured languages
            this.configLanguages.forEach(langCode => {
                const languageId = this.getLanguageIdByCode(langCode);
                if (!languageId) {
                    errors.push(`Language ${langCode}: Not found`);
                    return;
                }

                const translation = this.analysisResult.translations?.find(t => t.languageId === languageId);
                if (!translation) {
                    // Missing translation is only an error if at least one translation exists
                    if (this.analysisResult.translations && this.analysisResult.translations.length > 0) {
                        errors.push(`${langCode}: Missing translation`);
                    }
                    return;
                }

                // Only validate fields that were part of the analysis
                // If a field is not null/undefined, it was analyzed - so validate it's not empty
                if (translation.metaTitle !== null && translation.metaTitle !== undefined) {
                    if (translation.metaTitle.trim() === '') {
                        errors.push(`${langCode}: Meta title is empty but was part of analysis`);
                    }
                }
                if (translation.metaDescription !== null && translation.metaDescription !== undefined) {
                    if (translation.metaDescription.trim() === '') {
                        errors.push(`${langCode}: Meta description is empty but was part of analysis`);
                    }
                }
                if (translation.seoKeywords !== null && translation.seoKeywords !== undefined) {
                    if (typeof translation.seoKeywords === 'string' && translation.seoKeywords.trim() === '') {
                        errors.push(`${langCode}: SEO keywords are empty but were part of analysis`);
                    }
                }
                if (translation.productDescription !== null && translation.productDescription !== undefined) {
                    if (translation.productDescription.trim() === '') {
                        errors.push(`${langCode}: Product description is empty but was part of analysis`);
                    }
                }
            });

            return errors;
        },

        async onReject() {
            this.isSaving = true;

            try {
                this.analysisResult.status = 'rejected';
                await this.aiAnalysisRepository.save(this.analysisResult);

                this.createNotificationSuccess({
                    message: this.$tc('ai-image-tools.properties.detail.rejectSuccess')
                });

                this.isSaving = false;
                this.$router.push({ name: 'ai.image.tools.index', params: { activeTab: 'approval' } });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('ai-image-tools.properties.detail.rejectError')
                });
                this.isSaving = false;
            }
        },

        onCancel() {
            this.$router.push({ name: 'ai.image.tools.index' });
        },

        formatAnalyzedProperties(properties) {
            if (!properties) {
                return [];
            }

            return Object.entries(properties).map(([key, value]) => {
                let displayValue = value;
                if (typeof value === 'object' && value.options) {
                    displayValue = Array.isArray(value.options) ? value.options.join(', ') : value.options;
                }
                return { key, value: displayValue };
            });
        },

        getPropertyGroupByName(groupName) {
            // Convert underscores back to spaces (schema uses underscored keys)
            const normalizedName = groupName.replace(/_/g, ' ');

            // First try direct name match (current language)
            let group = this.propertyGroups.find(pg => pg.name === normalizedName);
            if (group) {
                return group;
            }

            // Try matching against English translation (AI returns English names)
            group = this.propertyGroups.find(pg => {
                const englishName = this.getEnglishTranslation(pg, 'name');
                return englishName === normalizedName;
            });

            return group;
        },

        getPropertyGroupLabel(groupName) {
            const propertyGroup = this.getPropertyGroupByName(groupName);
            // Convert underscores to spaces for display fallback
            const fallbackName = groupName.replace(/_/g, ' ');
            if (!propertyGroup) {
                return fallbackName;
            }
            return this.getEnglishTranslation(propertyGroup, 'name', fallbackName);
        },

        getOptionsForPropertyGroup(groupName) {
            const propertyGroup = this.getPropertyGroupByName(groupName);
            if (!propertyGroup || !propertyGroup.options) {
                return [];
            }

            return propertyGroup.options.map(option => {
                // Use English name for both value and label (AI stores English names)
                const englishName = this.getEnglishTranslation(option, 'name', option.name);

                return {
                    value: englishName,
                    label: englishName
                };
            });
        }
    }
});
