# CMaintzImageAi - Workflows

> **Navigation:** [Overview](./overview.md) | [Architecture](./architecture.md) | [API Integration](./api-integration.md) | **Workflows** | [Admin UI](./admin-ui.md) | [Development](./development.md)

## Analysis Workflow

```
┌──────────────────────────────────────────────────────────────────────────┐
│  TRIGGER: Admin clicks "Analyze All" or Scheduled Task runs             │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  BatchJobService::createAnalysisJob()                                   │
│    ├── Create AiBatchJob (status: Queued)                               │
│    ├── Create AiAnalysisResult records (status: Processing)  ◄── Upfront│
│    └── Dispatch AnalyzeBatchMessage                                     │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  AnalyzeBatchHandler::handleMessage()  [ASYNC - Queue Worker]           │
│    └── For each chunk of 30 products:                                   │
│          └── AnalysisOrchestrator::processSpecificProducts()            │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  AnalysisOrchestrator                                                   │
│    ├── ProductImageResolver: Fetch images, convert to base64            │
│    ├── AnalysisRequestFactory: Build request with prompt + schema       │
│    └── GeminiClient::analyzeBatch(): Send to API (6 products/request)   │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  GeminiResponseParser::parseBatchAnalysisResponse()                     │
│    └── Convert JSON to AnalysisResultDTO objects                        │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  AnalysisPersistenceService::persistResults()                           │
│    ├── ConfidenceCalculator: Calculate quality score                    │
│    ├── Update AiAnalysisResult with translations                        │
│    └── Set status: PendingReview or AutoApproved (based on confidence)  │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  IF status = PendingReview:                                             │
│    └── Admin reviews in dashboard, clicks Approve/Reject                │
│                                                                         │
│  IF status = AutoApproved (and approval workflow disabled):             │
│    └── AnalysisApprovalService::approveResults() called automatically   │
└────────────────────────────┬─────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  AnalysisApprovalService::approveResults()                              │
│    ├── ProductUpdateAssembler: Build update payload                     │
│    ├── Update product: translations, propertyIds                        │
│    └── Set analysis status: Approved                                    │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Composition Workflow

Takes existing artwork + existing environment image → blends them together.

```
User selects artwork + environment + frame options
                             │
                             ▼
CompositionOrchestrator::compose()
    ├── ProductImageResolver: Get artwork image
    ├── MediaFileReader: Get environment scene image
    ├── FrameCornerImageResolver: Get frame reference (if available)
    ├── CompositionPromptBuilder: Build detailed prompt
    └── GeminiClient::compositeImage(): Send to image generation API
                             │
                             ▼
Return binary image data for display/storage
```

---

## Scene Generation Workflow

AI creates entirely new environment images (no input artwork needed).

```
Admin opens Scene Generation UI
    │
    ├── Select scene type (living room, bedroom, office, etc.)
    ├── Configure options:
    │     ├── Interior style (scandinavian, industrial, modern, etc.)
    │     ├── Camera perspective (eye-level, corner view, etc.)
    │     ├── Camera lens (35mm, 50mm, 85mm)
    │     ├── Lighting (natural, evening, studio, etc.)
    │     ├── Mood (calm, dramatic, luxurious, etc.)
    │     └── Color palette (neutral, warm, earth tones, etc.)
    │
    ▼
SceneGenerationController::generateSceneImages()
    │
    ├── Creates GenerateSceneMessage for queue
    └── Returns job ID for frontend polling
                             │
                             ▼
GenerateSceneHandler::__invoke()
    │
    ├── SceneGenerationOrchestrator::generateSceneImages()
    │     ├── Load AiSceneGenerationConfig for product
    │     ├── For each selected scene type:
    │     │     └── ScenePromptBuilder::buildPrompt()
    │     │           ├── Camera/lens settings
    │     │           ├── Interior style description
    │     │           ├── Lighting direction
    │     │           ├── Mood atmosphere
    │     │           └── Wall placeholder requirements
    │     │
    │     └── GeminiClient::generateImages()
    │           └── Parallel requests to Gemini 2.5 Flash Image
    │
    └── PendingSceneImagePersistenceService::persist()
          └── Store in ai_pending_scene_image (status: pending)
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────────────┐
│  Admin views pending images in approval UI                              │
│    ├── Preview generated environments                                   │
│    ├── Approve: SceneImageApprovalService creates media entity          │
│    └── Reject: Mark record as rejected (keeps for analysis)             │
└──────────────────────────────────────────────────────────────────────────┘
```

**Key Difference:**
- **Composition**: Takes existing artwork + existing environment → blends them
- **Scene Generation**: AI creates entirely new environment images

---

## Prompt Building

The plugin constructs detailed prompts to guide Gemini's analysis.

### SystemInstructionBuilder

Sets behavioral guidelines:
- You are analyzing artwork for an e-commerce store
- Generate content suitable for product listings
- Use the specified tone (professional/casual/artistic)
- Only suggest properties from the whitelist

### BatchAnalysisPromptBuilder

Builds the main analysis prompt:

```
Analyze the following artwork images. For each image:

1. Generate SEO metadata in these languages: da-DK, en-GB, nn-NO, sv-SE
   - Meta title (max 60 characters)
   - Meta description (max 155 characters)
   - SEO keywords (5 keywords)

2. Generate product description (max 500 characters) per language

3. Suggest product properties from these options only:
   - Style: [Realism, Impressionism, Minimalism, ...]
   - Subject: [Abstract, Animals, Architecture, ...]
   - Mood: [Calm, Energetic, Romantic, ...]
   ...

Images are labeled with productId and analysisResultId.
Include these IDs in your response for each analysis.
```

### CompositionPromptBuilder

Most detailed builder (357 lines). Guides AI to composite artwork into scenes:
- Where to place the artwork on the wall
- How to match lighting and shadows
- How to preserve aspect ratio
- How to use frame reference images
- Wall placement validation rules

### ScenePromptBuilder

Uses photographic language for scene generation:
- Camera lens specifications (24mm, 35mm, 50mm, 85mm)
- Camera angles (eye level, low angle, high angle)
- Interior style descriptions (modern minimalist, Scandinavian, etc.)
- Lighting direction (natural, warm ambient, dramatic)
- Mood atmosphere (calm, energetic, professional)
- Color palette guidance

---

*Last Updated: December 2025*
