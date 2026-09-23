# Changelog

## v1.2.2 - 2026-09-23

### Changed

- `CmsPagesDataSource` calls `RichEditorExtension::renderField()` directly instead of compiling a Twig template
  around the `monsieurbiz_richeditor_render_field` filter. Same output; a shop without the rich-editor plugin now
  fails at container build instead of at sync time.

### BC breaks

- `CmsPagesDataSource::__construct()` — the 6th parameter added in v1.2.1 is now
  `MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension $richEditor`, not `Twig\Environment`.
  Autowired installations need no change.

## v1.2.1 - 2026-09-23

### Fixed

- `cms_pages` indexed MonsieurBiz rich-editor pages as their raw JSON (`[{"code":"monsieurbiz.html","data":…}]`).
  Content now renders through the plugin's own `monsieurbiz_richeditor_render_field` filter before text extraction,
  so the chatbot reads the page text. Plain-HTML content is unchanged. **Re-sync the `cms_pages` source** after
  updating. A page whose element fails to render is logged and skipped, like a page whose text conversion fails.

### BC breaks

- `CmsPagesDataSource::__construct()` — new 6th parameter `Twig\Environment $twig`.
  Autowired installations need no change; only code constructing the data source by hand is affected.

## Unreleased — taxon roots

### BC breaks

- `CategoriesDataSource::__construct()` — new 5th parameter `ChannelTaxonRootsInterface $channelTaxonRoots`.
  Autowired installations need no change; only code constructing the data source by hand is affected.

### Added

- `ChannelTaxonRootsInterface` — decides which taxon trees a channel indexes when it names no menu taxon.
  The shipped `ChannelTaxonRoots` keeps today's behaviour: one tree is used, several raise
  `AmbiguousChannelTaxonTreeException` (HTTP 409) rather than publishing another channel's categories.
  A shop whose channels deliberately **share** one taxonomy — no per-channel root taxons — now has a seam:
  alias the interface to its own implementation returning every root, and `categories` indexes instead of
  refusing. Before this, that shop could only be fixed by giving every channel a menu taxon.

## Unreleased

### Added

- `ChannelResolver::getEnabledChannels()` — every enabled channel of the shop.
- `SyliusLocaleContext::getAllChannelLocales()` — the locales of all enabled channels, de-duplicated. Implements the
  new `ChatbotLocaleContextInterface` method and needs `fluffydiscord/honkers-sdk ^1.1`.

### Changed

- `GET /chatbot/v1/sources` advertises the locales of **every** enabled channel instead of only those of the channel
  the request host resolved to. The source list carries no channel, so a backend driving several channels through one
  host learned only the host channel's locales and never ingested the rest. Reads are unaffected:
  `GET /chatbot/v1/sources/{name}` still applies `?channel=` and validates the locale against that channel.

## v1.3.1 - 2026-09-16

### Changed

- `ToolChoice` now extends `Assert\Choice` and `ToolChoiceValidator` extends `ChoiceValidator`: the loaded values are
  checked by Symfony's own validator. New options `multiple`, `min`, `max`, `match` and the matching messages.
  Violations use `Choice`'s codes; the `ToolChoice::NO_SUCH_CHOICE_ERROR` code from v1.3.0 is replaced by the
  inherited `Choice::NO_SUCH_CHOICE_ERROR`.
- `multiple: true` on an `array` property is published as `items.enum` with `minItems`/`maxItems`.
- A `ToolChoice` property typed anything but `string` (or `array` with `multiple`) fails the tool list with a
  `LogicException` instead of producing a schema no value can satisfy.
- A loader that returns no choices leaves the argument out of the schema instead of publishing an empty `enum`.
- Loaded choices are de-duplicated.

## v1.3.0 - 2026-09-16

### BC breaks

- `ArgumentsSchemaGenerator::__construct()` now requires `ToolChoiceLoaderRegistry $choiceLoaderRegistry`. Autowired
  installations need no change; only code constructing the generator by hand is affected.

### Added

- `#[ToolChoice(loader: ...)]` constraint and `ToolChoiceLoaderInterface` — tool arguments whose allowed values are
  loaded at runtime (e.g. from the database). The loader's values are published as the argument's schema `enum` and
  enforced when the tool is called.

## v1.2.0 - 2026-09-15

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
