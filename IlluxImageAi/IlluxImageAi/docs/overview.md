# IlluxImageAi - Overview

> **Navigation:** **Overview** | [Architecture](./architecture.md) | [API Integration](./api-integration.md) | [Workflows](./workflows.md) | [Admin UI](./admin-ui.md) | [Development](./development.md)

## Quick Reference

| Attribute | Value |
|-----------|-------|
| **Purpose** | AI-powered image analysis, SEO generation, and scene composition for artwork products |
| **Location** | `/custom/static-plugins/IlluxImageAi` |
| **Version** | 3.2.0 |
| **Compatibility** | Shopware 6.6 - 6.7 |
| **Developer** | WEXO A/S |
| **License** | Proprietary |
| **External API** | Google Gemini (AI Studio) |

### Key Files
| File | Purpose |
|------|---------|
| `src/IlluxImageAi.php` | Plugin entry point (install/uninstall) |
| `src/Api/Gemini/GeminiClient.php` | Direct Gemini API communication |
| `src/Orchestrator/AnalysisOrchestrator.php` | Batch analysis workflow coordination |
| `src/Orchestrator/CompositionOrchestrator.php` | Artwork-into-scene composition |
| `src/Orchestrator/SceneGenerationOrchestrator.php` | AI environment scene generation |
| `src/Controller/Administration/AnalysisController.php` | Analysis API (11 endpoints) |
| `src/Controller/Administration/SceneGenerationController.php` | Scene generation API (7 endpoints) |
| `src/Service/Analysis/ConfidenceCalculator.php` | Heuristic quality scoring algorithm |
| `src/Service/Approval/AnalysisApprovalService.php` | Transaction-safe approval workflow |
| `src/Builder/Prompt/BatchAnalysisPromptBuilder.php` | Analysis prompts with property constraints |
| `src/Builder/Prompt/CompositionPromptBuilder.php` | Detailed composition prompts (357 lines) |
| `src/Builder/Prompt/ScenePromptBuilder.php` | Scene generation prompts (photographic language) |
| `src/Builder/Prompt/SystemInstructionBuilder.php` | LLM behavioral guidelines |
| `src/Config/IlluxConfiguration.php` | Central typed configuration service |

### Database Tables
| Table | Purpose |
|-------|---------|
| `ai_analysis_result` | Stores analysis results per product |
| `ai_analysis_result_translation` | Multi-language metadata (title, description, keywords) |
| `ai_batch_job` | Tracks batch processing jobs |
| `ai_pending_scene_image` | Scene images awaiting approval |
| `ai_scene_generation_config` | Scene generation configuration |

### Entry Points
- **Admin UI:** Content menu → AI Image Tools (`/admin#/sw/extension/ai-tools/dashboard`)
- **API:** `/api/_action/illux-ai-tools/*`
- **CLI:** `bin/console illux:analyze-products`
- **Scheduled Task:** `ProductAnalysisTask` (configurable interval)

---

## What This Plugin Does

IlluxImageAi is an AI-powered plugin that automates the tedious work of writing product descriptions, SEO metadata, and assigning properties to artwork products. Instead of manually describing each piece of art, the plugin sends product images to Google's Gemini AI, which analyzes the artwork and returns structured data ready to be applied to the product.

### In Simple Terms

1. **Analyzes Artwork Images**: Send product cover images to AI → get back descriptions, SEO metadata, and suggested properties
2. **Generates Multi-Language Content**: One analysis produces content in Danish, English, Norwegian, and Swedish
3. **Suggests Product Properties**: AI identifies artwork style, mood, subject matter, etc.
4. **Composes Scene Images**: Place artwork into photorealistic room environments
5. **Tracks Time Savings**: Calculates how much manual work the AI has saved

---

## Core Capabilities

### Image Analysis
- **Batch Processing**: Up to 6 products analyzed per API call (not one-by-one)
- **Multi-Language**: Generates content in da-DK, en-GB, nn-NO, sv-SE simultaneously
- **Structured Output**: Returns JSON with enforced schema (no parsing guesswork)
- **Confidence Scoring**: Each result gets a quality score (0-100%) based on content analysis

### Content Generation
- **Meta Title**: SEO-optimized title (max 60 characters)
- **Meta Description**: Search engine description (max 155 characters)
- **SEO Keywords**: 3-5 relevant keywords per language
- **Product Description**: Customer-facing description (max 500 characters)

### Property Suggestions
- **Whitelisted Options**: AI can only suggest from pre-approved property values
- **New Option Proposals**: AI can suggest new property options (requires admin approval)
- **Automatic Assignment**: Approved properties are assigned to products

### Approval Workflow
- **Manual Approval**: Review each analysis before applying (default)
- **Auto-Approval**: Automatically apply high-confidence results
- **Confidence Threshold**: Flag low-confidence results for review even in auto mode

### Scene Composition (Artwork into Existing Scenes)
Takes an existing environment photo and composites the product artwork into it:
- **Artwork Compositing**: AI places product artwork onto wall surfaces in existing room photos
- **Frame Reference**: Uses product frame corner images (from WexoProductComponents) for accurate frame representation
- **Concurrent Processing**: Multiple scenes processed in parallel (non-blocking HTTP)
- **Detailed Prompts**: 357-line CompositionPromptBuilder ensures proper wall placement, lighting integration, perspective matching

### Scene Generation (AI-Generated Environments)
Generates entirely new room environment photos from scratch using AI:
- **Photorealistic Interiors**: Creates room scenes specifically designed for showcasing wall art
- **Configurable Options**: Interior style, camera lens, angle, lighting, mood, color palette, styling
- **Photographic Language**: Prompts use professional photography terminology (follows Gemini best practices)
- **Pending Approval**: Generated images stored in `ai_pending_scene_image` until admin approves
- **Aspect Ratio Options**: 1:1, 16:9, 9:16, 4:3, 3:4
- **Model**: Uses `gemini-2.5-flash-image` for image generation

**Scene Generation Configuration Options:**
| Option | Examples |
|--------|----------|
| Scene Type | Living room, bedroom, office, dining room |
| Interior Style | Modern minimalist, Scandinavian, industrial, bohemian |
| Camera Lens | 24mm wide angle, 35mm standard, 50mm portrait |
| Camera Angle | Eye level, low angle, high angle |
| Lighting | Natural daylight, warm ambient, dramatic shadows |
| Mood | Professional, cozy, energetic, calm |
| Color Palette | Neutral tones, warm earth, cool grays |
| Styling | Minimally furnished, cozy with textiles, gallery-like |

---

## Summary

IlluxImageAi is a comprehensive plugin that automates artwork product enrichment using Google's Gemini AI. Key architectural decisions include:

1. **Batch-First Design:** All processing is batched for efficiency
2. **Confidence Scoring:** Quality scoring system catches low-quality AI outputs
3. **Approval Workflow:** Optional human review before applying changes
4. **Schema Enforcement:** Structured API responses prevent parsing errors
5. **Queue Processing:** Async handling prevents timeouts and enables scaling

**Key Integration Points:**
- **Gemini API:** Direct HTTP communication via GeminiClient
- **WexoProductComponents:** Frame reference images for composition
- **Shopware DAL:** Product updates, translations, properties

**Current Platform:** Google AI Studio (API key authentication)

**Migration Path:** See [Vertex AI Migration](./vertex-ai-migration.md) for OAuth2 authentication and logprobs support.

**For new developers:** Start with understanding the analysis workflow in [Workflows](./workflows.md), then explore the codebase using [Architecture](./architecture.md) as a map.

---

*Last Updated: December 2025*
