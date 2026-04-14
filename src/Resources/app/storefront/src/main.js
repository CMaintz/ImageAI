import AiCompositorPlugin from './ai-compositor/ai-compositor.plugin';

const { PluginManager } = window;
PluginManager.register('AiCompositorPlugin', AiCompositorPlugin, '[data-ai-compositor]');
