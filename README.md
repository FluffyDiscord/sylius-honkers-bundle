# FluffyDiscord Sylius Chatbot Bundle

Exposes a small authenticated HTTP tool server (`/chatbot/v1`) for an external AI chatbot backend and embeds the chat widget into the Sylius shop layout.

Requires PHP `^8.2` and Sylius `^1.14 || ^2.2 || ^2.3`.

## Requirements

| Package | Constraint |
|---|---|
| `php` | `^8.2` |
| `ext-intl` | `*` |
| `sylius/sylius` | `^1.14 \|\| ^2.2 \|\| ^2.3@alpha` |
| `doctrine/orm` | `^2.20 \|\| ^3.6 \|\| ^4.0` |
| `doctrine/doctrine-bundle` | `^2.13 \|\| ^3.2 \|\| ^4.0` |
| `symfony/*` | `^6.4 \|\| ^7.4 \|\| ^8.0` |

The `@alpha` on `sylius/sylius` is only because 2.3 has no stable tag yet (`v2.3.0-ALPHA.1`); it drops once 2.3 ships stable.

On Sylius 1.14 the shop runs on Symfony 6.4 and has no Twig Hooks, so the widget is registered as a `sylius_ui` template block on `sylius.shop.layout.javascripts` instead. That is the only mechanism 1.14 offers and Sylius deprecated it in the same release, so the container build reports `sylius/ui-bundle` deprecations for it — expected, not a bug. Everything else — endpoints, data sources, catalog notifications — is identical on both lines.

## Installation

### 1. Composer

```bash
composer require fluffydiscord/sylius-chatbot-bundle
```

### 2. Register the bundle

`config/bundles.php`:

```php
FluffyDiscord\SyliusChatbotBundle\FluffyDiscordSyliusChatbotBundle::class => ['all' => true],
```

### 3. Routes

`config/routes/fluffydiscord_sylius_chatbot.yaml`:

```yaml
fluffydiscord_sylius_chatbot:
    resource: '@FluffyDiscordSyliusChatbotBundle/config/routes.php'
```

The endpoints live under `/chatbot/v1` on the shop host and must not be behind the shop's `_locale` prefix.

### 4. Bundle configuration

`config/packages/fluffydiscord_sylius_chatbot.yaml`:

```yaml
fluffy_discord_sylius_chatbot:
    api_secret: '%env(CHATBOT_API_SECRET)%'
    backend_url: '%env(CHATBOT_BACKEND_URL)%'
    ingest_secret: '%env(CHATBOT_INGEST_SECRET)%'
    widget:
        enabled: true
        site_key: '%env(CHATBOT_SITE_KEY)%'
        cdn_url: '%env(CHATBOT_WIDGET_CDN_URL)%'
```

`backend_url` is used by the `backend-url` attribute (the widget's API origin) and the catalog change notifier. `widget.backend_url` still works as a deprecated alias and is used when the root value is empty.

`widget.cdn_url` is the URL the `<script src>` loads `chat.js` from — set it to the Bunny CDN URL the backend publishes to via `chatbot:widget:deploy` (it must equal the backend's `BUNNY_CDN_PURGE_URL`). Only the script bytes move to the CDN; every API call still goes to `backend_url`. When empty it falls back to `{backend_url}/widget/v1/chat.js` (backend-served).

`widget.enabled` must be a literal boolean (it decides at compile time whether the widget hook is registered).

`.env`:

```dotenv
CHATBOT_API_SECRET=change-me
CHATBOT_BACKEND_URL=https://chatbot.example.com
CHATBOT_INGEST_SECRET=change-me
CHATBOT_SITE_KEY=site-key
```

`ingest_secret` is the shop half of the catalog notification credential; the backend receives `Authorization: Bearer <site_key>.<ingest_secret>`. Outside the `dev` environment a non-`https` `backend_url` is refused and nothing is sent.

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
            entry_point: FluffyDiscord\SyliusChatbotBundle\Security\ApiAuthenticationFailureHandler
            access_token:
                token_handler: FluffyDiscord\SyliusChatbotBundle\Security\ApiSecretAuthenticator
                failure_handler: FluffyDiscord\SyliusChatbotBundle\Security\ApiAuthenticationFailureHandler
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
- `categories` — enabled taxons of the channel tree per locale (`code, name, path, url, productCount`). The tree is the channel's **menu taxon** subtree; within it a taxon is served only when every ancestor *below* the tree top is enabled too, so a disabled branch never leaks its children. The tree top itself — the menu taxon, or the tree root when there is none — never disqualifies anything beneath it, so disabling it empties the channel's menu without emptying this source; Sylius's own menu makes the same call (`TaxonRepository::findChildrenByChannelMenuTaxon()` filters `enabled` on the rendered children only). The menu taxon **is** served as a category document, a bare tree root is not. A channel **without** a menu taxon serves the shop's only taxon tree, and is refused with `409 ambiguous_taxon_tree` when the shop has several — there is then no way to tell this channel's categories from another channel's. `productCount` counts the taxon's whole nested-set subtree and only products passing `ProductIndexabilityInterface`, so it agrees with the category page (`include_all_descendants: true`) and with what the chatbot can return.
- `cms_pages` — enabled Monsieur Biz CMS pages per locale (registered only when the plugin is installed).

Every absolute URL a source or a tool emits — page URLs and `imageUrl` alike — is built on the **resolved channel's hostname**, not on the host the request arrived at (the channel's hostname is empty → the request host stands in). A read for `channel=X` therefore always hands back links on X's own domain. Only the host is swapped. Sylius stores no per-channel *scheme* or *port*, so both still come from the request context: set `router.request_context.scheme` (and `host`) for `notify-all`, which runs outside a request and would otherwise emit `http://`, and expect a shop served on a non-standard port to carry that port into every channel's URLs. One caveat on `imageUrl`: the swap reaches Liip's resolver because it shares the router's `RequestContext`, but a `liip_imagine` **`cache` resolver** in front of `web_path` memoises the finished absolute URL under a host-less key, so the first channel read would then feed its host to every other channel. Keep the chatbot's image filter on a host-agnostic resolver.

**Adding a data source** — implement `ChatbotDataSourceInterface` the same way a tool is added; `SourceDefinition::$locales = null` means "all locales of the current channel".

**Extension points** (both govern the `products` source):

| Interface | Default | Purpose |
|---|---|---|
| `ProductViewFactoryInterface` | `ProductViewFactory` | price, stock, image and URL of an indexed product |
| `ProductIndexabilityInterface` | `ProductIndexability` | which products the `products` source serves (default: enabled, in the channel, at least one priced enabled variant) |

Both are wired as container aliases; redefine the alias in the shop's `services.yaml` to replace them.

### Catalog change notifications

These keep the backend's ingested sources fresh. Saving a `Product`, a `ProductTranslation`, a `Taxon` or a `TaxonTranslation` collects the changed external ids and one `POST {backend_url}/api/v1/catalog/changes` per `(source, locale)` is sent on `kernel.terminate` and on `ConsoleEvents::TERMINATE` (max 500 ids per request, 2 s timeout, 5 s max duration). The whole flush shares a single 5 s wall-clock budget: the requests are issued together and drained in one `stream()` loop, and whatever has not finished when the budget is spent is abandoned — a dropped notification costs at most one nightly cycle of staleness.

A `ProductTranslation` change announces its own locale only, a `TaxonTranslation` change announces `categories` for its own locale, a `Product` change announces every locale of `sylius_locale`, and a `Taxon` change announces `categories` for every locale without fanning out to its products. Every failure is logged as a warning and swallowed — a notification never breaks a shop request. With `backend_url`, `ingest_secret` or `widget.site_key` empty nothing is sent and a warning names the missing key.

`bin/console fluffydiscord:chatbot:notify-all [--source=products|categories] [--locale=cs_CZ] [--channel=code]` re-announces the whole catalog in 500-id batches, pausing 2 s between batches and honouring `Retry-After` on a 429. Pass `--channel` when no channel can be resolved from the CLI context; without `--locale` the locales are taken from the resolved channel, so each channel's catalog is paired with the locales that channel actually serves. A `--locale` the source does not serve aborts the run instead of announcing a locale the backend cannot use.

## Widget

When `widget.enabled` is true the bundle injects, via the `sylius_shop.base#javascripts` twig hook on Sylius 2 and the `sylius.shop.layout.javascripts` template block on Sylius 1.14:

```html
<script src="{widget_cdn_url}" defer></script>
<ai-chat-widget site-key="{site_key}" locale="{app.locale}" backend-url="{backend_url}"></ai-chat-widget>
```

`widget_cdn_url` defaults to `{backend_url}/widget/v1/chat.js` when `widget.cdn_url` is unset. The widget talks only to the backend at `backend_url`; it never calls `/chatbot/v1` itself. Tool calls and source ingestion are the backend's job — the widget only renders what the backend streams back.
