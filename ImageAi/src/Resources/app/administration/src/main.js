import './services/ai-analysis-api.service';
import './services/scene-generation-api.service';

import illuxAiAnalysisStore from './module/ai-image-tools/state/illux-ai-analysis.store';

Shopware.State.registerModule('illuxAiAnalysis', illuxAiAnalysisStore);

import './module/ai-image-tools';

import enGB from './module/ai-image-tools/snippet/en-GB.json'
import daDK from './module/ai-image-tools/snippet/da-DK.json'

const {Locale} = Shopware

Locale.extend('en-GB', enGB)
Locale.extend('da-DK', daDK)
