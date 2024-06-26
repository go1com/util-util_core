<?php

namespace go1\util\tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use go1\util\AccessChecker;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\Text;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class AccessCheckerTest extends UtilCoreTestCase
{
    use PortalMockTrait;
    use UserMockTrait;

    public function testValidAccount()
    {
        $portalId = $this->createPortal($this->go1, ['title' => 'qa.mygo1.com']);
        $userId = $this->createUser($this->go1, ['instance' => 'accounts.gocatalyze.com']);
        $accountId = $this->createUser($this->go1, ['instance' => 'qa.mygo1.com']);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);

        $req = new Request();
        $jwt = $this->jwtForUser($this->go1, $userId, 'qa.mygo1.com');
        $payload = Text::jwtContent($jwt);
        $req->attributes->set('jwt.payload', $payload);
        $service = new AccessChecker();

        {
            // context portal
            $portal = $service->contextPortal($req);
            $this->assertEquals('qa.mygo1.com', $portal->title);
            $this->assertEquals($portalId, $portal->id);
        }

        {
            // check by name
            $account = $service->validAccount($req, 'qa.mygo1.com');
            $this->assertEquals($account->id, $accountId);
        }

        {
            // check by ID
            $account = $service->validAccount($req, $portalId);
            $this->assertEquals($account->id, $accountId);
        }

        {
            // test usedCreds
            $this->assertFalse($service->usedCredentials($req));

            $jwt = $this->jwtForUser($this->go1, $userId, 'qa.mygo1.com');
            $payload = Text::jwtContent($jwt);
            $payload->usedCreds = 1;
            $req->attributes->set('jwt.payload', $payload);
            $this->assertTrue($service->usedCredentials($req));
        }
    }

    public function testIsStudentManager()
    {
        $manager2Id = $this->createUser($this->go1, ['mail' => $manager2Mail = 'manager2@mail.com', 'instance' => $accountsName = 'accounts.gocatalyze.com']);
        $managerId = $this->createUser($this->go1, ['mail' => $managerMail = 'manager@mail.com', 'instance' => $accountsName = 'accounts.gocatalyze.com']);
        $studentId = $this->createUser($this->go1, ['mail' => $studentMail = 'student@mail.com', 'instance' => $portalName = 'portal.mygo1.com']);
        $this->link($this->go1, EdgeTypes::HAS_MANAGER, $studentId, $managerId);

        # Is manager
        $req = new Request();
        $req->attributes->set('jwt.payload', $this->getPayload(['id' => $managerId, 'mail' => $managerMail]));
        $this->assertTrue((new AccessChecker())->isStudentManager($this->go1, $req, $studentMail, $portalName, EdgeTypes::HAS_MANAGER));

        # Is not manager
        $req = new Request();
        $req->attributes->set('jwt.payload', $this->getPayload(['id' => $manager2Id, 'mail' => $manager2Mail]));
        $this->assertFalse((new AccessChecker())->isStudentManager($this->go1, $req, $studentMail, $portalName, EdgeTypes::HAS_MANAGER));
    }

    public function testPortalAdminWithInheritance()
    {
        $portalId = $this->createPortal($this->go1, [$portalName = 'admin.go1.co']);
        $userId = $this->createUser($this->go1, ['mail' => $mail = 'duy.nguyen@go1.com', 'instance' => $accountsName = 'accounts.gocatalyze.com', 'data' => ['roles' => [Roles::ROOT]]]);
        $accountId = $this->createUser($this->go1, ['mail' => $mail, 'instance' => $portalName, 'data' => ['roles' => [Roles::ADMIN]]]);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->link($this->go1, EdgeTypes::HAS_ROLE, $userId, $this->createAccountsAdminRole($this->go1, ['instance' => $accountsName]));
        $this->link($this->go1, EdgeTypes::HAS_ROLE, $accountId, $this->createPortalAdminRole($this->go1, ['instance' => $portalName]));

        $req = new Request();
        $access = new AccessChecker();
        $req->attributes->set('jwt.payload', JWT::decode($this->jwtForUser($this->go1, $userId, $portalName), new Key('INTERNAL', 'HS256')));
        $this->assertTrue((bool) $access->isPortalAdmin($req, $portalName));
        $this->assertTrue((bool) $access->isPortalAdmin($req, $portalId));
        $this->assertTrue((bool) $access->isPortalAdmin($req, $portalName), false);
        $this->assertTrue((bool) $access->isPortalAdmin($req, $portalId), false);
        $this->assertTrue((bool) $access->isContentAdministrator($req, $portalName));
        $this->assertFalse((bool) $access->isContentAdministrator($req, $portalName, false));
    }

    public function testPortalContentAdminWithInheritance()
    {
        $userId = $this->createUser($this->go1, [
            'mail'     => $mail = 'duy.nguyen@go1.com',
            'instance' => $accountsName = 'accounts.gocatalyze.com',
            'data'     => ['roles' => [Roles::ROOT]],
        ]);
        $accountId = $this->createUser($this->go1, [
            'mail'     => $mail,
            'instance' => $portalName = 'content-admin.go1.co',
            'data'     => ['roles' => [Roles::ADMIN_CONTENT]],
        ]);

        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->link($this->go1, EdgeTypes::HAS_ROLE, $userId, $this->createAccountsAdminRole($this->go1, ['instance' => $accountsName]));
        $this->link($this->go1, EdgeTypes::HAS_ROLE, $portalName, $this->createPortalContentAdminRole($this->go1, ['instance' => $portalName]));

        $req = new Request();
        $accessChecker = new AccessChecker();
        $req->attributes->set('jwt.payload', JWT::decode($this->jwtForUser($this->go1, $userId, $portalName), new Key('INTERNAL', 'HS256')));
        $this->assertTrue((bool) $accessChecker->isPortalAdmin($req, $portalName));
        $this->assertFalse((bool) $accessChecker->isPortalAdmin($req, $portalName, Roles::ADMIN, false));
        $this->assertTrue((bool) $accessChecker->isContentAdministrator($req, $portalName));
        $this->assertTrue((bool) $accessChecker->isContentAdministrator($req, $portalName, Roles::ADMIN, false));
    }

    public function testGetAccessToken()
    {
        $sessionToken = '2235e435f2015211bb1e633a677a690844a0cc55aec409fb1aa84f9210a96373271d2fda1e62cd96192bec475cd664103a6edadd508276880b9db3bd5c992199';

        $req = new Request();
        $accessChecker = new AccessChecker();
        $this->assertNull($accessChecker->sessionToken($req));

        $req->attributes->set('jwt.payload', (object) ['sid' => $sessionToken]);
        $this->assertEquals($sessionToken, $accessChecker->sessionToken($req));
    }

    public function testIsRequestVerified()
    {
        $scope = [
            'name'    => 'player',
            'context' => [
                'id' => 123,
            ],
        ];

        {
            $req = new Request();
            $this->assertFalse(AccessChecker::isRequestVerified($req, $scope));
        }

        {
            $req = new Request();
            $req->headers->set('X-ACCESS', 'verified');
            $this->assertFalse(AccessChecker::isRequestVerified($req, $scope));
        }

        {
            $req = new Request();
            $req->headers->set('X-ACCESS', 'unverified');
            $this->assertFalse(AccessChecker::isRequestVerified($req, $scope));
        }

        {
            $jwt = JWT::encode(['sid' => 'SID', 'exp' => strtotime('+ 15 minutes'), 'scope' => $scope], 'INTERNAL', 'HS256');
            $req = new Request();
            $req->attributes->set('jwt.payload', JWT::decode($jwt, new Key('INTERNAL', 'HS256')));
            $req->headers->set('X-ACCESS', 'verified');
            $this->assertTrue(AccessChecker::isRequestVerified($req, $scope));
        }
    }

    public function testNegativeUserId()
    {
        $portalId = $this->createPortal($this->go1, ['title' => 'qa.mygo1.com']);
        $userId = $this->createUser($this->go1, ['id' => -100, 'instance' => 'accounts.gocatalyze.com']);
        $accountId = $this->createUser($this->go1, ['instance' => 'qa.mygo1.com']);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);

        $req = new Request();
        $jwt = $this->jwtForUser($this->go1, $userId, 'qa.mygo1.com');
        $payload = Text::jwtContent($jwt);
        $req->attributes->set('jwt.payload', $payload);
        $service = new AccessChecker();

        {
            $user = $service->validUser($req);
            $this->assertFalse($user->id < 0);
        }
    }
}
