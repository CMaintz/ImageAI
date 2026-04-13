# Image AI — Shopware Plugin

A Shopware 6 plugin that automates product enrichment for artwork using Google Gemini. Send product cover images to the AI and get back SEO metadata, product descriptions, and property suggestions — across multiple languages in a single API call.

**Version:** 3.2.1 · **Compatibility:** Shopware 6.6 – 6.7 · **Developer:** [WEXO A/S](https://www.wexo.dk)

---

## Features

- **Batch image analysis** — up to 6 products per API call with retry and exponential backoff
- **Multi-language content** — generates da-DK, en-GB, nn-NO and sv-SE output simultaneously
- **SEO metadata** — meta title, meta description, and keywords per language
- **Product descriptions** — customer-facing copy generated from the product image
- **Property suggestions** — AI assigns or proposes product properties from a whitelisted set
- **Confidence scoring** — heuristic quality check flags low-confidence results for manual review
- **Approval workflow** — manual review or auto-approve mode (configurable)
- **Scene composition** — composite product artwork into existing room environment photos
- **Scene generation** — generate entirely new photorealistic room environments with AI
- **Scheduled analysis** — automatic background processing on a configurable interval

---

## Requirements

- Shopware 6.6 or 6.7
- PHP 8.2+
- A [Google AI Studio](https://aistudio.google.com/) API key (Gemini)

---

## Installation

1. Copy the `ImageAi` folder into `custom/static-plugins/` on your Shopware instance.
2. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate IlluxImageAi
   bin/console cache:clear
   ```

---

## Configuration

In the Shopware admin go to **Extensions → My extensions → Image AI → Configure** and set:

| Setting | Description |
|---|---|
| Gemini API Key | Your Google AI Studio API key |
| Analysis model | Gemini model to use (default: `gemini-2.5-flash`) |
| Languages | Which languages to generate content for |
| Auto-approve | Apply results automatically or hold for review |
| Confidence threshold | Minimum score before a result is flagged for review |
| Scheduled analysis | Enable/disable automatic background analysis |

---

## Usage

**Admin UI:** Extensions menu → AI Image Tools

**CLI:**
```bash
bin/console illux:analyze-products
```

**API:**
```
POST /api/_action/illux-ai-tools/analyze-products
POST /api/_action/illux-ai-tools/analyze-all-products
GET  /api/_action/illux-ai-tools/batch-job/{id}
```

---

## Documentation

Detailed docs are in the [`docs/`](./ImageAi/docs/) folder:

- [Overview](./ImageAi/docs/overview.md)
- [Architecture](./ImageAi/docs/architecture.md)
- [API Integration](./ImageAi/docs/api-integration.md)
- [Workflows](./ImageAi/docs/workflows.md)
- [Admin UI](./ImageAi/docs/admin-ui.md)
- [Development](./ImageAi/docs/development.md)

---

## License

Proprietary — © WEXO A/S
