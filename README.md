# Sylius honkers.dev Plugin

> Renamed from `fluffydiscord/sylius-honkers-bundle` → see [UPGRADE-2.0.md](UPGRADE-2.0.md).

Sylius plugin for the honkers.dev chatbot: shop tools, catalog sources and the shop widget. Sylius 1.14 and 2.x.

Tool-server mechanics (endpoints, error format, locales, your own tools and sources) live in the
[Symfony bundle README](https://github.com/FluffyDiscord/symfony-honkers-bundle) — this page covers the Sylius pieces.

## Features

- [Connect from the dashboard](#connect-from-the-dashboard) — one click pairs a channel; nobody copies a secret
- [Credentials](#credentials) — env for a single site, one site per channel, or your own store
- [Shipped tools](#shipped-tools) — order status and product availability in the chat
- [Shipped sources](#shipped-sources) — products, categories and CMS pages, with links on each channel's domain
- [Catalog change notifications](#catalog-change-notifications) — saved products reach the chatbot without waiting for the nightly sync
- [Widget](#widget) — the chat on every shop page, no template edits
- [Chat click tracking](#chat-click-tracking) — visits and revenue from chat links
- [Orders from chat](#orders-from-chat) — open the conversation behind an order from its admin page

## Requirements

| Package | Constraint |
|---|---|
| `php` | `^8.1` |
| `ext-intl` | `*` |
| `sylius/sylius` | `^1.14 \|\| ^2.2 \|\| ^2.3@alpha` |
| `doctrine/orm` | `^2.20 \|\| ^3.6 \|\| ^4.0` |
| `symfony/*` | `^6.4 \|\| ^7.0 \|\| ^8.0` |

> **On Sylius 1.14 the widget uses a deprecated hook.** 1.14 has no Twig Hooks, so the widget is a `sylius_ui`
> template block on `sylius.shop.layout.javascripts`. The container build reports `sylius/ui-bundle`
> deprecations for it: expected. Everything else works the same on both lines.

## Install

```bash
composer require fluffydiscord/sylius-honkers-plugin
```

## Usage

1. `config/bundles.php` — register the Symfony bundle and this plugin (Symfony Flex already did this if it's on):

   ```diff
    return [
        // ...
   +    FluffyDiscord\HonkersBundle\FluffyDiscordHonkersBundle::class => ['all' => true],
   +    FluffyDiscord\SyliusHonkersPlugin\FluffyDiscordSyliusHonkersPlugin::class => ['all' => true],
    ];
   ```

2. `config/routes/fluffy_discord_honkers.yaml` — the routes live in the Symfony bundle, `/chatbot/pair` included:

   ```yaml
   fluffy_discord_honkers:
       resource: '@FluffyDiscordHonkersBundle/config/routes.php'
   ```

   **Keep them off the shop's `_locale` prefix.** The backend calls `/chatbot/v1/*` on the shop host; the
   dashboard's Connect button opens `/chatbot/pair`.

3. `config/packages/security.yaml` — guard `/chatbot/v1`, **before** the Sylius `shop` firewall (firewalls match in
   order and `shop` matches `^/`):

   ```diff
    security:
   +    providers:
   +        chatbot_backend:
   +            memory:
   +                users: []
        firewalls:
   +        chatbot_api:
   +            pattern: ^/chatbot/v1
   +            stateless: true
   +            provider: chatbot_backend
   +            entry_point: FluffyDiscord\HonkersBundle\Security\ApiAuthenticationFailureHandler
   +            access_token:
   +                token_handler: FluffyDiscord\HonkersBundle\Security\ApiSecretAuthenticator
   +                failure_handler: FluffyDiscord\HonkersBundle\Security\ApiAuthenticationFailureHandler
            shop:
                # ...
        access_control:
   +        - { path: ^/chatbot/v1, roles: ROLE_CHATBOT_BACKEND }
   ```

   `/chatbot/pair` stays outside this firewall — it's a browser redirect, not a backend call.

4. `config/packages/fluffy_discord_honkers.yaml` — where the backend is:

   ```yaml
   fluffy_discord_honkers:
       backend_url: '%env(CHATBOT_BACKEND_URL)%'
   ```

5. `.env.local` — the backend URL, plus the credentials unless you [connect from the dashboard](#connect-from-the-dashboard):

   ```diff
   +CHATBOT_BACKEND_URL=https://honkers.dev
   +CHATBOT_API_SECRET=change-me
   +CHATBOT_INGEST_SECRET=change-me
   +CHATBOT_SITE_KEY=site-key
   ```

## Configuration

The connection and the widget belong to the Symfony bundle. Defaults:

```yaml
fluffy_discord_honkers:
    backend_url: ''
    widget:
        enabled: true
        cdn_url: ''
        defer: true
```

| Option | Default | Meaning |
|---|---|---|
| `backend_url` | `''` | honkers.dev origin; the widget, notifications, reports and pairing talk to it. Empty → nothing is sent |
| `widget.enabled` | `true` | `false` → no widget. Literal boolean, no `%env()%` |
| `widget.cdn_url` | `''` | where `chat.js` loads from. Empty → `https://honkers.b-cdn.net/widget/v1/chat.js` |
| `widget.defer` | `true` | `false` → plain `<script src>`, no `defer`. Literal boolean, no `%env()%` |

> **Outside `dev`, a non-`https` `backend_url` is refused** and nothing is sent.

## Credentials

**The widget needs only a site key.** Notifications and order reports also need an ingest secret (outbound:
`Authorization: Bearer <site key>.<ingest secret>`); the backend calls `/chatbot/v1` with the API secret. Per
channel, the first match wins:

| Channel | Site key | Ingest secret |
|---|---|---|
| [paired](#connect-from-the-dashboard) | stored on the channel | stored on the channel |
| in `channel_site_keys` | the entry | `CHATBOT_INGEST_SECRET` |
| in `channel_site_keys` with `''` | none → no widget, no notifications | — |
| any other | `CHATBOT_SITE_KEY` | `CHATBOT_INGEST_SECRET` |

Site key without an ingest secret → the widget renders; notifications and order reports are skipped.

The backend's API secret is accepted when it matches `CHATBOT_API_SECRET` or any paired channel's secret.

> **A paired API secret works on every channel**, not only on the channels it was paired with.

### One site per channel

**Map channel codes to site keys when each channel is its own site on the backend.**
`config/packages/fluffy_discord_sylius_honkers_plugin.yaml`:

```yaml
fluffy_discord_sylius_honkers_plugin:
    channel_site_keys:
        CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
        SK_WEB: '%env(CHATBOT_SITE_KEY_SK)%'
        DE_WEB: 'pk_live_de'
```

| Option | Default | Meaning |
|---|---|---|
| `channel_site_keys` | `{}` | `{ <channel code>: <site key> }`; codes verbatim (`cz-web` stays `cz-web`), values literal or `%env()%` |

- **An entry wins over `CHATBOT_SITE_KEY`.** `''` opts the channel out — it never falls back.
- `CHATBOT_SITE_KEY` then serves only unmapped channels; leave it empty when every channel is mapped.

### Your own store

Alias `ChannelCredentialsProviderInterface` to your service in `config/services.yaml`:

```yaml
services:
    FluffyDiscord\SyliusHonkersPlugin\Credentials\ChannelCredentialsProviderInterface: '@App\Chatbot\VaultCredentialsProvider'
```

The Symfony bundle's `CredentialsProviderInterface` points at the same service. To make Connect store into it too,
also implement `FluffyDiscord\HonkersBundle\Contract\CredentialsWriterInterface` and alias it the same way.

> **Alias the Sylius interface, not only the Symfony one.** Aliasing just `CredentialsProviderInterface` fails the
> container build: Sylius resolves credentials per channel.

## Connect from the dashboard

**Click Connect on the tool-server connection in the chatbot dashboard** — the shop generates its secrets, sends them
to `backend_url` and stores them on the connection's channels (none listed → the channel of the shop host).
Nothing to copy. A new key works at once, no cache clear.

1. `src/Entity/Channel/Channel.php` — let the channel store credentials:

   ```diff
   +use FluffyDiscord\SyliusHonkersPlugin\Credentials\HonkersChannelInterface;
   +use FluffyDiscord\SyliusHonkersPlugin\Credentials\HonkersChannelTrait;
    use Sylius\Component\Core\Model\Channel as BaseChannel;

   -class Channel extends BaseChannel
   +class Channel extends BaseChannel implements HonkersChannelInterface
    {
   +    use HonkersChannelTrait;
   ```

   > **Use `HonkersChannelInterface` only together with `HonkersChannelTrait`.** The lookup relies on the trait's fields.

2. Add the `honkers_site_key`, `honkers_api_secret_hash` and `honkers_ingest_secret` columns:

   ```bash
   bin/console doctrine:migrations:diff
   bin/console doctrine:migrations:migrate
   ```

3. Admin → Channels → set **Hostname** to the host of the connection's base URL (`shop.cz` for
   `https://shop.cz`). Case doesn't matter; `www.shop.cz` ≠ `shop.cz`.

   **Pairing several channels on different hosts?** Every channel's hostname must be on a verified domain of the
   website (`shop.sk` or `eshop.shop.sk` for `shop.sk`), else nothing is saved.

4. Click **Connect** in the dashboard. You land back there when it's done.

Adding the trait changes nothing until a channel is paired: unpaired channels keep their configured credentials.

> **Unset `CHATBOT_API_SECRET` once every channel is paired.** It stays valid otherwise.

> The API secret is stored only as a SHA-256 hash; the ingest secret is stored as-is (it has to be sent).
> The trait maps the columns with Doctrine attributes and annotations. Channel mapped in XML → map
> `honkersSiteKey` (`string`, 64), `honkersApiSecretHash` (`string`, 64, fixed) and `honkersIngestSecret`
> (`string`, 128), all nullable, yourself.

What can go wrong:

| Situation | Result |
|---|---|
| Channel entity without the trait | 409 page: paste the credentials into the shop's configuration |
| `backend_url` empty, or not `https` outside `dev` | 400 `backend_url_refused`, nothing sent |
| Shop host's channel has no hostname | 400 `pairing_hostname_missing`, nothing sent |
| A paired channel has no hostname | 400 `pairing_hostname_missing`, nothing saved |
| Connection's base URL on another host | 400 `pairing_wrong_shop`, nothing saved |
| A paired channel's hostname not on a verified domain | 400 `pairing_wrong_shop`, nothing saved |
| Connection names a channel code the shop doesn't have | 400 `pairing_unsupported`, nothing saved |
| Link older than 10 minutes, or used | 400 `pairing_not_found` |

A failed Connect is retried by clicking Connect again.

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
  `taxonNames` their names in the requested locale; the names and main taxon path also live in the document text.
- `categories` — enabled taxons of the channel tree per locale (`code, name, path, url, productCount`).
  - Tree = the channel's **menu taxon** subtree. A taxon is served only when every ancestor *below* the
    tree top is enabled, so a disabled branch never leaks its children.
  - The tree top (menu taxon, or tree root when there is none) never disqualifies what is beneath it.
    Disabling it empties the channel's menu without emptying this source.
  - The menu taxon is served as a category document; a bare tree root is not.
  - A channel with no menu taxon serves the shop's only taxon tree. With several trees it is refused
    `409 ambiguous_taxon_tree`.
  - `productCount` counts the taxon's whole subtree, only products passing `ProductIndexabilityInterface` —
    so it agrees with the category page (`include_all_descendants: true`) and the chatbot.
- `cms_pages` — enabled CMS pages of the channel per locale, from Monsieur Biz CMS or BitBag CMS (registered only
  when one of the plugins is installed).

**Every absolute URL a source or tool emits is built on the resolved channel's hostname**, not the
request host. A read for `channel=X` returns links on X's domain; only the host is swapped.

- Channel hostname empty → the request host stands in.
- Sylius stores no per-channel *scheme* or *port*, so both come from the request context. Set
  `router.request_context.scheme` (and `host`) for `notify-all` — it runs outside a request and would
  otherwise emit `http://`. A shop on a non-standard port carries that port into every channel's URLs.

> `imageUrl`: a `liip_imagine` **`cache` resolver** in front of `web_path` memoises the finished
> absolute URL under a host-less key — the first channel read then feeds its host to every other
> channel. Keep the chatbot's image filter on a host-agnostic resolver.

### Extension points

Both govern the `products` source; redefine the container alias in `services.yaml` to replace them:

| Interface | Default | Purpose |
|---|---|---|
| `ProductViewFactoryInterface` | `ProductViewFactory` | price, stock, image and URL of an indexed product |
| `ProductIndexabilityInterface` | `ProductIndexability` | which products the source serves (enabled, in the channel, at least one priced enabled variant) |

Add your own source → [Symfony bundle README](https://github.com/FluffyDiscord/symfony-honkers-bundle#add-a-data-source).

## Catalog change notifications

**These keep the backend's ingested sources fresh.** Saving a `Product`, `ProductTranslation`, `Taxon`
or `TaxonTranslation` collects the changed ids; one `POST {backend_url}/api/v1/catalog/changes`
per `(source, locale, site)` is sent after the response (`kernel.terminate`, `ConsoleEvents::TERMINATE`), one at a
time, max 500 ids per request.

> **The backend accepts notifications only from an active site.** A draft site answers 401 — activate it
> in the dashboard first. The same goes for order reports and the widget.

| Saved entity | Announces |
|---|---|
| `ProductTranslation` | its own locale |
| `TaxonTranslation` | `categories` for its own locale |
| `Product` | every locale of `sylius_locale` |
| `Taxon` | `categories` for every locale, no product fan-out |

**Recipients: every enabled channel serving the locale.** Channels with the same site key share one request.

- Channel without a site key or ingest secret → skipped, warning.
- Channel mapped to `''` → skipped, debug record.
- Locale no enabled channel serves → sent nowhere.
- `backend_url` empty → nothing sent.
- Backend fails → logged warning; the shop request is never affected. A dropped notification costs at most one
  nightly sync of staleness.

### Re-announce everything

```bash
bin/console fluffydiscord:chatbot:notify-all [--source=products|categories|cms_pages] [--locale=cs_CZ] [--channel=code]
```

Re-announces the whole catalog of one channel to its site in 500-id batches, pausing 2 s between batches and
honouring `Retry-After` on a 429.

- **Pass `--channel` when no channel can be resolved from the CLI context.** Run it once per channel.
- Disabled channel, or one without a site key or ingest secret → aborts.
- Without `--locale`, the channel's locales are announced.
- `--locale` must be an ICU-known locale (any spelling); an unknown or unserved locale aborts the run.

## Widget

When `widget.enabled` is true the plugin injects, via the `sylius_shop.base#javascripts` twig hook on
Sylius 2 and the `sylius.shop.layout.javascripts` template block on Sylius 1.14:

```html
<script src="{widget.cdn_url}" defer></script>
<ai-chat-widget site-key="{site key}" locale="{app.locale}" backend-url="{backend_url}"></ai-chat-widget>
```

- The site key is the current channel's, looked up on every render (`fluffydiscord_chatbot_site_key()`).
- **No site key → nothing renders**, neither script nor element.
- `widget.defer: false` → `<script src="…">` without `defer`.

Include the template yourself with a plain context:

```twig
{% include '@FluffyDiscordSyliusHonkersPlugin/shop/widget.html.twig' with {
    backend_url: 'https://chatbot.example.com',
    widget_cdn_url: '',
    defer: true,
} only %}
```

The widget talks only to `backend_url`; it never calls `/chatbot/v1` itself.

## Chat click tracking

**Visits and orders from chat links are reported for you.** No setup beyond `backend_url` and credentials.

- **Visit** → the Symfony bundle's [landing beacon](https://github.com/FluffyDiscord/symfony-honkers-bundle#chat-click-tracking)
  reports it, with the current channel's site key.
- **Product page opened from a chat link** (`?gooseclid=…`) → the product is remembered in the session.
  Last click per product wins, at most 20 products.
- **Order placed with a remembered product** → reported after the response is sent: revenue of the matching
  items (incl. tax and item discounts, excl. shipping), the order's currency, the order channel's site key.
  Unpaid orders count — it's reported when placed.
- **Any placed order resets the session**, with or without a chat product.

> Only shop checkout is covered (`sylius.order.post_complete`). Orders completed through the API or your
> own code aren't reported.

Backend unreachable → logged warning; checkout is never affected. Non-https `backend_url` → skipped outside
the `dev` env.

## Orders from chat

**Open the chat conversation behind an order from its admin page.** Add the interface and trait to your
`Order` entity:

```diff
+use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderInterface;
+use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderTrait;
 use Sylius\Component\Core\Model\Order as BaseOrder;

-class Order extends BaseOrder
+class Order extends BaseOrder implements ChatAttributedOrderInterface
 {
+    use ChatAttributedOrderTrait;
```

Then add the `chat_click_ids` column:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

The order keeps the click id (`gooseclid`) of every chat link that led to one of its products, oldest click
first. The order detail shows a **From chatbot** box, last in the right column:

- **Chat order** → a numbered list, one **Open conversation** link per click, to the conversation in the
  honkers.dev console.
- **Other orders** → `no`.

> The trait maps the column with Doctrine attributes and annotations. Order mapped in XML → map
> `chatClickIds` (`json`, nullable) yourself.

> Overriding `@SyliusAdmin/Order/show.html.twig` on Sylius 1 drops the sidebar blocks. Include the field
> yourself: `{% include '@FluffyDiscordSyliusHonkersPlugin/admin/order/from_chat.html.twig' %}`.

## License

MIT
