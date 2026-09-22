# FluffyDiscord Sylius honkers.dev Bundle

Plug-and-play Sylius wrapper for the honkers.dev chatbot tool-server. It adds the Sylius default tools,
data sources and the shop widget on top of two lower layers:

- [`fluffydiscord/honkers-sdk`](https://github.com/FluffyDiscord/honkers-sdk) — the
  framework-agnostic core (tools, data sources, JSON-schema, DTOs).
- [`fluffydiscord/symfony-honkers-bundle`](https://github.com/FluffyDiscord/symfony-honkers-bundle) —
  the vanilla Symfony bundle that exposes the `/chatbot/v1` HTTP endpoints, security and wiring.

Both are pulled in automatically as dependencies. This bundle only adds the Sylius-specific pieces.

Requires PHP `^8.1` and Sylius `^1.14 || ^2.2 || ^2.3`.

## Requirements

| Package | Constraint |
|---|---|
| `php` | `^8.1` |
| `ext-intl` | `*` |
| `fluffydiscord/symfony-honkers-bundle` | `dev-master` |
| `sylius/sylius` | `^1.14 \|\| ^2.2 \|\| ^2.3@alpha` |
| `doctrine/orm` | `^2.20 \|\| ^3.6 \|\| ^4.0` |
| `doctrine/doctrine-bundle` | `^2.13 \|\| ^3.2 \|\| ^4.0` |
| `symfony/*` | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `twig/twig` | `^2.12 \|\| ^3.3` |

The `@alpha` on `sylius/sylius` is only because 2.3 has no stable tag yet (`v2.3.0-ALPHA.1`); it drops once 2.3 ships stable.

On Sylius 1.14 the shop runs on Symfony 6.4 and has no Twig Hooks, so the widget is registered as a `sylius_ui` template block on `sylius.shop.layout.javascripts` instead. That is the only mechanism 1.14 offers and Sylius deprecated it in the same release, so the container build reports `sylius/ui-bundle` deprecations for it — expected, not a bug. Everything else — endpoints, data sources, catalog notifications — is identical on both lines.

## Installation

### 1. Composer

```bash
composer require fluffydiscord/sylius-honkers-bundle
```

### 2. Register the bundles

Both the vanilla Symfony bundle (endpoints, security) and this Sylius wrapper are registered in
`config/bundles.php`:

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

The endpoints live under `/chatbot/v1` on the shop host and must not be behind the shop's `_locale` prefix.

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

`backend_url` is the honkers.dev origin — used by the widget's `backend-url` attribute and the catalog change notifier.

`widget.cdn_url` is the URL the `<script src>` loads `chat.js` from — set it to the Bunny CDN URL the backend publishes to via `chatbot:widget:deploy` (it must equal the backend's `BUNNY_CDN_PURGE_URL`). Only the script bytes move to the CDN; every API call still goes to `backend_url`. When empty it falls back to `{backend_url}/widget/v1/chat.js` (backend-served).

`widget.enabled` must be a literal boolean (the widget hook is registered at build time).

`.env`:

```dotenv
CHATBOT_API_SECRET=change-me
CHATBOT_BACKEND_URL=https://chatbot.example.com
CHATBOT_INGEST_SECRET=change-me
CHATBOT_SITE_KEY=site-key
```

`ingest_secret` is the shop half of the catalog notification credential; the backend receives `Authorization: Bearer <site_key>.<ingest_secret>`. Outside the `dev` environment a non-`https` `backend_url` is refused and nothing is sent.

#### One site per channel

When each channel is its own site on the backend, map channel codes to site keys in this bundle.
`config/packages/fluffy_discord_sylius_honkers.yaml`:

```yaml
fluffy_discord_sylius_honkers:
    channel_site_keys:
        CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
        SK_WEB: '%env(CHATBOT_SITE_KEY_SK)%'
        DE_WEB: 'pk_live_de'
```

- A channel's site key is `channel_site_keys[<channel code>]`, else `fluffy_discord_honkers.widget.site_key`. An entry is authoritative: a channel mapped to an empty value has no site key, it never falls back.
- `widget.site_key` is optional once `channel_site_keys` has an entry; it then only serves unmapped channels.
- Keys are channel codes (not normalized, `cz-web` stays `cz-web`), values are strings — literal or `%env()%`.
- With channel keys set, **disabled** channels get no catalog notifications and `notify-all --channel=<disabled>` is refused.
- Empty `channel_site_keys` (the default) behaves like a single-site shop: channels, enabled or not, are never consulted for notifications.

### 5. Security

Add the `chatbot_api` firewall to `config/packages/security.yaml` **before** the Sylius `shop` firewall (firewalls match in order and `shop` matches `^/`):

```yaml
security:
    providers:
        chatbot_backend:
            memory:
                users: []
    firewalls:
        chatbot_api:
            pattern: ^/chatbot/v1
            stateless: true
            provider: chatbot_backend
            entry_point: FluffyDiscord\HonkersBundle\Security\ApiAuthenticationFailureHandler
            access_token:
                token_handler: FluffyDiscord\HonkersBundle\Security\ApiSecretAuthenticator
                failure_handler: FluffyDiscord\HonkersBundle\Security\ApiAuthenticationFailureHandler
        # shop: ...
    access_control:
        - { path: ^/chatbot/v1, roles: ROLE_CHATBOT_BACKEND }
```

The backend authenticates with `Authorization: Bearer <CHATBOT_API_SECRET>`.

## What the bundle exposes

Three independent channels — do not conflate them:

- **Tools** — live, per-request calls the backend makes *during a conversation* (order status, stock/price right now).
- **Sources** — bulk catalog/content documents the backend *pulls and ingests* into its own retrieval index; never called per chat.
- **Widget** — the chat UI, embedded into the shop layout.

Tools and sources are HTTP endpoints under `/chatbot/v1` on the shop host. Both use the error envelope
`{ "error": { "code", "message", "violations" } }`; `violations` is non-empty only for HTTP 422 (`validation_failed`).

### Locales

Every locale the bundle accepts — the `locale` query parameter, the tool call `context.locale`, the `Accept-Language`
header and `--locale` — must be a locale ICU knows (`symfony/intl`). Spelling does not matter: `cs-CZ`, `cs_cz` and
`CS-cz` all mean `cs_CZ`. The requested locale is matched against the locales the channel (or the data source) serves,
first as the same locale and then as the same language, and everything the bundle then queries, renders and announces
uses **the shop's own full locale code** (`cs_CZ`), never the requested spelling and never a bare language.

What an unusable locale costs differs per endpoint, because each one has somewhere different to fall back to:

| Endpoint | Not an ICU locale | Valid but unserved |
|---|---|---|
| `GET /sources/{name}?locale=` | 400 `invalid_locale` | 400 `invalid_locale` |
| `POST /tools/{name}` (`context.locale`) | 422 `validation_failed` with a `context.locale` violation | falls back to the shop's context locale |
| `GET /tools` (`Accept-Language`) | 400 `bad_request` | falls back to the shop's context locale |
| `notify-all --locale` | aborts, nothing announced | aborts, nothing announced |

## Tools

The backend calls these *while answering a shopper*: the model decides it needs live data, the backend makes a
server-to-server request to the shop mid-turn, and feeds the result back into the same conversation. Tools answer
"what is **true right now**".

| Method | Path | Description |
|---|---|---|
| GET | `/chatbot/v1/tools` | Tool definitions with generated JSON input schemas; labels translated per `Accept-Language` |
| POST | `/chatbot/v1/tools/{name}` | Executes a tool with `{ arguments, context: { conversationId, locale, channelCode } }` |

**Shipped tools**

- `get_order_status` — order state, payment and shipping state, tracking codes, item count and total; requires the order number and the customer e-mail (uniform "not found" otherwise).
- `get_product_availability` — price, currency and stock for up to 20 variant codes; returns a `products` block for the widget.

**Adding a tool** — one class plus one arguments DTO, no configuration:

```php
readonly class MyArguments
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $query = '',
    ) {
    }
}

readonly class MyTool implements ChatbotToolInterface
{
    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition('my_tool', 'app.chatbot.my_tool.description');
    }

    public function getArgumentsClass(): string
    {
        return MyArguments::class;
    }

    public function execute(object $arguments, ToolCallContext $context): ToolResult
    {
        return new ToolResult([new ContentItem('...')]);
    }
}
```

The interface is autoconfigured; the input schema is generated from the DTO (`Assert\NotBlank` → required, `Assert\Email` → e-mail format, `Assert\Choice` → enum, `Assert\Length` → maxLength, nullable → optional). Descriptions and labels are translation keys resolved with the request locale. `getDefinition()` must not rely on constructor arguments — it is invoked at container compile time to index tools by name (duplicate names fail the container build).

### Choices loaded at runtime

`Assert\Choice` takes a fixed list or a static callback — no services. When the allowed values live in the database, use `#[ToolChoice]` instead: it is `Assert\Choice` with the choices loaded from a service implementing `ToolChoiceLoaderInterface` (autoconfigured). The loaded values become the property's schema `enum` in the tool list, and Symfony's `ChoiceValidator` checks them when the tool is called.

```php
use FluffyDiscord\Honkers\Contract\ToolChoiceLoaderInterface;
use FluffyDiscord\Honkers\Validator\ToolChoice;

readonly class RegionChoiceLoader implements ToolChoiceLoaderInterface
{
    public function __construct(
        private RegionRepository $regionRepository,
    ) {
    }

    public function loadChoices(): array
    {
        return $this->regionRepository->findAllNames();
    }
}

readonly class FindByRegionArguments
{
    public function __construct(
        #[ToolChoice(loader: RegionChoiceLoader::class, message: 'The region argument must be one of the listed names.')]
        public ?string $region = null,
    ) {
    }
}
```

- Takes every `Assert\Choice` option except `choices`, `callback` and `strict`: `multiple`, `min`, `max`, `match` and the messages. Violations carry `Choice`'s codes and parameters.
- The property must be `string`, or `array` with `multiple: true` (published as `items.enum`, plus `minItems`/`maxItems`). Anything else fails the tool list.
- The loader is looked up by its service id, which must be its class name (the default for autoconfigured services).
- Choices are the exact values the tool accepts, in every locale — not translated labels. Duplicates are dropped.
- A loader that returns nothing leaves the argument out of the schema; any value sent anyway fails validation. A required argument (`Assert\NotBlank`) with an empty loader makes the tool uncallable.
- A loader that throws fails the tool list and the call. Return an empty list for missing data; throw only for misconfiguration.
- `null` passes; add `Assert\NotBlank` to make the argument required.

## Sources

Sources are **not** called per chat. The backend pulls them in bulk, chunks and embeds the documents, and stores the
vectors in its own retrieval index; at chat time it searches that index instead of calling the shop. Sources answer
"what the shop **is**" (catalog + content), the counterpart to tools' "what is true right now". Re-ingestion is driven
by the catalog-change notifications below (delta) and by the backend's own full-sync schedule.

| Method | Path | Description |
|---|---|---|
| GET | `/chatbot/v1/sources` | Data source definitions with served locales |
| GET | `/chatbot/v1/sources/{name}?locale=cs_CZ&channel=&cursor=&ids[]=` | Keyset-paginated documents (200 per page); with `ids[]` (max 500) the cursor is ignored and `nextCursor` is `null` |

**Shipped sources**

- `products` — indexable channel products per locale with `ProductMetadata` (`code, name, url, imageUrl, priceMinor, currency, inStock, taxons, attributes`); `taxons` carries taxon **codes**, the taxon names and the main taxon path live in the document text.
- `categories` — enabled taxons of the channel tree per locale (`code, name, path, url, productCount`).
  - Tree = the channel's **menu taxon** subtree. A taxon is served only when every ancestor *below* the tree top is enabled, so a disabled branch never leaks its children.
  - The tree top (menu taxon, or tree root when there is none) never disqualifies what is beneath it. Disabling it empties the channel's menu without emptying this source — Sylius's own menu filters `enabled` on rendered children only (`TaxonRepository::findChildrenByChannelMenuTaxon()`).
  - The menu taxon is served as a category document; a bare tree root is not.
  - A channel with no menu taxon serves the shop's only taxon tree. With several trees it is refused `409 ambiguous_taxon_tree` — the channel's categories cannot be told from another channel's.
  - `productCount` counts the taxon's whole nested-set subtree, only products passing `ProductIndexabilityInterface` — so it agrees with the category page (`include_all_descendants: true`) and with what the chatbot returns.
- `cms_pages` — enabled Monsieur Biz CMS pages per locale (registered only when the plugin is installed).

Every absolute URL a source or tool emits (page URLs and `imageUrl`) is built on the **resolved channel's hostname**, not the request host. A read for `channel=X` returns links on X's domain. Only the host is swapped.

- Channel hostname empty → the request host stands in.
- Sylius stores no per-channel *scheme* or *port*, so both come from the request context. Set `router.request_context.scheme` (and `host`) for `notify-all` — it runs outside a request and would otherwise emit `http://`. A shop on a non-standard port carries that port into every channel's URLs.
- `imageUrl`: the swap reaches Liip's resolver (it shares the router's `RequestContext`), but a `liip_imagine` **`cache` resolver** in front of `web_path` memoises the finished absolute URL under a host-less key — the first channel read then feeds its host to every other channel. Keep the chatbot's image filter on a host-agnostic resolver.

**Adding a data source** — implement `ChatbotDataSourceInterface` the same way a tool is added; `SourceDefinition::$locales = null` means "all locales of the current channel".

**Extension points** (both govern the `products` source):

| Interface | Default | Purpose |
|---|---|---|
| `ProductViewFactoryInterface` | `ProductViewFactory` | price, stock, image and URL of an indexed product |
| `ProductIndexabilityInterface` | `ProductIndexability` | which products the `products` source serves (default: enabled, in the channel, at least one priced enabled variant) |

Both are wired as container aliases; redefine the alias in the shop's `services.yaml` to replace them.

### Catalog change notifications

These keep the backend's ingested sources fresh. Saving a `Product`, a `ProductTranslation`, a `Taxon` or a `TaxonTranslation` collects the changed external ids and one `POST {backend_url}/api/v1/catalog/changes` per `(source, locale)` is sent on `kernel.terminate` and on `ConsoleEvents::TERMINATE` (max 500 ids per request). Each request carries a 2 s timeout and 5 s max duration and is sent synchronously, one at a time, through the SDK's `CatalogIngestClient`; a failure is logged and swallowed — a dropped notification costs at most one nightly cycle of staleness.

A `ProductTranslation` change announces its own locale only, a `TaxonTranslation` change announces `categories` for its own locale, a `Product` change announces every locale of `sylius_locale`, and a `Taxon` change announces `categories` for every locale without fanning out to its products. Every failure is logged as a warning and swallowed — a notification never breaks a shop request. With `backend_url` or `ingest_secret` empty, or with neither `widget.site_key` nor any `channel_site_keys` entry set, nothing is sent and a warning names the missing key.

Which site receives a `(source, locale)`:

| `channel_site_keys` | Recipients |
|---|---|
| empty | the `widget.site_key` site, for every locale |
| set | the site of every **enabled** channel serving that locale; channels resolving to the same site key share one request |

With channel keys set, an enabled channel that serves a changed locale but resolves no key (unmapped, `site_key` empty) is skipped with a warning; a channel mapped to an empty value is skipped with a debug record. A locale no enabled channel serves is sent nowhere. Every site's requests are sent one at a time in 500-id batches.

`bin/console fluffydiscord:chatbot:notify-all [--source=products|categories|cms_pages] [--locale=cs_CZ] [--channel=code]` re-announces the whole catalog in 500-id batches, pausing 2 s between batches and honouring `Retry-After` on a 429. Pass `--channel` when no channel can be resolved from the CLI context; without `--locale` the locales are taken from the resolved channel, so each channel's catalog is paired with the locales that channel actually serves. Without channel keys the catalog goes to the `widget.site_key` site. With channel keys it goes to the resolved channel's site only, and a disabled channel or one without a site key aborts the run; run it once per channel. A `--locale` the source does not serve aborts the run instead of announcing a locale the backend cannot use.

## Widget

When `widget.enabled` is true the bundle injects, via the `sylius_shop.base#javascripts` twig hook on Sylius 2 and the `sylius.shop.layout.javascripts` template block on Sylius 1.14:

```html
<script src="{widget_cdn_url}" defer></script>
<ai-chat-widget site-key="{site_key}" locale="{app.locale}" backend-url="{backend_url}"></ai-chat-widget>
```

`site_key` is resolved at render time by the `fluffydiscord_chatbot_site_key(fallback)` Twig function: the current channel's `channel_site_keys` entry, else the template's own `site_key`. The shop's `ChannelContextInterface` decides the channel; without a resolvable channel the fallback is used. When the resolved key is empty nothing is rendered — neither the script nor the element.

The template can also be included directly with a plain context — the channel key is still applied:

```twig
{% include '@FluffyDiscordSyliusHonkers/shop/widget.html.twig' with {
    backend_url: 'https://chatbot.example.com',
    site_key: 'site-key',
    widget_cdn_url: '',
} only %}
```

`widget_cdn_url` defaults to `{backend_url}/widget/v1/chat.js` when `widget.cdn_url` is unset. The widget talks only to the backend at `backend_url`; it never calls `/chatbot/v1` itself. Tool calls and source ingestion are the backend's job — the widget only renders what the backend streams back.
