# Language Flag Images

This directory contains flag images for different languages used in the language switcher.

## Required Flag Images

The language switcher expects flag images in the following format:
- `{language_code}.png` (e.g., `en.png`, `es.png`, `fr.png`)

## Default Images

If a language doesn't have a specific flag image, the system will fall back to:
- `en.png` for English
- A default flag icon

## Supported Languages

Common language codes that should have flag images:
- `en` - English (United States flag)
- `es` - Spanish (Spain flag)
- `fr` - French (France flag)
- `de` - German (Germany flag)
- `it` - Italian (Italy flag)
- `pt` - Portuguese (Portugal flag)
- `ru` - Russian (Russia flag)
- `zh` - Chinese (China flag)
- `ja` - Japanese (Japan flag)
- `ko` - Korean (South Korea flag)
- `ar` - Arabic (Saudi Arabia flag)
- `hi` - Hindi (India flag)

## Image Specifications

- Format: PNG
- Size: 20x15 pixels (recommended)
- Style: Flag icons or country flags
- Background: Transparent or white

## Adding New Languages

When adding a new language to the system:
1. Add the language to the database via the admin panel
2. Add the corresponding flag image to this directory
3. The language switcher will automatically pick up the new language and flag
