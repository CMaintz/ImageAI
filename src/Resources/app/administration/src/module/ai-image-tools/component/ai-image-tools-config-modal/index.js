import template from './ai-image-tools-config-modal.html.twig';
import './ai-image-tools-config-modal.scss';

const { Component, Mixin } = Shopware;

/**
 * Modal for managing scene generation configuration options
 *
 * Allows adding, editing, and removing options for each category
 * (camera lens, lighting, interior style, etc.)
 */
Component.register('ai-image-tools-config-modal', {
    template,

    inject: ['sceneGenerationApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        isOpen: {
            type: Boolean,
            required: true,
            default: false
                }
                },

                data() {
                    return {
                        isLoading: false,
                        isSaving: false,
                        config: null,
                        activeCategory: 'cameraLensOptions',

                        categories: [
                            { key: 'sceneTypeOptions', label: 'Scene Types', hasValue: false, isLongDescription: true },
                            { key: 'cameraLensOptions', label: 'Camera Lens', hasValue: false },
                            { key: 'perspectiveOptions', label: 'Perspective (Vertical)', hasValue: false },
                            { key: 'cameraAngleOptions', label: 'Camera Angle (Horizontal)', hasValue: false },
                            { key: 'interiorStyleOptions', label: 'Interior Style', hasValue: false },
                            { key: 'lightingOptions', label: 'Lighting', hasValue: false },
                            { key: 'styleOptions', label: 'Visual Style', hasValue: false },
                            { key: 'stylingOptions', label: 'Styling', hasValue: false },
                            { key: 'moodOptions', label: 'Mood', hasValue: false },
                            { key: 'colorPaletteOptions', label: 'Color Palette', hasValue: false },
                            { key: 'compositionOptions', label: 'Composition', hasValue: false },
                            { key: 'aspectRatioOptions', label: 'Aspect Ratio', hasValue: true }
                        ],

                        newOption: {
                            label: '',
                            description: '',
                            value: ''
                        },

                        editingIndex: null
                    };
                },

                computed: {
                    currentCategory() {
                        return this.categories.find(c => c.key === this.activeCategory);
                    },

                    currentOptions() {
                        if (!this.config || !this.config[this.activeCategory]) {
                            return [];
                        }
                        return this.config[this.activeCategory];
                    },

                    canAddOption() {
                        if (!this.newOption.label.trim()) {
                            return false;
                        }

                        if (this.currentCategory?.hasValue) {
                            return !!this.newOption.value.trim();
                        }
                        return !!this.newOption.description.trim();
                    }
                },

                watch: {
                    isOpen: {
                        immediate: true,
                        handler(newVal) {
                            if (newVal && !this.config) {
                                this.loadConfig();
                            }
                        }
                    }
                },

                methods: {
                    async loadConfig() {
                        this.isLoading = true;

                        try {
                            const response = await this.sceneGenerationApiService.getConfig();
                            if (response.success) {
                                this.config = response.config;
                            }
                        } catch (error) {
                            console.error('Failed to load config:', error);
                            this.createNotificationError({
                                message: 'Failed to load configuration'
                            });
                        } finally {
                            this.isLoading = false;
                        }
                    },

                    selectCategory(categoryKey) {
                        this.activeCategory = categoryKey;
                        this.cancelEdit();
                        this.resetNewOption();
                    },

                    addOption() {
                        if (!this.canAddOption) {
                            return;
                        }

                        const option = {
                            label: this.newOption.label.trim()
                        };

                        if (this.currentCategory?.hasValue) {
                            option.value = this.newOption.value.trim();
                        } else {
                            option.description = this.newOption.description.trim();
                        }

                        this.config[this.activeCategory].push(option);
                        this.resetNewOption();
                    },

                    editOption(index) {
                        this.editingIndex = index;
                    },

                    saveEdit(index) {
                        this.editingIndex = null;
                    },

                    cancelEdit() {
                        this.editingIndex = null;
                    },

                    removeOption(index) {
                        this.config[this.activeCategory].splice(index, 1);
                    },

                    moveOptionUp(index) {
                        if (index <= 0) {
                            return;
                        }
                        const options = this.config[this.activeCategory];
                        [options[index - 1], options[index]] = [options[index], options[index - 1]];
                    },

                    moveOptionDown(index) {
                        const options = this.config[this.activeCategory];
                        if (index >= options.length - 1) {
                            return;
                        }
                        [options[index], options[index + 1]] = [options[index + 1], options[index]];
                    },

                    resetNewOption() {
                        this.newOption = {
                            label: '',
                            description: '',
                            value: ''
                        };
                    },

                    async saveConfig() {
                        this.isSaving = true;

                        try {
                            await this.sceneGenerationApiService.updateConfig(this.config);

                            this.createNotificationSuccess({
                                message: 'Configuration saved successfully'
                            });

                            this.$emit('config-saved');
                            this.closeModal();
                        } catch (error) {
                            console.error('Failed to save config:', error);
                            this.createNotificationError({
                                message: 'Failed to save configuration'
                            });
                        } finally {
                            this.isSaving = false;
                        }
                    },

                    closeModal() {
                        this.$emit('close');
                    }
                }
                });
