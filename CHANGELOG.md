# Changelog

## v2.0.1

### Changed

- Saved-entity notifications send a batch once more when the backend asks to retry within 2 s (rate limit), and log a
  warning when it can't queue them for longer. They were dropped without a log before.
- `notify-all` also waits out a `503` (backend can't queue right now) and sends the same batch again.

## v2.0.0

Upgrade steps → [UPGRADE-2.0.md](UPGRADE-2.0.md).

### BC breaks

- Renamed to `fluffydiscord/sylius-honkers-plugin`: namespace `FluffyDiscord\SyliusHonkersPlugin\`, bundle class
  `FluffyDiscordSyliusHonkersPlugin`, config root `fluffy_discord_sylius_honkers_plugin`, template namespace
  `@FluffyDiscordSyliusHonkersPlugin`.
- Requires `fluffydiscord/honkers-sdk` ^2.0 and `fluffydiscord/symfony-honkers-bundle` ^2.0: `api_secret`,
  `ingest_secret` and `widget.site_key` are no longer config options; the credentials come from
  `CHATBOT_API_SECRET`, `CHATBOT_INGEST_SECRET` and `CHATBOT_SITE_KEY`, or from your own provider.
- `ChannelSiteKeyContext` removed. `SiteKeyResolver` keeps only `getSiteKeyRouting()`; `SiteKeyRouting` carries
  `SiteCredentials`. `fluffydiscord_chatbot_site_key()` takes no argument.
- Catalog notifications always go to the enabled channels serving the locale, also without `channel_site_keys`; a
  locale no enabled channel serves is no longer sent. `notify-all` refuses a disabled channel in every setup.
- A site key without an ingest secret still renders the widget; notifications, order reports and `notify-all` skip
  that channel.
- `ChannelCredentialsProviderInterface` must be aliased on Sylius: aliasing only the Symfony bundle's
  `CredentialsProviderInterface` fails the container build.
- `ChannelCredentialsProviderInterface::findForChannel()` and `CatalogChangeNotifier::notify()` take the loaded
  `ChannelInterface` instead of a channel code.
- An own `ChannelCredentialsProviderInterface` gets no channel writer: Connect stores into it only when you alias
  `CredentialsWriterInterface` yourself.

### Added

- Connect from the dashboard. Add `HonkersChannelInterface` + `HonkersChannelTrait` to your `Channel` and migrate:
  Connect generates the secrets, stores them on the channel and the widget, notifications and reports use them at
  once. Unpaired channels keep their configured credentials. Channels on different hosts pair together when every
  hostname is on a verified domain of the website.
- `ChannelCredentialsProviderInterface` — alias it to load the credentials from your own store.
- `widget.defer` (Symfony bundle) — `false` loads `chat.js` without `defer`.
- MIT license, GitHub Actions CI on PHP 8.2 / 8.4 × Sylius 1.14 / 2.2.

## v1.5.1 - 2026-10-07

### Fixed

- Shops on `bitbag/cms-plugin` get their CMS pages into the chatbot again: the `cms_pages` source now reads BitBag
  pages too.

## v1.5.0 - 2026-10-07

### Added

- Orders from chat. Implement `ChatAttributedOrderInterface` with `ChatAttributedOrderTrait` on your `Order`: the
  click ids of the chat links that led to the order are saved with it at checkout, and the admin order detail
  links each one to its conversation in the honkers.dev console. Needs a `chat_click_ids` column migration.

## v1.4.0 - 2026-10-06

### Added

- Chat click tracking. A visitor who lands on a product page through a chat link (`?gooseclid=`) is remembered in
  the session; when an order with that product is placed, the bundle reports the order number and the total of
  those items to the chatbot. Any placed order clears the remembered clicks. Shop checkout only, not API checkout.
- The landing visit itself is reported server-side through `fluffydiscord/symfony-honkers-bundle` v1.2, with the
  site key of the current channel.

### Changed

- Requires `fluffydiscord/honkers-sdk` ^1.2 and `fluffydiscord/symfony-honkers-bundle` ^1.2; declares `symfony/intl`.
- The catalog change notifier no longer logs a warning when `backend_url` or `ingest_secret` is empty; it skips
  silently. A non-https backend outside `dev` is still refused with a warning.
- The site-key and locale contexts are aliased by a compiler pass, so they win whichever order the bundles are
  registered in.

## v1.3.1 - 2026-10-02

### Fixed

- An unset `widget.cdn_url` loads `chat.js` from the honkers.dev CDN
  (`https://honkers.b-cdn.net/widget/v1/chat.js`) instead of `{backend_url}/widget/v1/chat.js`. The shop widget
  markup is now rendered by `fluffydiscord/honkers-sdk`'s `WidgetSnippet`, so it follows the SDK's default.

## v1.3.0 - 2026-09-23

### Added

- Product metadata carries `taxonNames`: the product's taxon names in the requested locale, alongside the existing
  `taxons` codes. The chatbot builds a short search card per product from the title, these names and short
  attributes. Additive; a backend that ignores the key is unaffected. The changed metadata makes the next catalog
  sync rewrite every product once.

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
