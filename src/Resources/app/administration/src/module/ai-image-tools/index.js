import './page/ai-image-tools-index';
import './page/ai-image-tools-detail';

Shopware.Module.register('ai-image-tools', {
    type: 'plugin',
    name: 'AIImageTools',
    title: 'ai-image-tools.general.mainMenuItemGeneral',
    description: 'ai-image-tools.general.descriptionTextModule',
    icon: 'regular-artificial-intelligence',

    routes: {
        index: {
            component: 'ai-image-tools-index',
            path: 'dashboard'
        },
        analysisDetail: {
            component: 'ai-image-tools-detail',
            path: 'analysis/:id',
            meta: {
                parentPath: 'ai.image.tools.index'
            }
        }
    },

    navigation: [
        {
            id: 'image-ai-image-tools',
            label: 'ai-image-tools.general.mainMenuItemGeneral',
            path: 'ai.image.tools.index',
            icon: 'regular-artificial-intelligence',
            position: 199,
            parent: 'sw-content'
    }
    ],
});


