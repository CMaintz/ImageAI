# CMaintzImageAi - Administration UI

> **Navigation:** [Overview](./overview.md) | [Architecture](./architecture.md) | [API Integration](./api-integration.md) | [Workflows](./workflows.md) | **Admin UI** | [Development](./development.md)

## Module Overview

The plugin adds an "AI Image Tools" module to the Shopware admin under the **Content** menu.

**Location:** `src/Resources/app/administration/src/module/ai-image-tools/`

**Access:** Content menu → AI Image Tools (`/admin#/sw/extension/ai-tools/dashboard`)

---

## Routes

| Route | Page | Purpose |
|-------|------|---------|
| `/dashboard` | `ai-image-tools-index` | Main dashboard |
| `/analysis/:id` | `ai-image-tools-detail` | Analysis detail/approval |

---

## Dashboard Page

The main dashboard (`ai-image-tools-index`) shows:

1. **Active Job Progress**: If a batch job is running, shows progress bar with percentage
2. **Statistics Overview**: Counts by status (pending review, approved, rejected, etc.)
3. **Batch Controls**: Buttons to start analysis, stop running jobs
4. **Time Statistics**: Total time saved by AI automation

---

## Components

| Component | Purpose |
|-----------|---------|
| `ai-image-tools-overview` | Statistics cards and summary display |
| `ai-image-tools-list` | Paginated list of analysis results |
| `ai-image-tools-run` | Controls to trigger batch analysis |
| `ai-image-tools-approval` | Approve/reject buttons and workflow |
| `ai-image-tools-properties` | Manage property option suggestions |
| `ai-image-tools-configuration` | Plugin settings display |
| `ai-image-tools-config-modal` | Modal dialog for editing config |
| `ai-image-tools-generate` | Scene generation controls |

---

## JavaScript Services

| Service | Purpose |
|---------|---------|
| `ai-analysis-api.service.js` | API client for analysis endpoints |
| `scene-generation-api.service.js` | API client for scene generation |

**Entry Point:** `main.js`
```javascript
import './services/ai-analysis-api.service';
import './services/scene-generation-api.service';

import illuxAiAnalysisStore from './module/ai-image-tools/state/illux-ai-analysis.store';
Shopware.State.registerModule('illuxAiAnalysis', illuxAiAnalysisStore);

import './module/ai-image-tools';
```

---

## State Management

Vuex store: `illuxAiAnalysis`

**State Properties:**
- `activeJob`: Currently running batch job (if any)
- `stats`: Analysis statistics by status
- `selectedResults`: Selected items for bulk actions
- `isLoading`: Loading state flags

---

## User Workflows

### Starting Batch Analysis

1. Navigate to AI Image Tools dashboard
2. Click "Analyze All Products" or "Analyze Selected"
3. Dashboard shows progress bar with real-time updates
4. When complete, results appear in the list

### Reviewing Analysis Results

1. Navigate to analysis list
2. Click on a result to open detail view
3. Review AI-generated content:
   - Meta title, description, keywords (per language)
   - Product description (per language)
   - Suggested properties
   - Confidence score and warnings
4. Click Approve or Reject

### Managing Property Suggestions

1. Navigate to Properties tab
2. View AI-suggested property options not in whitelist
3. Approve to add to whitelist, Reject to discard

### Scene Generation

1. Navigate to Scene Generation tab
2. Select scene type and configure options
3. Click Generate
4. Review pending images
5. Approve to save to media library

---

## Localization

**Supported Languages:**
- English (en-GB)
- Danish (da-DK)

**Snippet Files:**
- `module/ai-image-tools/snippet/en-GB.json`
- `module/ai-image-tools/snippet/da-DK.json`

---

*Last Updated: December 2025*
