# Sylius honkers.dev Bundle

Plug-and-play Sylius wrapper for the honkers.dev chatbot tool-server. Adds the default tools, data
sources and shop widget on top of two lower layers, both pulled in automatically:

- [`fluffydiscord/honkers-sdk`](https://github.com/FluffyDiscord/honkers-sdk) — the
  framework-agnostic core (tools, data sources, JSON-schema, DTOs).
- [`fluffydiscord/symfony-honkers-bundle`](https://github.com/FluffyDiscord/symfony-honkers-bundle) —
  the vanilla Symfony bundle: the `/chatbot/v1` endpoints, security, error format, locale rules, and
  how to write your own tools and sources.

**Read the [Symfony bundle README](https://github.com/FluffyDiscord/symfony-honkers-bundle) for the
tool-server mechanics** — this page covers only the Sylius pieces.

Requires PHP `^8.1` and Sylius `^1.14 || ^2.2 || ^2.3`.

## Requirements

| Package | Constraint |
|---|---|
| `php` | `^8.1` |
| `ext-intl` | `*` |
| `sylius/sylius` | `^1.14 \|\| ^2.2 \|\| ^2.3@alpha` |
| `doctrine/orm` | `^2.20 \|\| ^3.6 \|\| ^4.0` |
| `doctrine/doctrine-bundle` | `^2.13 \|\| ^3.2 \|\| ^4.0` |
| `symfony/*` | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `twig/twig` | `^2.12 \|\| ^3.3` |

The `@alpha` on `sylius/sylius` is only because 2.3 has no stable tag yet (`v2.3.0-ALPHA.1`); it drops
once 2.3 ships stable.

**On Sylius 1.14 the widget uses a deprecated hook.** 1.14 runs on Symfony 6.4 with no Twig Hooks, so
the widget is registered as a `sylius_ui` template block on `sylius.shop.layout.javascripts` — the only
mechanism 1.14 offers, deprecated in that same release. The container build reports
`sylius/ui-bundle` deprecations for it: expected, not a bug. Endpoints, data sources and catalog
notifications are identical on both lines.

## Installation

### 1. Composer

```bash
composer require fluffydiscord/sylius-honkers-bundle
```

### 2. Register the bundles

Register both the vanilla Symfony bundle and this Sylius wrapper in `config/bundles.php`:

```php
FluffyDiscord\HonkersBundle\FluffyDiscordHonkersBundle::class => ['all' => true],
FluffyDiscord\SyliusHonkersBundle\FluffyDiscordSyliusHonkersBundle::class => ['all' => true],
```

### 3. Routes

The routes are owned by the Symfony bundle. `config/routes/fluffy_discord_honkers.yaml`:

```yaml
fluffy_discord_honkers:
    resource: '@FluffyDiscordHonkersBundle/config/routes.php'
```

**Keep the endpoints off the shop's `_locale` prefix** — they live under `/chatbot/v1` on the shop host.

### 4. Configuration

The connection and widget config belong to the Symfony bundle.
`config/packages/fluffy_discord_honkers.yaml`:

```yaml
fluffy_discord_honkers:
    api_secret: '%env(CHATBOT_API_SECRET)%'
    backend_url: '%env(CHATBOT_BACKEND_URL)%'
    ingest_secret: '%env(CHATBOT_INGEST_SECRET)%'
    widget:
        enabled: true
        site_key: '%env(CHATBOT_SITE_KEY)%'
        cdn_url: '%env(CHATBOT_WIDGET_CDN_URL)%'
```

```dotenv
CHATBOT_API_SECRET=change-me
CHATBOT_BACKEND_URL=https://chatbot.example.com
CHATBOT_INGEST_SECRET=change-me
CHATBOT_SITE_KEY=site-key
```

- `backend_url` — the honkers.dev origin, used by the widget's `backend-url` attribute and the catalog
  notifier. **Outside `dev`, a non-`https` `backend_url` is refused and nothing is sent.**
- `ingest_secret` — the shop half of the catalog credential; the backend receives
  `Authorization: Bearer <site_key>.<ingest_secret>`.
- `widget.cdn_url` — where `<script src>` loads `chat.js` from. Set it to the Bunny CDN URL the backend
  publishes to via `chatbot:widget:deploy` (must equal the backend's `BUNNY_CDN_PURGE_URL`). Only the
  script bytes move to the CDN; every API call still goes to `backend_url`. Empty → falls back to
  `{backend_url}/widget/v1/chat.js`.
- `widget.enabled` — must be a literal boolean; the widget hook is registered at build time.

#### One site per channel

**Map channel codes to site keys when each channel is its own site on the backend.**
`config/packages/fluffy_discord_sylius_honkers.yaml`:

```yaml
fluffy_discord_sylius_honkers:
    channel_site_keys:
        CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
        SK_WEB: '%env(CHATBOT_SITE_KEY_SK)%'
        DE_WEB: 'pk_live_de'
```

- A channel's site key is `channel_site_keys[<channel code>]`, else `widget.site_key`. An entry is
  authoritative: a channel mapped to an empty value has no site key and never falls back.
- `widget.site_key` is optional once `channel_site_keys` has an entry; it then only serves unmapped
  channels.
- Keys are channel codes, unnormalized (`cz-web` stays `cz-web`); values are strings, literal or
  `%env()%`.
- With channel keys set, **disabled** channels get no catalog notifications and
  `notify-all --channel=<disabled>` is refused.
- Empty `channel_site_keys` (the default) behaves like a single-site shop: channels are never consulted.

### 5. Security

Add the `chatbot_api` firewall (see the Symfony bundle README) to `config/packages/security.yaml`
**before** the Sylius `shop` firewall — firewalls match in order and `shop` matches `^/`:

```yaml
security:
    firewalls:
        chatbot_api:
            pattern: ^/chatbot/v1
            # ... token handler + failure handler, per the Symfony bundle README
        # shop: ...
```

## Shipped tools

The backend calls these mid-conversation for live data:

- `get_order_status` — order state, payment and shipping state, tracking codes, item count and total;
  requires the order number and the customer e-mail (uniform "not found" otherwise).
- `get_product_availability` — price, currency and stock for up to 20 variant codes; returns a
  `products` block for the widget.

Add your own tool → [Symfony bundle README](https://github.com/FluffyDiscord/symfony-honkers-bundle#add-a-tool).

## Shipped sources

The backend pulls these in bulk and ingests them into its retrieval index:

- `products` — indexable channel products per locale with `ProductMetadata` (`code, name, url,
  imageUrl, priceMinor, currency, inStock, taxons, taxonNames, attributes`); `taxons` carries taxon **codes**,
  `taxonNames` their names in the requested locale (the backend builds each product's search card from them); the
  names and main taxon path also live in the document text.
- `categories` — enabled taxons of the channel tree per locale (`code, name, path, url, productCount`).
  - Tree = the channel's **menu taxon** subtree. A taxon is served only when every ancestor *below* the
    tree top is enabled, so a disabled branch never leaks its children.
  - The tree top (menu taxon, or tree root when there is none) never disqualifies what is beneath it.
    Disabling it empties the channel's menu without emptying this source.
  - The menu taxon is served as a category document; a bare tree root is not.
  - A channel with no menu taxon serves the shop's only taxon tree. With several trees it is refused
    `409 ambiguous_taxon_tree`.
  - `productCount` counts the taxon's whole nested-set subtree, only products passing
    `ProductIndexabilityInterface` — so it agrees with the category page
    (`include_all_descendants: true`) and the chatbot.
- `cms_pages` — enabled Monsieur Biz CMS pages per locale (registered only when the plugin is installed).

**Every absolute URL a source or tool emits is built on the resolved channel's hostname**, not the
request host. A read for `channel=X` returns links on X's domain; only the host is swapped.

- Channel hostname empty → the request host stands in.
- Sylius stores no per-channel *scheme* or *port*, so both come from the request context. Set
  `router.request_context.scheme` (and `host`) for `notify-all` — it runs outside a request and would
  otherwise emit `http://`. A shop on a non-standard port carries that port into every channel's URLs.
- `imageUrl`: a `liip_imagine` **`cache` resolver** in front of `web_path` memoises the finished
  absolute URL under a host-less key — the first channel read then feeds its host to every other
  channel. Keep the chatbot's image filter on a host-agnostic resolver.

### Extension points

Both govern the `products` source; redefine the container alias in `services.yaml` to replace them:

| Interface | Default | Purpose |
|---|---|---|
| `ProductViewFactoryInterface` | `ProductViewFactory` | price, stock, image and URL of an indexed product |
| `ProductIndexabilityInterface` | `ProductIndexability` | which products the source serves (enabled, in the channel, at least one priced enabled variant) |

Add your own source → [Symfony bundle README](https://github.com/FluffyDiscord/symfony-honkers-bundle#add-a-data-source).

## Catalog change notifications

**These keep the backend's ingested sources fresh.** Saving a `Product`, `ProductTranslation`, `Taxon`
or `TaxonTranslation` collects the changed external ids; one `POST {backend_url}/api/v1/catalog/changes`
per `(source, locale)` is sent on `kernel.terminate` and `ConsoleEvents::TERMINATE`, synchronously and
one at a time through the SDK's `CatalogIngestClient` (2 s timeout, 5 s max duration, max 500 ids per
request). **A failure is logged and swallowed** — a dropped notification costs at most one nightly
cycle of staleness, never a broken shop request.

What each change announces:

| Saved entity | Announces |
|---|---|
| `ProductTranslation` | its own locale |
| `TaxonTranslation` | `categories` for its own locale |
| `Product` | every locale of `sylius_locale` |
| `Taxon` | `categories` for every locale, no product fan-out |

With `backend_url` or `ingest_secret` empty, or with neither `widget.site_key` nor any
`channel_site_keys` entry set, nothing is sent and a warning names the missing key.

Which site receives a `(source, locale)`:

| `channel_site_keys` | Recipients |
|---|---|
| empty | the `widget.site_key` site, for every locale |
| set | the site of every **enabled** channel serving that locale; channels resolving to the same site key share one request |

With channel keys set: an enabled channel that serves a changed locale but resolves no key is skipped
with a warning; a channel mapped to an empty value is skipped with a debug record; a locale no enabled
channel serves is sent nowhere.

### Re-announce everything

```bash
bin/console fluffydiscord:chatbot:notify-all [--source=products|categories|cms_pages] [--locale=cs_CZ] [--channel=code]
```

Re-announces the whole catalog in 500-id batches, pausing 2 s between batches and honouring
`Retry-After` on a 429.

- **Pass `--channel` when no channel can be resolved from the CLI context.**
- Without `--locale`, locales come from the resolved channel, so each channel's catalog is paired with
  the locales it serves.
- Without channel keys the catalog goes to the `widget.site_key` site. With channel keys it goes to the
  resolved channel's site only — a disabled channel or one without a site key aborts the run; run it
  once per channel.
- `--locale` must be an ICU-known locale (any spelling); an unknown or unserved locale aborts the run.

## Widget

When `widget.enabled` is true the bundle injects, via the `sylius_shop.base#javascripts` twig hook on
Sylius 2 and the `sylius.shop.layout.javascripts` template block on Sylius 1.14:

```html
<script src="{widget_cdn_url}" defer></script>
<ai-chat-widget site-key="{site_key}" locale="{app.locale}" backend-url="{backend_url}"></ai-chat-widget>
```

`site_key` is resolved at render time by the `fluffydiscord_chatbot_site_key(fallback)` Twig function:
the current channel's `channel_site_keys` entry, else the template's own `site_key`. The shop's
`ChannelContextInterface` decides the channel; without a resolvable channel the fallback is used.
**When the resolved key is empty nothing renders** — neither script nor element.

Include the template directly with a plain context — the channel key still applies:

```twig
{% include '@FluffyDiscordSyliusHonkers/shop/widget.html.twig' with {
    backend_url: 'https://chatbot.example.com',
    site_key: 'site-key',
    widget_cdn_url: '',
} only %}
```

`widget_cdn_url` defaults to `{backend_url}/widget/v1/chat.js` when `widget.cdn_url` is unset. The
widget talks only to `backend_url`; it never calls `/chatbot/v1` itself.
