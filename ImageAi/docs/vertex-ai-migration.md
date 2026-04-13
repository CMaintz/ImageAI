# IlluxImageAi - Vertex AI Migration Guide

> **Navigation:** [Overview](./overview.md) | [Architecture](./architecture.md) | [API Integration](./api-integration.md) | [Workflows](./workflows.md) | [Admin UI](./admin-ui.md) | [Development](./development.md) | **Vertex AI Migration**

## Platform Comparison

| Aspect | Google AI Studio | Vertex AI |
|--------|------------------|-----------|
| **Best For** | Rapid prototyping, quick API testing | Enterprise-scale AI, production |
| **Complexity** | Simple, user-friendly | Feature-rich, more complex |
| **Authentication** | API Key | Service Account / OAuth2 |
| **Quotas** | Lower limits | Higher enterprise limits |
| **Features** | Basic tuning, prompt design | Model Garden, custom training |
| **Logprobs** | Not available | Available |
| **Support** | Community | Enterprise SLAs |

### When to Use Each

**Google AI Studio (Current):**
- Quick prototyping
- Lower traffic applications
- Simple API key auth is sufficient

**Vertex AI:**
- Production, enterprise-scale
- Need logprobs for confidence calculation
- Higher quotas and SLAs needed
- Deep GCP integration required

---

## Current Implementation Status

> **Note:** LogProbs support is partially implemented but NOT wired up. The code exists and is ready for Vertex AI integration.

### What Already Exists

**1. GeminiResponseParser::extractLogProbs()** (`src/Api/Gemini/GeminiResponseParser.php:112-140`)
```php
public function extractLogProbs(array $rawResponse): array
{
    $candidate = $rawResponse['candidates'][0] ?? null;
    if (!$candidate || empty($candidate['logprobsResult']['chosenCandidates'])) {
        return [];
    }

    $logProbs = [];
    foreach ($candidate['logprobsResult']['chosenCandidates'] as $tokenData) {
        if (isset($tokenData['logProbability'])) {
            $logProbs[] = (float) $tokenData['logProbability'];
        }
    }
    return $logProbs;
}
```
- Extracts from `candidates[0].logprobsResult.chosenCandidates`
- Returns `array<float>` of log probabilities
- Has TODO: "should be removed if customer isn't moving to Vertex AI"

**2. ConfidenceCalculator::calculateLogProbConfidence()** (`src/Service/Analysis/ConfidenceCalculator.php:411-434`)
```php
private function calculateLogProbConfidence(array $logProbs): float
{
    if (empty($logProbs)) {
        return 0.5;
    }
    $probs = array_map('exp', $logProbs);  // Convert log probs to probabilities
    $avgConf = array_sum($probs) / count($probs);
    $minConf = min($probs);
    return ($avgConf * 0.7) + ($minConf * 0.3);  // Hybrid scoring
}
```
- Uses hybrid scoring: 70% average + 30% minimum ("weakest link")
- Converts log probs via `exp()` function

**3. ConfidenceCalculator::calculate()** (`src/Service/Analysis/ConfidenceCalculator.php:39-71`)
```php
public function calculate(
    AnalysisResultDTO $dto,
    array $qualitySignals = [],
    array $rawLogProbs = []        // ← Already accepts log probs!
): ConfidenceResult {
    // ...
    if (!empty($rawLogProbs)) {
        $modelIntrinsicScore = $this->calculateLogProbConfidence($rawLogProbs);
        $geminiScore = ($modelIntrinsicScore * 0.6) + ($heuristicScore * 0.4);
    } else {
        $geminiScore = $heuristicScore;  // ← Currently always takes this path
    }
}
```
- Already accepts `array $rawLogProbs = []` parameter
- Blending logic implemented: 60% model intrinsic + 40% heuristic
- **Currently never receives log probs** (always empty array)

---

## Current Implementation (AI Studio)

```php
// Authentication: API Key in header
'headers' => [
    'Content-Type' => 'application/json',
    'x-goog-api-key' => $apiConfig->apiKey,
]

// URL Structure
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
```

**Request Body:**
```json
{
  "system_instruction": {
    "parts": [{ "text": "You are an artwork analyst..." }]
  },
  "contents": [{
    "parts": [
      { "text": "Analyze the following images..." },
      { "inline_data": { "mime_type": "image/jpeg", "data": "<base64>" } }
    ]
  }],
  "generationConfig": {
    "responseMimeType": "application/json",
    "responseSchema": { /* schema */ },
    "mediaResolution": "MEDIA_RESOLUTION_HIGH",
    "thinkingConfig": { "thinkingBudget": -1 }
  }
}
```

---

## Vertex AI API Structure

**Important:** Vertex AI uses OAuth2 authentication, NOT API keys.

**URL Structure:**
```
https://{LOCATION}-aiplatform.googleapis.com/v1/projects/{PROJECT_ID}/locations/{LOCATION}/publishers/google/models/{MODEL_ID}:generateContent
```

**Example:**
```
https://europe-west4-aiplatform.googleapis.com/v1/projects/my-project-123/locations/europe-west4/publishers/google/models/gemini-2.5-flash:generateContent
```

**Request Body (with logprobs):**
```json
{
  "systemInstruction": {
    "role": "user",
    "parts": [{ "text": "You are an artwork analyst..." }]
  },
  "contents": [{
    "role": "user",
    "parts": [
      { "text": "Analyze the following images..." },
      { "inlineData": { "mimeType": "image/jpeg", "data": "<base64>" } }
    ]
  }],
  "generationConfig": {
    "responseMimeType": "application/json",
    "responseSchema": { /* schema */ },
    "mediaResolution": "HIGH",
    "thinkingConfig": { "thinkingBudget": -1 },
    "responseLogprobs": true,
    "logprobs": 5
  }
}
```

---

## Key API Differences

| Field | AI Studio | Vertex AI |
|-------|-----------|-----------|
| Auth Header | `x-goog-api-key: {key}` | `Authorization: Bearer {token}` |
| Base URL | `generativelanguage.googleapis.com` | `{location}-aiplatform.googleapis.com` |
| System Instruction | `system_instruction` | `systemInstruction` |
| Inline Data | `inline_data` | `inlineData` |
| MIME Type | `mime_type` | `mimeType` |
| Media Resolution | `MEDIA_RESOLUTION_HIGH` | `HIGH` |
| Response Logprobs | Not supported | `responseLogprobs: true` |

---

## Model & Version Handling Differences

Understanding URL structure differences is critical for the migration:

### AI Studio (Current)

```
URL Pattern: {baseUrl}/{version}/models/{model}:generateContent
Example:     generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent
```

- Version in URL path (`v1beta`)
- Model name is simple: `gemini-2.5-flash`
- Current `buildModelUrl()` constructs: `{baseUrl}/{version}/models/{model}`

### Vertex AI

```
URL Pattern: {location}-aiplatform.googleapis.com/v1/projects/{projectId}/locations/{location}/publishers/google/models/{model}:generateContent
Example:     europe-west4-aiplatform.googleapis.com/v1/projects/my-project/locations/europe-west4/publishers/google/models/gemini-1.5-flash-001:generateContent
```

- Version baked into model name (`gemini-1.5-flash-001`)
- URL version is always `v1`
- Publisher path required: `publishers/google/models/`
- Project ID and location embedded in URL

### Config Field Behavior

Model names are admin-configured via config fields. When switching to Vertex AI:
1. Admin toggles `useVertexAi` to true
2. Admin updates model field to Vertex AI model name (e.g., `gemini-1.5-flash-001`)
3. Admin provides GCP Project ID, Location, and Service Account JSON
4. No automatic model name mapping needed

---

## Integration Point: AnalysisMapper

The missing link for LogProbs is in `AnalysisMapper::mapToEntityData()`.

**File:** `src/Service/Analysis/AnalysisMapper.php`
**Method:** `mapToEntityData()` (line 83)

### Current Code (LogProbs NOT passed)

```php
// Line 83 - Currently calls without log probs
$confidenceResult = $this->confidenceCalculator->calculate($dto);
```

### Required Change

```php
// Extract log probs from raw API response (if Vertex AI enabled)
$logProbs = $this->responseParser->extractLogProbs($rawApiResponse);

// Pass log probs to confidence calculator
$confidenceResult = $this->confidenceCalculator->calculate($dto, [], $logProbs);
```

### Implementation Options

**Option A: Pass raw response to AnalysisMapper**
- Modify `mapToEntityData()` signature to accept raw API response
- Extract log probs within the mapper

**Option B: Extract earlier in pipeline**
- Extract log probs in `GeminiClient` or `AnalysisOrchestrator`
- Pass extracted log probs through to `AnalysisMapper`

---

## Migration Requirements

### 1. config.xml - Add Fields

```xml
<input-field type="bool">
    <name>useVertexAi</name>
    <label>Use Vertex AI</label>
    <defaultValue>false</defaultValue>
</input-field>

<input-field type="text">
    <name>gcpProjectId</name>
    <label>GCP Project ID</label>
</input-field>

<input-field type="single-select">
    <name>gcpLocation</name>
    <label>GCP Location</label>
    <options>
        <option><id>europe-west4</id><name>Europe West 4</name></option>
        <option><id>us-central1</id><name>US Central 1</name></option>
    </options>
</input-field>

<input-field type="textarea">
    <name>serviceAccountJson</name>
    <label>Service Account JSON</label>
</input-field>
```

### 2. ConfigKeys.php - Add Constants

Add to `src/Config/ConfigKeys.php`:

```php
// Vertex AI Configuration
public const string USE_VERTEX_AI = self::PREFIX . 'useVertexAi';
public const string GCP_PROJECT_ID = self::PREFIX . 'gcpProjectId';
public const string GCP_LOCATION = self::PREFIX . 'gcpLocation';
public const string SERVICE_ACCOUNT_JSON = self::PREFIX . 'serviceAccountJson';

// LogProbs Configuration
public const string ENABLE_LOGPROBS = self::PREFIX . 'enableLogprobs';
public const string LOGPROBS_COUNT = self::PREFIX . 'logprobsCount';
```

### 3. ApiConfiguration.php - Complete Refactor

The class needs significant changes to support dual-platform URL building:

```php
readonly class ApiConfiguration
{
    public function __construct(
        // Existing
        public string $apiKey,
        public string $apiModel,
        public string $apiBaseUrl,
        public string $apiVersion,
        public string $imageGenerationModel,
        // New for Vertex AI
        public bool $useVertexAi = false,
        public ?string $gcpProjectId = null,
        public ?string $gcpLocation = null,
        public ?string $serviceAccountJson = null,
    ) {}

    public function isConfigured(): bool
    {
        if ($this->useVertexAi) {
            return !empty($this->gcpProjectId)
                && !empty($this->gcpLocation)
                && !empty($this->serviceAccountJson);
        }
        return !empty($this->apiKey) && !empty($this->apiModel);
    }

    public function getAnalysisUrl(): string
    {
        return $this->buildModelUrl($this->apiModel);
    }

    public function getImageGenerationUrl(): string
    {
        return $this->buildModelUrl($this->imageGenerationModel);
    }

    private function buildModelUrl(string $model): string
    {
        if ($this->useVertexAi) {
            return $this->buildVertexAiUrl($model);
        }
        return $this->buildAiStudioUrl($model);
    }

    private function buildAiStudioUrl(string $model): string
    {
        $baseUrl = rtrim($this->apiBaseUrl, '/');
        $version = ltrim($this->apiVersion, '/');
        return "{$baseUrl}/{$version}/models/{$model}:generateContent";
    }

    private function buildVertexAiUrl(string $model): string
    {
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->gcpLocation,
            $this->gcpProjectId,
            $this->gcpLocation,
            $model
        );
    }
}
```

### 4. GeminiClient.php - OAuth2 Token Management

```php
use Google\Auth\Credentials\ServiceAccountCredentials;

private ?string $cachedToken = null;
private ?int $tokenExpiry = null;

private function getVertexAiAccessToken(ApiConfiguration $config): string
{
    if ($this->cachedToken && time() < ($this->tokenExpiry - 300)) {
        return $this->cachedToken;
    }

    $scopes = ['https://www.googleapis.com/auth/cloud-platform'];

    $credentials = new ServiceAccountCredentials(
        $scopes,
        json_decode($config->serviceAccountJson, true)
    );

    $token = $credentials->fetchAuthToken();
    $this->cachedToken = $token['access_token'];
    $this->tokenExpiry = time() + ($token['expires_in'] ?? 3600);

    return $this->cachedToken;
}
```

### 5. AnalysisRequest.php - Platform-Specific Payload

```php
public function toApiPayload(bool $useVertexAi = false): array
{
    // Field naming differs
    if ($useVertexAi) {
        $imageData = ['inlineData' => ['mimeType' => $mime, 'data' => $base64]];
        $systemKey = 'systemInstruction';
        $mediaResolution = 'HIGH';
    } else {
        $imageData = ['inline_data' => ['mime_type' => $mime, 'data' => $base64]];
        $systemKey = 'system_instruction';
        $mediaResolution = 'MEDIA_RESOLUTION_HIGH';
    }

    $generationConfig = [/*...*/];

    if ($useVertexAi) {
        $generationConfig['responseLogprobs'] = true;
        $generationConfig['logprobs'] = 5;
    }

    // ...
}
```

### 6. GeminiResponseParser.php - Logprobs Parsing

> **Note:** `extractLogProbs()` is already implemented (see "Current Implementation Status" above). This section shows an alternative structure if the existing implementation needs updating.

```php
public function parseLogprobs(array $response): ?array
{
    if (!isset($response['candidates'][0]['logprobsResult'])) {
        return null;
    }

    $tokenProbabilities = [];
    foreach ($response['candidates'][0]['logprobsResult']['topCandidates'] as $position) {
        foreach ($position['candidates'] ?? [] as $candidate) {
            $tokenProbabilities[] = [
                'token' => $candidate['token'] ?? '',
                'logProbability' => $candidate['logProbability'] ?? 0,
                'probability' => exp($candidate['logProbability'] ?? 0),
            ];
        }
    }
    return $tokenProbabilities;
}
```

### 7. ConfidenceCalculator.php - Logprobs Integration

> **Note:** `calculate()` already accepts `$rawLogProbs` and `calculateLogProbConfidence()` already exists (see "Current Implementation Status" above). The integration is already complete - just needs to be wired up at the AnalysisMapper level.

```php
public function calculateConfidence(AnalysisResultDTO $result, ?array $logprobs = null): ConfidenceResult
{
    $heuristicResult = $this->calculateHeuristicConfidence($result);

    if ($logprobs !== null) {
        $logprobsConfidence = $this->calculateLogprobsConfidence($logprobs);
        // 60% heuristic, 40% logprobs
        $combinedScore = ($heuristicResult->score * 0.6) + ($logprobsConfidence * 0.4);
        return new ConfidenceResult($combinedScore, /*...*/);
    }

    return $heuristicResult;
}

private function calculateLogprobsConfidence(array $logprobs): float
{
    $totalProbability = array_sum(array_column($logprobs, 'probability'));
    $avgProbability = $totalProbability / count($logprobs);
    return max(0, min(1, ($avgProbability - 0.5) * 2));
}
```

### 8. Composer Dependency

```bash
composer require google/auth:^1.0
```

---

## Response with Logprobs

Vertex AI response includes logprobs when requested:

```json
{
  "candidates": [{
    "content": {
      "parts": [{ "text": "{\"analyses\": [...]}" }]
    },
    "logprobsResult": {
      "topCandidates": [
        {
          "candidates": [
            { "token": "{", "logProbability": -0.001 },
            { "token": " {", "logProbability": -7.5 }
          ]
        }
      ]
    }
  }]
}
```

---

## Logprobs for Confidence

Currently, confidence uses heuristics (content length, generic patterns). With logprobs:

1. **Token-Level Confidence:** See model certainty per word
2. **Aggregate Confidence:** Average probabilities for overall certainty
3. **Uncertainty Detection:** Low probability = model uncertainty
4. **Better Auto-Approval:** More accurate threshold for automatic approval

---

## Useful Resources

- [Vertex AI Model Tuning](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/models/tune-models)
- [Vertex AI Pricing](https://cloud.google.com/vertex-ai/generative-ai/pricing)
- [AI Studio Pricing](https://ai.google.dev/gemini-api/docs/pricing)

---

*Last Updated: December 17, 2025*
