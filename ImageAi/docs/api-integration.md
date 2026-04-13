# IlluxImageAi - API Integration

> **Navigation:** [Overview](./overview.md) | [Architecture](./architecture.md) | **API Integration** | [Workflows](./workflows.md) | [Admin UI](./admin-ui.md) | [Development](./development.md)

## Gemini API

The plugin communicates with Google's Gemini AI through their REST API.

### How It Works (Simplified)

1. **Send a Request**: Plugin sends product images + a prompt asking "analyze this artwork"
2. **AI Thinks**: Gemini processes the images and generates structured data
3. **Get Response**: Plugin receives JSON with descriptions, keywords, property suggestions
4. **Apply Results**: Data is stored and (optionally) applied to products

### Current Configuration

| Setting | Default Value |
|---------|---------------|
| Base URL | `https://generativelanguage.googleapis.com` |
| API Version | `v1beta` |
| Analysis Model | `gemini-2.5-flash` |
| Image Generation Model | `gemini-2.5-flash-image` |
| Authentication | API Key (`x-goog-api-key` header) |

**Full Analysis URL:**
```
https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent
```

---

## Batch Processing Architecture

The plugin **always** processes products in batches, never individually.

```
┌─────────────────────────────────────────────────────────────────┐
│                      One API Request                            │
├─────────────────────────────────────────────────────────────────┤
│  [IMAGE 1] productId="abc" analysisResultId="123"              │
│  <base64 image data>                                           │
│                                                                 │
│  [IMAGE 2] productId="def" analysisResultId="456"              │
│  <base64 image data>                                           │
│                                                                 │
│  ... up to 6 products per request ...                          │
├─────────────────────────────────────────────────────────────────┤
│  + System instruction (behavioral guidelines)                   │
│  + Analysis prompt (what to analyze, constraints)              │
│  + Response schema (enforced JSON structure)                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      One API Response                           │
├─────────────────────────────────────────────────────────────────┤
│  {                                                              │
│    "analyses": [                                                │
│      { "analysisResultId": "123", "da-DK": {...}, ... },       │
│      { "analysisResultId": "456", "da-DK": {...}, ... }        │
│    ]                                                            │
│  }                                                              │
└─────────────────────────────────────────────────────────────────┘
```

### Request Structure

From `AnalysisRequest::toApiPayload()`:

```php
return [
    'system_instruction' => [
        'parts' => [['text' => $this->systemInstruction]]
    ],
    'contents' => [[
        'parts' => [
            ['text' => $this->prompt],
            ['text' => '[IMAGE 1] productId="abc", analysisResultId="123"'],
            ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => '<base64>']],
            // ... more images ...
        ],
    ]],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
        'responseSchema' => $this->schema,
        'mediaResolution' => 'MEDIA_RESOLUTION_HIGH',
        'thinkingConfig' => ['thinkingBudget' => -1],
    ],
];
```

### Key Constants

From `PluginConstants.php`:

```php
const MAX_PRODUCTS_PER_ADMIN_REQUEST = 500;  // Max products per batch job
const MAX_PRODUCTS_PER_API_BATCH = 6;        // Products per Gemini API call
const HANDLER_CHUNK_SIZE = 30;               // Products per queue handler chunk
const API_TIMEOUT = 120;                     // Seconds
const API_TIMEOUT_BATCH = 120;               // Seconds
const API_MAX_DURATION_BATCH = 240;          // Max duration seconds
const API_MAX_DURATION_GENERATION = 180;     // Image generation max
const DEFAULT_LANGUAGES = ['en-GB', 'da-DK', 'nn-NO', 'sv-SE'];
const PROPERTY_CACHE_TTL_SECONDS = 3600;     // 1 hour
```

### Retry Logic

Exponential backoff via `RetryWithBackoffTrait`:
- Max retries: 3
- Initial delay: 500ms
- Multiplier: 2x (500ms → 1000ms → 2000ms)

---

## Controller Endpoints

All endpoints prefixed with `/api/_action/illux-ai-tools/`.

### AnalysisController (11 Endpoints)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/analyze-product/{productId}` | Analyze single product |
| POST | `/analyze-products` | Analyze specific products by IDs |
| POST | `/analyze-all-products` | Analyze all eligible unanalyzed products |
| GET | `/batch-job/{id}` | Get batch job status and progress |
| GET | `/analysis-stats` | Get statistics by analysis status |
| GET | `/active-job` | Get currently running job |
| GET | `/suggested-options` | Get pending property option suggestions |
| POST | `/suggested-options/approve` | Approve suggested property options |
| POST | `/suggested-options/reject` | Reject suggested property options |
| GET | `/time-statistics` | Get time savings statistics |
| POST | `/stop-analysis/{batchJobId}` | Cancel a running batch job |

### ApprovalController

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/approve` | Approve analysis results |
| POST | `/reject` | Reject analysis results |

### SceneGenerationController (7 Endpoints)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/scene-types` | Get available scene types from media folder |
| GET | `/scene-generation-options` | Get all configurable options |
| POST | `/generate-scene-images` | Queue scene generation (async) |
| GET | `/pending-scene-images` | Get pending images for approval |
| GET | `/scene-generation-config` | Get scene generation config |
| POST | `/scene-generation-config` | Update scene generation config |
| POST | `/prompt-preview` | Preview prompt without generating |

### Example Usage

**Start batch analysis:**
```bash
curl -X POST /api/_action/illux-ai-tools/analyze-all-products \
  -H "Authorization: Bearer <token>"
```

**Check job progress:**
```bash
curl -X GET /api/_action/illux-ai-tools/batch-job/{jobId} \
  -H "Authorization: Bearer <token>"
```

**Response:**
```json
{
  "id": "abc123",
  "type": "Analysis",
  "status": "Processing",
  "totalItems": 100,
  "processedItems": 45,
  "successCount": 43,
  "failureCount": 2,
  "startedAt": "2025-12-17T10:00:00Z"
}
```

---

## Queue System

Uses Shopware's message queue for async processing.

### Messages

| Message | Handler | Purpose |
|---------|---------|---------|
| `AnalyzeBatchMessage` | `AnalyzeBatchHandler` | Process batch of products |
| `GenerateSceneMessage` | `GenerateSceneHandler` | Generate scene images |

### AnalyzeBatchHandler Flow

```
handleMessage(AnalyzeBatchMessage)
    │
    ├── 1. Check if job is cancelled
    ├── 2. Check memory usage (> 128MB → GC)
    ├── 3. Split products into chunks (30 per chunk)
    ├── 4. For each chunk:
    │      ├── AnalysisOrchestrator::processSpecificProducts()
    │      ├── Update batch job progress
    │      └── Check for cancellation
    └── 5. Mark job as Completed/Failed
```

### Memory Management

```php
$memoryUsage = memory_get_usage(true);
if ($memoryUsage > 128 * 1024 * 1024) {  // 128MB
    gc_collect_cycles();
}
```

---

## Scheduled Tasks

### ProductAnalysisTask

Automatically analyzes new products on schedule.

- **Enabled via:** `scheduledTaskEnabled` setting
- **Interval:** `scheduledTaskInterval` (default: 8 hours)

**What It Does:**
1. Find products with "Illux Artwork" property type
2. Exclude already-analyzed products
3. Create batch job for unanalyzed products
4. Dispatch to queue

### JobCleanupTask

Cleans up old batch jobs and pending scene images.

**What It Does:**
- Delete completed/failed jobs older than retention period
- Remove orphaned pending scene images
- Free up database space

---

*Last Updated: December 2025*
