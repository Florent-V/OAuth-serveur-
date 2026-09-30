<?php

namespace App\Entity;

use App\Repository\ApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une application protégée par le serveur OAuth2 (= un client OAuth2).
 *
 * Les champs OAuth2 standards (name, secret, redirectUris, grants, scopes, active...)
 * sont hérités d'AbstractClient et mappés par le bundle league/oauth2-server-bundle.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'oauth2_client')]
class Application extends AbstractClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    protected string $identifier;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** URL d'accueil de l'application (ex. https://app1.mydomain.com), affichée sur le portail. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: false)]
    private ?string $homeUrl = null;

    /**
     * Si vrai, un utilisateur qui s'inscrit depuis cette application obtient automatiquement l'accès.
     * Sinon, un administrateur doit lui attribuer l'application.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $openRegistration = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Uniquement utilisé à la création (admin) : client public sans secret (SPA, mobile → PKCE obligatoire). */
    private bool $publicClient = false;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'applications')]
    private Collection $users;

    public function __construct(string $name, string $identifier, ?string $secret)
    {
        parent::__construct($name, $identifier, $secret);
        $this->createdAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
    }

    /**
     * Uniquement avant la première persistance (l'identifiant est la clé primaire).
     */
    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = trim((string) $identifier);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getHomeUrl(): ?string
    {
        return $this->homeUrl;
    }

    public function setHomeUrl(?string $homeUrl): static
    {
        $this->homeUrl = $homeUrl ?: null;

        return $this;
    }

    public function isOpenRegistration(): bool
    {
        return $this->openRegistration;
    }

    public function setOpenRegistration(bool $openRegistration): static
    {
        $this->openRegistration = $openRegistration;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->addApplication($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            $user->removeApplication($this);
        }

        return $this;
    }

    public function isPublicClient(): bool
    {
        return $this->publicClient;
    }

    public function setPublicClient(bool $publicClient): static
    {
        $this->publicClient = $publicClient;

        return $this;
    }

    /**
     * Redirect URIs sous forme de texte (une par ligne), pour les formulaires d'administration.
     */
    public function getRedirectUrisText(): string
    {
        return implode("\n", array_map(static fn (RedirectUri $uri): string => (string) $uri, $this->getRedirectUris()));
    }

    public function setRedirectUrisText(?string $text): static
    {
        $lines = preg_split('/[\s,]+/', (string) $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $this->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), array_values(array_unique($lines))));

        return $this;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
