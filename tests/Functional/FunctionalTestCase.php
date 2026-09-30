<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class FunctionalTestCase extends WebTestCase
{
    protected const CLIENT_ID = 'app1';
    protected const CLIENT_SECRET = 'app1-secret';
    protected const REDIRECT_URI = 'https://app1.mydomain.test/oauth/callback';
    protected const PASSWORD = 'Un-mot-de-passe-solide-42';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createApplication(string $id = self::CLIENT_ID, string $name = 'Application 1', bool $openRegistration = false): Application
    {
        $hasher = static::getContainer()->get('league.oauth2_server.password_hasher');
        $application = (new Application($name, $id, $hasher->hash(self::CLIENT_SECRET)))
            ->setHomeUrl('https://app1.mydomain.test/')
            ->setOpenRegistration($openRegistration)
            ->setRedirectUris(new RedirectUri(self::REDIRECT_URI))
            ->setGrants(new Grant(OAuth2Grants::AUTHORIZATION_CODE), new Grant(OAuth2Grants::REFRESH_TOKEN));
        $this->em()->persist($application);
        $this->em()->flush();

        return $application;
    }

    protected function createUser(string $email = 'alice@example.com', array $applications = [], array $roles = []): User
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user = (new User())->setEmail($email)->setDisplayName('Alice')->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        foreach ($applications as $application) {
            $user->addApplication($application);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function authorizeUrl(string $clientId = self::CLIENT_ID): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'profile email',
            'state' => 'xyz',
        ]);
    }

    protected function login(string $email = 'alice@example.com', string $password = self::PASSWORD): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }
}
