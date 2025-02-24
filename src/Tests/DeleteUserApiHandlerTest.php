<?php

namespace Crm\UsersModule\Tests;

use Crm\ApiModule\Api\EmptyResponse;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\ApplicationModule\User\UserDataProviderInterface;
use Crm\ApplicationModule\User\UserDataRegistrator;
use Crm\UsersModule\Api\DeleteUserApiHandler;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Tomaj\NetteApi\Response\JsonApiResponse;

class DeleteUserApiHandlerTest extends DatabaseTestCase
{
    const EMAIL = 'testdeleteuser@example.com';

    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var UsersRepository */
    private $usersRepository;

    /** @var UserDataRegistrator */
    private $userDataRegistrator;

    /** @var DeleteUserApiHandler */
    private $handler;

    private $user;

    protected function requiredRepositories(): array
    {
        return [
            AccessTokensRepository::class,
            UsersRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userDataRegistrator = $this->inject(UserDataRegistrator::class);
        $this->handler = $this->inject(DeleteUserApiHandler::class);
    }

    public function testSuccessfulDeleteUser()
    {
        $user = $this->getUser();
        $accessToken = $this->accessTokensRepository->add($user);

        $userDataProviderMock = \Mockery::mock(UserDataProviderInterface::class);
        $userDataProviderMock->shouldReceive('canBeDeleted')->andReturn([true, null]);
        $userDataProviderMock->shouldReceive('delete')->once();
        $userDataProviderMock->shouldIgnoreMissing();

        $this->userDataRegistrator->addUserDataProvider($userDataProviderMock, 10);
        $this->handler->setAuthorization(new TestUserTokenAuthorization($accessToken, $user));
        $response = $this->handler->handle([]); // TODO: fix params

        $this->assertEquals(EmptyResponse::class, get_class($response));
        $this->assertEquals(204, $response->getCode());
    }

    public function testDeleteProtectedUserError()
    {
        $user = $this->getUser();
        $accessToken = $this->accessTokensRepository->add($user);

        /** @var UserDataProviderInterface $userDataProviderMock */
        $userDataProviderMock = \Mockery::mock(UserDataProviderInterface::class)
            ->shouldReceive('canBeDeleted')
            ->andReturn([false, "err"])
            ->getMock();
        $this->userDataRegistrator->addUserDataProvider($userDataProviderMock, 20);

        $this->handler->setAuthorization(new TestUserTokenAuthorization($accessToken, $user));
        $response = $this->handler->handle([]); // TODO: fix params

        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(403, $response->getCode());

        $userFound = $this->usersRepository->findBy('email', self::EMAIL);
        $this->assertNotEmpty($userFound);
    }

    private function getUser()
    {
        if (!$this->user) {
            $this->user = $this->usersRepository->add(self::EMAIL, 'nbusr123');
        }
        return $this->user;
    }
}
