# Test Images for AI Analysis

Place your test artwork images in this directory to automatically create test products when the plugin is activated.

## Supported Formats

- JPG/JPEG
- PNG
- WEBP

## How It Works

1. Add your test artwork images to this directory
2. Activate the plugin (or re-activate if already installed)
3. Test products will be automatically created with:
   - Product name based on the image filename
   - Product number: `AI-TEST-XXXXXXXX` (generated hash)
   - Associated with "Illux Artwork" property
   - Cover image set to your test image
   - Basic pricing and stock configured
   - Visible in all sales channels

## Example

If you add a file named `abstract-painting.jpg`, it will create a product named "Test: Abstract Painting".

## Uninstalling

When you uninstall the plugin (without keeping user data), all test products will be automatically removed.

## Notes

- The test products use a special tax named "AI Test Product Tax" to make them easy to identify
- Products are only created when the plugin is activated, not on every page load
- If you want to add more test products, deactivate and reactivate the plugin