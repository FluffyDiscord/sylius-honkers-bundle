# Changelog

## Unreleased

### BC breaks

- `CatalogChangeNotifier::__construct()` — the 3rd parameter is now `SiteKeyResolver $siteKeyResolver`; the
  `string $siteKey` parameter (autowired from `widget.site_key`) is removed.
  Before: `($backendClient, $logger, $backendUrl, $ingestSecret, $siteKey, $environment)`.
  After: `($backendClient, $logger, $siteKeyResolver, $backendUrl, $ingestSecret, $environment)`.
- `CatalogChangeNotifier::notify()` — new 4th parameter `?string $channelCode = null`; subclasses overriding `notify()`
  must add it.
- `NotifyAllCommand::__construct()` — new 5th parameter `SiteKeyResolver $siteKeyResolver`.
- `templates/shop/widget.html.twig` requires the bundle's `fluffydiscord_chatbot_site_key()` Twig function and renders
  nothing when the resolved site key is empty (previously `<ai-chat-widget site-key="">`).

Autowired installations need no change; only code constructing or extending these classes, or rendering the template
in a Twig environment without the bundle, is affected.

### Added

- `widget.channel_site_keys` — per-channel site keys (`{ <channel code>: <site key> }`, literal or `%env()%`) for shops
  where each channel is its own site on the backend. `widget.site_key` stays the fallback and is no longer required
  once a channel key is set.

  ```yaml
  fluffy_discord_sylius_chatbot:
      widget:
          site_key: '%env(CHATBOT_SITE_KEY)%'
          channel_site_keys:
              CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
              SK_WEB: '%env(CHATBOT_SITE_KEY_SK)%'
  ```

- With channel keys set, catalog change notifications go to the site of every enabled channel serving the changed
  locale, one request per site key per `(source, locale)`. An enabled channel serving the locale without a resolvable
  key is skipped with a warning; one mapped to an empty key with a debug record.
- With channel keys set, `notify-all` announces to the resolved channel's site and aborts for a disabled channel or one
  without a site key.
- The widget's `site-key` is the current channel's key, resolved at render time by the new
  `fluffydiscord_chatbot_site_key()` Twig function — also when the template is included directly.
- `twig/twig` (`^2.12 || ^3.3`) is now an explicit requirement.

### Changed

- The missing-configuration key for site keys reads `widget.site_key or widget.channel_site_keys`.
- Notification log targets read `<source> / <locale> / <site key>`.
