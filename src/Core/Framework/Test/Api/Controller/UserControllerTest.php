<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\Api\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Acl\Event\AclGetAdditionalPrivilegesEvent;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Api\OAuth\Scope\UserVerifiedScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Test\IdsCollection;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Response;

class UserControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    /**
     * @group slow
     */
    public function testMe(): void
    {
        $url = sprintf('/api/v%s/_info/me', PlatformRequest::API_VERSION);
        $client = $this->getBrowser();
        $client->request('GET', $url);

        static::assertSame(200, $client->getResponse()->getStatusCode());

        $content = json_decode($client->getResponse()->getContent(), true);

        static::assertArrayHasKey('attributes', $content['data']);
        static::assertSame('user', $content['data']['type']);
        static::assertSame('admin@example.com', $content['data']['attributes']['email']);
        static::assertNotNull($content['data']['relationships']['avatarMedia']);
    }

    public function testCreateUser(): void
    {
        $client = $this->getBrowser();
        $data = [
            'email' => 'foo@bar.com',
            'firstName' => 'Firstname',
            'lastName' => 'Lastname',
            'password' => 'password',
            'username' => 'foobar',
            'localeId' => $this->getContainer()->get(Connection::class)->fetchColumn('SELECT LOWER(HEX(id)) FROM locale LIMIT 1'),
        ];

        $client->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/user', $data);

        $response = $client->getResponse();
        static::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        static::assertArrayHasKey('errors', $content);
        static::assertEquals('This access token does not have the scope "user-verified" to process this Request', $content['errors'][0]['detail']);

        $this->getContainer()->get(Connection::class)
            ->executeUpdate("DELETE FROM user WHERE email = 'admin@example.com'");

        $this->kernelBrowser = null;
        $client = $this->getBrowser(true, [UserVerifiedScope::IDENTIFIER]);
        $client->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/user', $data);

        $response = $client->getResponse();
        static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function testRemoveRoleAssignment(): void
    {
        $ids = new IdsCollection();

        $user = [
            'id' => $ids->get('user'),
            'email' => 'foo@bar.com',
            'firstName' => 'Firstname',
            'lastName' => 'Lastname',
            'password' => 'password',
            'username' => 'foobar',
            'localeId' => $this->getContainer()->get(Connection::class)->fetchColumn('SELECT LOWER(HEX(id)) FROM locale LIMIT 1'),
            'aclRoles' => [
                ['id' => $ids->get('role-1'), 'name' => 'role-1'],
                ['id' => $ids->get('role-2'), 'name' => 'role-2'],
            ],
        ];

        $this->getContainer()->get('user.repository')
            ->create([$user], Context::createDefaultContext());

        $client = $this->getBrowser(true, [UserVerifiedScope::IDENTIFIER]);
        $client->request('DELETE', '/api/v' . PlatformRequest::API_VERSION . '/user/' . $ids->get('user') . '/acl-roles/' . $ids->get('role-1'));

        $response = $client->getResponse();
        $content = json_decode($response->getContent(), true);
        static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), print_r($content, true));

        $assigned = $this->getContainer()->get(Connection::class)
            ->fetchAll(
                'SELECT LOWER(HEX(acl_role_id)) as id FROM acl_user_role WHERE user_id = :id',
                ['id' => Uuid::fromHexToBytes($ids->get('user'))]
            );

        $assigned = array_column($assigned, 'id');
        static::assertEquals(array_values($ids->getList(['role-2'])), $assigned);
    }

    public function testDeleteUser(): void
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'email' => 'foo@bar.com',
            'firstName' => 'Firstname',
            'lastName' => 'Lastname',
            'password' => 'password',
            'username' => 'foobar',
            'localeId' => $this->getContainer()->get(Connection::class)->fetchColumn('SELECT LOWER(HEX(id)) FROM locale LIMIT 1'),
        ];

        $this->getContainer()->get('user.repository')
            ->create([$data], Context::createDefaultContext());

        $client = $this->getBrowser();
        $client->request('DELETE', '/api/v' . PlatformRequest::API_VERSION . '/user/' . $id);
        $response = $client->getResponse();
        static::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        static::assertArrayHasKey('errors', $content);
        static::assertEquals('This access token does not have the scope "user-verified" to process this Request', $content['errors'][0]['detail']);

        $this->getContainer()->get(Connection::class)
            ->executeUpdate("DELETE FROM user WHERE email = 'admin@example.com'");

        $this->kernelBrowser = null;
        $client = $this->getBrowser(true, [UserVerifiedScope::IDENTIFIER]);
        $client->request('DELETE', '/api/v' . PlatformRequest::API_VERSION . '/user/' . $id);

        $response = $client->getResponse();
        $content = json_decode($response->getContent(), true);
        static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), print_r($content, true));
    }

    public function testSetOwnProfilePrivilegesEvent(): void
    {
        Feature::skipTestIfInActive('FEATURE_NEXT_3722', $this);

        $getAdditionalPrivileges = function (AclGetAdditionalPrivilegesEvent $event): void {
            $privileges = $event->getPrivileges();
            static::assertContains('user:update_profile', $privileges);
            $privileges[] = 'my_custom_privilege';
            $event->setPrivileges($privileges);
        };
        $this->getContainer()->get('event_dispatcher')->addListener(AclGetAdditionalPrivilegesEvent::class, $getAdditionalPrivileges);

        $this->getBrowser()->request('PATCH', '/api/v' . PlatformRequest::API_VERSION . '/_info/me');
        $response = $this->getBrowser()->getResponse();
        $privileges = json_decode($response->getContent(), true);

        static::assertNotContains('unit:read', $privileges);
        static::assertContains('user_change_me', $privileges);
        static::assertContains('my_custom_privilege', $privileges);
    }

    public function testSetOwnProfileNoPermission(): void
    {
        Feature::skipTestIfInActive('FEATURE_NEXT_3722', $this);

        try {
            $this->authorizeBrowser($this->getBrowser(), [], []);
            $this->getBrowser()->request('PATCH', '/api/v' . PlatformRequest::API_VERSION . '/_info/me');
            $response = $this->getBrowser()->getResponse();

            static::assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $response->getContent());
            static::assertEquals(MissingPrivilegeException::MISSING_PRIVILEGE_ERROR, json_decode($response->getContent(), true)['errors'][0]['code'], $response->getContent());
        } finally {
            $this->resetBrowser();
        }
    }
}
