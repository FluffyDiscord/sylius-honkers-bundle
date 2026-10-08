# Upgrade from 1.x to 2.0

**The package is renamed and the secrets leave the config.** Most shops change five files and keep their `.env`.

## 1. Swap the package

```bash
composer remove fluffydiscord/sylius-honkers-bundle
composer require fluffydiscord/sylius-honkers-plugin
```

This pulls in `fluffydiscord/symfony-honkers-bundle` 2.0 and `fluffydiscord/honkers-sdk` 2.0.

| | 1.x | 2.0 |
|---|---|---|
| Composer package | `fluffydiscord/sylius-honkers-bundle` | `fluffydiscord/sylius-honkers-plugin` |
| Namespace | `FluffyDiscord\SyliusHonkersBundle\` | `FluffyDiscord\SyliusHonkersPlugin\` |
| Bundle class | `FluffyDiscordSyliusHonkersBundle` | `FluffyDiscordSyliusHonkersPlugin` |
| Config root | `fluffy_discord_sylius_honkers` | `fluffy_discord_sylius_honkers_plugin` |
| Template namespace | `@FluffyDiscordSyliusHonkers` | `@FluffyDiscordSyliusHonkersPlugin` |

## 2. Rename in your code

`config/bundles.php`:

```diff
     FluffyDiscord\HonkersBundle\FluffyDiscordHonkersBundle::class => ['all' => true],
-    FluffyDiscord\SyliusHonkersBundle\FluffyDiscordSyliusHonkersBundle::class => ['all' => true],
+    FluffyDiscord\SyliusHonkersPlugin\FluffyDiscordSyliusHonkersPlugin::class => ['all' => true],
```

`config/packages/fluffy_discord_sylius_honkers.yaml` → rename the file to `fluffy_discord_sylius_honkers_plugin.yaml`:

```diff
-fluffy_discord_sylius_honkers:
+fluffy_discord_sylius_honkers_plugin:
     channel_site_keys:
         CZ_WEB: '%env(CHATBOT_SITE_KEY_CZ)%'
```

`src/Entity/Order/Order.php`, if you use orders from chat:

```diff
-use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatAttributedOrderInterface;
-use FluffyDiscord\SyliusHonkersBundle\Attribution\ChatAttributedOrderTrait;
+use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderInterface;
+use FluffyDiscord\SyliusHonkersPlugin\Attribution\ChatAttributedOrderTrait;
```

Templates that include ours:

```diff
-{% include '@FluffyDiscordSyliusHonkers/admin/order/from_chat.html.twig' %}
+{% include '@FluffyDiscordSyliusHonkersPlugin/admin/order/from_chat.html.twig' %}
```

## 3. Delete the secrets from the config

**`api_secret`, `ingest_secret` and `widget.site_key` are gone** — keeping them fails the container build
(`Unrecognized option`). `config/packages/fluffy_discord_honkers.yaml`:

```diff
 fluffy_discord_honkers:
-    api_secret: '%env(CHATBOT_API_SECRET)%'
     backend_url: '%env(CHATBOT_BACKEND_URL)%'
-    ingest_secret: '%env(CHATBOT_INGEST_SECRET)%'
     widget:
         enabled: true
-        site_key: '%env(CHATBOT_SITE_KEY)%'
         cdn_url: '%env(CHATBOT_WIDGET_CDN_URL)%'
```

They're read straight from `CHATBOT_API_SECRET`, `CHATBOT_INGEST_SECRET` and `CHATBOT_SITE_KEY`. Under other names →
rename the env vars. `channel_site_keys` is unchanged.

Want to stop pasting secrets? → [Connect from the dashboard](README.md#connect-from-the-dashboard).

## 4. Check what changed behaviour

| | 1.x | 2.0 |
|---|---|---|
| Notifications without `channel_site_keys` | every locale → the `widget.site_key` site | every **enabled** channel serving the locale → its site |
| Notification for a locale no enabled channel serves | sent to the `widget.site_key` site | not sent |
| Site key without an ingest secret | — | widget renders; notifications and order reports skipped |
| `notify-all --channel=<disabled>` without `channel_site_keys` | announced | refused |
| Widget template included with `site_key` in the context | that key was the fallback | ignored; the channel's key is used |
| `<script>` of the widget | always `defer` | `widget.defer`, default `true` |

## Removed and changed classes

Autowired shops need no change. Only code constructing, extending or calling these:

- `Site\ChannelSiteKeyContext` — removed, with the Symfony bundle's `SiteKeyContextInterface`. Use
  `Credentials\ChannelCredentialsProviderInterface::findCurrentSite()` / `findForChannel()`.
- `Channel\SiteKeyResolver` — only `getSiteKeyRouting()` is left; `getSiteKey()`, `findChannelSiteKey()`,
  `getDefaultSiteKey()`, `hasChannelSiteKeys()`, `hasAnySiteKey()` removed. `SiteKeyRouting::getSiteKeys()` →
  `getSiteCredentials()`, returning `SiteCredentials`.
- `fluffydiscord_chatbot_site_key()` takes no argument.
- `CatalogChangeNotifier::notify()` takes the channel, not its code: `?string $channelCode` → `?ChannelInterface $channel`.
- Own `ChannelCredentialsProviderInterface`: `findForChannel()` gets the loaded channel, not its code:

  ```diff
  -public function findForChannel(string $channelCode): ?SiteCredentials
  +public function findForChannel(ChannelInterface $channel): ?SiteCredentials
  ```

- New `ChannelCredentialsProviderInterface $credentialsProvider` constructor parameter on `CatalogChangeNotifier`,
  `SiteKeyResolver`, `NotifyAllCommand`, `ChatOrderAttributionListener` and `ChatbotWidgetRuntime`; it replaces their
  `SiteKeyResolver` / site-key / channel-context parameters.
- SDK 2.0: `CatalogIngestClient::send()` and `TelemetryClient` take a `SiteCredentials` per call; their constructors
  lose the ingest secret.
