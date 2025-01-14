<?php

namespace Crm\UsersModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class GetDeviceTokenApiHandler extends ApiHandler
{
    private $accessTokensRepository;

    private $deviceTokensRepository;

    public function __construct(
        AccessTokensRepository $accessTokensRepository,
        DeviceTokensRepository $deviceTokensRepository
    ) {
        $this->accessTokensRepository = $accessTokensRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
    }

    public function params(): array
    {
        return [
            new InputParam(InputParam::TYPE_POST, 'device_id', InputParam::REQUIRED),

            new InputParam(InputParam::TYPE_POST, 'access_token', InputParam::OPTIONAL),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $paramsProcessor = new ParamsProcessor($this->params());
        $error = $paramsProcessor->hasError();
        if ($error) {
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                'status' => 'error',
                'message' => 'Wrong input - ' . $error
            ]);
            return $response;
        }

        $params = $paramsProcessor->getValues();

        $accessToken = null;
        if (isset($params['access_token'])) {
            $accessToken = $this->accessTokensRepository->loadToken($params['access_token']);
            if (!$accessToken) {
                $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                    'status' => 'error',
                    'message' => 'Access token not valid'
                ]);
                return $response;
            }
        }

        $deviceToken = $this->deviceTokensRepository->generate($params['device_id']);
        if ($accessToken) {
            $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);
        }

        $response = new JsonApiResponse(Response::S200_OK, [
            'device_token' => $deviceToken->token
        ]);
        return $response;
    }
}
