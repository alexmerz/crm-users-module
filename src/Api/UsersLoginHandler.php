<?php

namespace Crm\UsersModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\UsersModule\Auth\UserAuthenticator;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Nette\Security\AuthenticationException;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class UsersLoginHandler extends ApiHandler
{
    private $userAuthenticator;

    private $accessTokensRepository;

    private $deviceTokensRepository;

    private $translator;

    public function __construct(
        UserAuthenticator $userAuthenticator,
        AccessTokensRepository $accessTokensRepository,
        DeviceTokensRepository $deviceTokensRepository,
        Translator $translator
    ) {
        $this->userAuthenticator = $userAuthenticator;
        $this->accessTokensRepository = $accessTokensRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
        $this->translator = $translator;
    }

    public function params(): array
    {
        return [
            new InputParam(InputParam::TYPE_POST, 'email', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'password', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'source', InputParam::OPTIONAL),
            new InputParam(InputParam::TYPE_POST, 'device_token', InputParam::OPTIONAL),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $paramsProcessor = new ParamsProcessor($this->params());

        $params = $paramsProcessor->getValues();

        if (!isset($params['source']) && isset($_GET['source'])) {
            $params['source'] = $_GET['source'];
        }

        if (!$params['email']) {
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, ['status' => 'error', 'error' => 'no_email', 'message' => 'No valid email', 'code' => 'invalid_email']);
            return $response;
        }

        if (!$params['password']) {
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, ['status' => 'error', 'error' => 'no_password', 'message' => 'No valid password', 'code' => 'invalid_password']);
            return $response;
        }

        $deviceToken = false;
        if (!empty($params['device_token'])) {
            $deviceToken = $this->deviceTokensRepository->findByToken($params['device_token']);
            if (!$deviceToken) {
                $response = new JsonApiResponse(Response::S404_NOT_FOUND, [
                    'status' => 'error',
                    'message' => 'Device token doesn\'t exist',
                    'code' => 'device_token_doesnt_exist'
                ]);
                return $response;
            }
        }

        try {
            $source = 'api';
            if ($params['source'] && $params['source'] != 'api') {
                $source .= '+' . $params['source'];
            }
            $identity = $this->userAuthenticator->authenticate([
                'username' => $params['email'],
                'password' => $params['password'],
                'source' => $source,
            ]);
        } catch (AuthenticationException $authException) {
            $message = $authException->getMessage();
            $code = 'auth_failed';
            if (in_array($authException->getCode(), [UserAuthenticator::IDENTITY_NOT_FOUND, UserAuthenticator::NOT_APPROVED], true)) {
                $message = $this->translator->translate('users.api.users_login_handler.identity_not_found');
                $code = 'identity_not_found';
            } elseif ($authException->getCode() === UserAuthenticator::INVALID_CREDENTIAL) {
                $message = $this->translator->translate('users.api.users_login_handler.invalid_credentials');
                $code = 'invalid_credential';
            }
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, ['status' => 'error', 'error' => 'auth_failed', 'message' => $message, 'code' => $code]);
            return $response;
        }

        $result = [
            'status' => 'ok',
            'user' => [
                'id' => $identity->id,
                'email' => $identity->data['email'],
                'first_name' => $identity->data['first_name'],
                'last_name' => $identity->data['last_name'],
                'confirmed_at' => $identity->data['confirmed_at'] ? $identity->data['confirmed_at']->format(DATE_RFC3339) : null,
            ],
        ];

        if ($identity->getRoles()) {
            $result['user']['roles'] = $identity->getRoles();
        }

        $lastToken = $this->accessTokensRepository->allUserTokens($identity->id)->limit(1)->fetch();

        if ($lastToken && $deviceToken) {
            $this->accessTokensRepository->pairWithDeviceToken($lastToken, $deviceToken);
        }

        if ($lastToken) {
            $result['access']['token'] = $lastToken->token;
        }

        $response = new JsonApiResponse(Response::S200_OK, $result);
        return $response;
    }
}
