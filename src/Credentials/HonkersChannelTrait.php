<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Credentials;

use Doctrine\ORM\Mapping\Column;

trait HonkersChannelTrait
{
    /**
     * @Column(name="honkers_site_key", type="string", length=64, nullable=true)
     */
    #[Column(name: 'honkers_site_key', type: 'string', length: 64, nullable: true)]
    private ?string $honkersSiteKey = null;

    /**
     * @Column(name="honkers_api_secret_hash", type="string", length=64, nullable=true, options={"fixed": true})
     */
    #[Column(name: 'honkers_api_secret_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $honkersApiSecretHash = null;

    /**
     * @Column(name="honkers_ingest_secret", type="string", length=128, nullable=true)
     */
    #[Column(name: 'honkers_ingest_secret', type: 'string', length: 128, nullable: true)]
    private ?string $honkersIngestSecret = null;

    public function getHonkersSiteKey(): ?string
    {
        return $this->honkersSiteKey;
    }

    public function setHonkersSiteKey(?string $honkersSiteKey): void
    {
        $this->honkersSiteKey = $honkersSiteKey;
    }

    public function setHonkersApiSecret(#[\SensitiveParameter] string $plainApiSecret): void
    {
        $this->honkersApiSecretHash = hash('sha256', $plainApiSecret);
    }

    public function getHonkersIngestSecret(): ?string
    {
        return $this->honkersIngestSecret;
    }

    public function setHonkersIngestSecret(#[\SensitiveParameter] ?string $honkersIngestSecret): void
    {
        $this->honkersIngestSecret = $honkersIngestSecret;
    }
}
