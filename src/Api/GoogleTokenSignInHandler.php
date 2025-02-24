<?php

namespace Crm\UsersModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\UsersModule\Auth\Sso\GoogleSignIn;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Application\LinkGenerator;
use Nette\Database\Table\ActiveRow;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Tomaj\NetteApi\Params\PostInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Implements validation of Google Token ID
 * see: https://developers.google.com/identity/sign-in/web/backend-auth
 *
 * @package Crm\UsersModule\Api
 */
class GoogleTokenSignInHandler extends ApiHandler
{
    private $googleSignIn;

    private $accessTokensRepository;

    private $deviceTokensRepository;

    private $usersRepository;

    public function __construct(
        GoogleSignIn $googleSignIn,
        AccessTokensRepository $accessTokensRepository,
        DeviceTokensRepository $deviceTokensRepository,
        UsersRepository $usersRepository,
        LinkGenerator $linkGenerator
    ) {
        $this->googleSignIn = $googleSignIn;
        $this->accessTokensRepository = $accessTokensRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
        $this->usersRepository = $usersRepository;
        $this->linkGenerator = $linkGenerator;
    }

    public function params(): array
    {
        return [
            (new PostInputParam('id_token'))->setRequired(),
            new PostInputParam('create_access_token'),
            new PostInputParam('device_token'),
            new PostInputParam('gsi_auth_code'),
            new PostInputParam('is_web'),
            new PostInputParam('source'),
            new PostInputParam('locale'),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $idToken = $params['id_token'];
        $createAccessToken = filter_var($params['create_access_token'], FILTER_VALIDATE_BOOLEAN) ?? false;
        $gsiAuthCode = $params['gsi_auth_code'] ?? null;
        $isWeb = filter_var($params['is_web'], FILTER_VALIDATE_BOOLEAN) ?? false;

        $deviceToken = null;
        if (!empty($params['device_token'])) {
            if (!$createAccessToken) {
                $response = new JsonApiResponse(IResponse::S400_BAD_REQUEST, [
                    'status' => 'error',
                    'code' => 'no_access_token_to_pair_device_token',
                    'message' => 'There is no access token to pair with device token. Set parameter "create_access_token=true" in your request payload.'
                ]);
                return $response;
            }

            $deviceToken = $this->deviceTokensRepository->findByToken($params['device_token']);
            if (!$deviceToken) {
                $response = new JsonApiResponse(IResponse::S404_NOT_FOUND, [
                    'status' => 'error',
                    'message' => 'Device token doesn\'t exist',
                    'code' => 'device_token_doesnt_exist'
                ]);
                return $response;
            }
        }

        // If user provides auth_code, use it to load Google access_token and id_token (replaces one from parameters)
        $gsiAccessToken = null;
        if ($gsiAuthCode) {
            // 'postmessage' is a reserved URI string in Google-land. Use it in case auth_code was requested from web surface.
            // Otherwise, use standard callback URI also used in Google presenter.
            // @see https://github.com/googleapis/google-auth-library-php/blob/21dd478e77b0634ed9e3a68613f74ed250ca9347/src/OAuth2.php#L777
            $redirectUri = $isWeb ? 'postmessage' : $this->linkGenerator->link('Users:Google:Callback');

            try {
                $creds = $this->googleSignIn->exchangeAuthCode($gsiAuthCode, $redirectUri);
                if (!isset($creds['id_token']) || !isset($creds['access_token'])) {
                    // do not break login process if access_token is invalid (and id_token possibly valid)
                    Debugger::log('Unable to exchange auth code for access_token and id_token, creds: ' . Json::encode($creds), ILogger::ERROR);
                } else {
                    $idToken = $creds['id_token'];
                    $gsiAccessToken = $creds['access_token'];
                }
            } catch (\Exception $e) {
                // do not break login process if e.g. network call fails
                Debugger::log($e->getMessage(), Debugger::EXCEPTION);
            }
        }

        $user = $this->googleSignIn->signInUsingIdToken($idToken, $gsiAccessToken, null, $params['source'] ?? null, $params['locale'] ?? null);

        if (!$user) {
            $response = new JsonApiResponse(IResponse::S400_BAD_REQUEST, [
                'status' => 'error',
                'code' => 'error_verifying_id_token',
                'message' => 'Unable to verify ID token',
            ]);
            return $response;
        }

        $accessToken = null;
        if ($createAccessToken) {
            $accessToken = $this->accessTokensRepository->add($user, 3, GoogleSignIn::ACCESS_TOKEN_SOURCE_WEB_GOOGLE_SSO);
            if ($deviceToken) {
                $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);
            }
        }

        $result = $this->formatResponse($user, $accessToken);
        $response = new JsonApiResponse(IResponse::S200_OK, $result);
        return $response;
    }

    private function formatResponse(ActiveRow $user, ?ActiveRow $accessToken): array
    {
        $user = $this->usersRepository->find($user->id);
        $result = [
            'status' => 'ok',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'created_at' => $user->created_at->format(\DateTimeInterface::RFC3339),
                'confirmed_at' => $user->confirmed_at ? $user->confirmed_at->format(\DateTimeInterface::RFC3339) : null,
            ],
        ];

        if ($accessToken) {
            $result['access']['token'] = $accessToken->token;
        }
        return $result;
    }
}
