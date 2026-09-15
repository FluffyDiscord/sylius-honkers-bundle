# Changelog

## Unreleased

### Added

- `widget.channel_site_keys` — per-channel site keys (`{ <channel code>: <site key> }`, literal or `%env()%`) for shops
  where each channel is its own site on the backend. `widget.site_key` stays the fallback.

  ```yaml
  fluffy_discord_sylius_chatbot:
      widget:
          site_key: '%env(CHATBOT_SITE_KEY)%'
          channel_site_keys:
              CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
              SK_WEB: '%env(CHATBOT_SITE_KEY_SK)%'
  ```

- Catalog change notifications go to the site of every enabled channel serving the changed locale, one request per
  site key per `(source, locale)`; a channel without a resolvable key is skipped with a warning.
- `notify-all` announces to the resolved channel's site and aborts when that channel has no site key.
- The widget's `site-key` is the current channel's key, resolved at render time by the new
  `fluffydiscord_chatbot_site_key()` Twig function — also when the template is included directly.

### Changed

- `CatalogChangeNotifier` takes a `SiteKeyResolver` instead of the `widget.site_key` string, and `notify()` accepts an
  optional channel code. Only relevant when constructing or extending the notifier manually.
- Notification log targets read `<source> / <locale> / <site key>`.
