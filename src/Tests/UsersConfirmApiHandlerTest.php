<?php

namespace Crm\UsersModule\Tests;

use Crm\ApiModule\Authorization\NoAuthorization;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\UsersModule\Api\UsersConfirmApiHandler;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\Seeders\UsersSeeder;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;

class UsersConfirmApiHandlerTest extends DatabaseTestCase
{
    /** @var UsersConfirmApiHandler */
    private $handler;

    protected function requiredSeeders(): array
    {
        return [
            UsersSeeder::class
        ];
    }

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->inject(UsersConfirmApiHandler::class);
    }

    public function testConfirmUserNoEmail()
    {
        $this->handler->setAuthorization(new NoAuthorization());
        $response = $this->handler->handle([]); // TODO: fix params

        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(Response::S400_BAD_REQUEST, $response->getCode());

        $payload = $response->getPayload();
        $this->assertEquals('error', $payload['status']);
    }

    public function testConfirmUserUserNotFound()
    {
        $_POST['email'] = '0test@user.site';

        $this->handler->setAuthorization(new NoAuthorization());
        $response = $this->handler->handle([]); // TODO: fix params

        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(Response::S404_NOT_FOUND, $response->getCode());

        $payload = $response->getPayload();
        $this->assertEquals('error', $payload['status']);
        $this->assertEquals('user_not_found', $payload['code']);
    }

    public function testConfirmUserUserFound()
    {
        $_POST['email'] = 'admin@admin.sk';

        $this->handler->setAuthorization(new NoAuthorization());
        $response = $this->handler->handle([]); // TODO: fix params

        $this->assertEquals(JsonApiResponse::class, get_class($response));
        $this->assertEquals(Response::S200_OK, $response->getCode());

        $payload = $response->getPayload();
        $this->assertEquals('ok', $payload['status']);
    }
}
