# Langbly Translator for TMGMT

Drupal module that provides [Langbly](https://langbly.com) as a machine translation provider for the [Translation Management Tool (TMGMT)](https://www.drupal.org/project/tmgmt). Translate your Drupal content between 100+ languages.

## Requirements

- Drupal 10 or 11
- [TMGMT](https://www.drupal.org/project/tmgmt) module installed and enabled
- A [Langbly API key](https://langbly.com/dashboard/api-keys) (free tier: 500K characters/month)

## Installation

### Via Composer (recommended)

```bash
composer require langbly/langbly_translator
drush en langbly_translator
```

### Manual

1. Download this module to `/modules/contrib/langbly_translator/`
2. Enable the module at `/admin/modules` or via Drush: `drush en langbly_translator`

## Configuration

1. Go to **Administration > Translation Management > Translators** (`/admin/tmgmt/translators`)
2. Click **Add translator**
3. Select **Langbly** as the translator plugin
4. Enter your Langbly API key
5. Save the translator

The API key is validated on save. If the key is invalid, you will see an error message.

## Usage

Once configured, Langbly will be available as a translation provider in TMGMT.

1. Go to **Translation > Sources** and select content to translate
2. Choose **Langbly** as the translator
3. Submit the translation job
4. Translations are returned immediately (synchronous)

## Features

- Translates to 100+ languages
- Batch processing with automatic chunking (respects API limits)
- HTML format support (preserves markup during translation)
- Language code mapping (Drupal codes to ISO 639-1)
- API key validation on configuration save
- Detailed error logging via Drupal's watchdog

## Supported Languages

Langbly supports all major languages including: English, Dutch, French, German, Spanish, Italian, Portuguese, Japanese, Chinese, Korean, Arabic, Russian, and 90+ more.

## API

This module uses the [Langbly Translation API](https://docs.langbly.com), which is compatible with the Google Translate v2 API format. This means switching from Google Translate to Langbly requires no changes to your API integration.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
