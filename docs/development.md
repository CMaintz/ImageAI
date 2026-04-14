# CMaintzImageAi - Development & TODOs

> **Navigation:** [Overview](./overview.md) | [Architecture](./architecture.md) | [API Integration](./api-integration.md) | [Workflows](./workflows.md) | [Admin UI](./admin-ui.md) | **Development**

## Console Commands

```bash
# Analyze all unanalyzed products
bin/console image-ai:analyze-products

# With options
bin/console image-ai:analyze-products --batch-size=50
bin/console image-ai:analyze-products --force  # Re-analyze already analyzed

# Dev commands (remove post-testing)
bin/console illux:dev:generate-test-products
bin/console illux:dev:remove-test-products
bin/console illux:dev:cleanup-test-media
bin/console illux:dev:install-environment-images
bin/console illux:dev:diagnose-test-products
```

---

## Testing Considerations

1. **API Mocking:** Use a mock HTTP client for unit tests
2. **Batch Testing:** Test with varying batch sizes (1, 6, 30, 100)
3. **Confidence Scoring:** Verify penalty calculations with known inputs
4. **Translation Testing:** Ensure all configured languages receive content
5. **Error Handling:** Test API failures, timeouts, malformed responses

---

## Known Limitations

1. **No Real-Time Progress:** Frontend polls for updates; no WebSocket/SSE
2. **Frame Resolver Incomplete:** Frame corner image resolver needs testing
3. **Single Tenant:** No multi-tenant/multi-shop isolation for API keys
4. **Language Hardcoding:** Language codes are hardcoded in some places

---

## WexoProductComponents Integration

The plugin integrates with WexoProductComponents to get frame reference images for composition.

### FrameCornerImageResolver

**Purpose:** Fetch frame corner images from product component mappings.

**How It Works:**
1. Load product with component mappings
2. Find mapping where component type `isFrame = true`
3. Get frame images (top, bottom, left, right corners)
4. Return as base64 for composition request

**Note:** This integration needs testing and may not work in all cases.

---

## TODOs

### High Priority

#### 1. Frame Image Resolver Testing
**Locations:** `CompositionOrchestrator.php:119`, `FrameCornerImageResolver.php:30`

**Issue:** The frame corner image resolver was never fully tested.

**Why It Matters:** Composition quality depends on accurate frame representation.

**Resolution:**
1. Create test products with known frame components
2. Verify frame images load from WexoProductComponents
3. Test composition with and without frame references
4. Add integration tests

---

#### 2. Response Validation Completion
**Location:** `GeminiResponseParser.php:149`

**Issue:** Validation doesn't check if SEO data/description are present when enabled.

**Why It Matters:** Silent failures lead to incomplete products.

**Resolution:**
```php
if ($config->getContentConfig()->includeSeoAnalysis) {
    if (empty($analysis['metaData'])) {
        throw new ValidationException('SEO data missing');
    }
}
```

---

### Medium Priority

#### 3. Thinking Budget Testing
**Location:** `AnalysisRequest.php:54`

**Issue:** Uses unlimited thinking (`thinkingBudget: -1`). No quality comparison done.

**Resolution:** Run comparison tests with budgets: -1, 0, 100, 500, 1000

---

#### 4. Conditional Schema Fields
**Location:** `BatchAnalysisPromptBuilder.php:290`

**Issue:** Schema always includes all fields even when features are disabled.

**Resolution:** Conditionally add schema fields based on config.

---

#### 5. Streaming to Frontend
**Location:** `GeminiClient.php:133`

**Issue:** Concurrent composition results aren't sent to frontend in real-time.

**Resolution Options:**
- Long polling endpoint
- Server-Sent Events (SSE)
- WebSocket connection

---

#### 6. Scene Image Data Cleanup
**Location:** `SceneImageApprovalService.php:148`

**Issue:** After approval, large image blobs remain in pending table.

**Resolution:** Delete blob after moving to media library.

---

### Low Priority

#### 7. Dev Cleanup
**Location:** `TestProductInstaller.php:49`, `Command\Dev/` folder

**Issue:** Remove test product installer & dev commands after development.

---

#### 8. Twig Image URL Implementation
**Locations:** `AiCompositorTwigExtension.php:247`, `:261`

**Issue:** Image URL retrieval returns null instead of actual URLs.

---

#### 9. Composition Prompt Improvements
**Location:** `CompositionPromptBuilder.php:249`

**Issue:** Some prompt sections may need refinement.

---

#### 10. Scene Prompt Focus Settings
**Location:** `ScenePromptBuilder.php:198`

**Issue:** Focus settings (sharp vs soft) may not be optimal.

---

#### 11. JS Promise Handling
**Location:** `ai-analysis-api.service.js:244`

**Issue:** Promise returned by poll function is ignored.

---

#### 12. UI Button Placement
**Location:** `ai-image-tools-properties.html.twig:74`

**Issue:** Button should be in smart bar.

---

#### 13. Storefront Error Handling
**Location:** `ai-compositor.plugin.ts:715`

**Issue:** No error handling or user feedback for composition failures.

---

#### 14. GraphicalAssistance Integration
**Location:** `ai-compositor.plugin.ts:405`

**Issue:** User uploaded image from GraphicalAssistance not implemented.

---

#### 15. Vertex AI Migration Cleanup
**Location:** `GeminiResponseParser.php:114`

**Issue:** Code note about removing section if not moving to Vertex AI.

---

*Last Updated: December 2025*
