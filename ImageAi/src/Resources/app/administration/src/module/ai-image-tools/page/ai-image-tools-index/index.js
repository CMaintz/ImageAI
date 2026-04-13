import template from './ai-image-tools-index.html.twig';
import './ai-image-tools-index.scss';

import '../../component/ai-image-tools-overview';
import '../../component/ai-image-tools-configuration';
import '../../component/ai-image-tools-properties';
import '../../component/ai-image-tools-list';
import '../../component/ai-image-tools-run';
import '../../component/ai-image-tools-generate';
import '../../component/ai-image-tools-approval';

const { Component } = Shopware;

Component.register('ai-image-tools-index', {
    template,

    data() {
        return {
            activeTab: this.$route.query.returnTab || 'overview',
            isLoading: false,
            isSaveSuccessful: false,
            total: 0
        };
    },

    computed: {
        configurationComponent() {
            return this.$refs.configurationComponent;
        }
    },

    methods: {
        onTabChange(tabName) {
            if (typeof tabName === 'object' && tabName !== null) {
                this.activeTab = tabName.name || tabName;
            } else {
                this.activeTab = tabName;
            }
        },

        switchToTab(tabName) {
            this.activeTab = tabName;
        },

        async onSave() {
            // Call the configuration component's save method
            if (this.activeTab === 'configuration' && this.configurationComponent) {
                this.isLoading = true;
                this.isSaveSuccessful = false;

                try {
                    await this.configurationComponent.onSave();
                    this.isSaveSuccessful = true;
                } catch (error) {
                    // Error is already handled in the component
                } finally {
                    this.isLoading = false;

                    // Reset success state after a delay
                    if (this.isSaveSuccessful) {
                        setTimeout(() => {
                            this.isSaveSuccessful = false;
                        }, 3000);
                    }
                }
            }
        },
    }
});
