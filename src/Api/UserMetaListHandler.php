<?php

namespace Crm\UsersModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\UsersModule\Auth\UsersApiAuthorizationInterface;
use Crm\UsersModule\Repository\UserMetaRepository;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class UserMetaListHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $userMetaRepository;

    public function __construct(
        UserMetaRepository $userMetaRepository
    ) {
        $this->userMetaRepository = $userMetaRepository;
    }

    public function params(): array
    {
        return [
            new InputParam(InputParam::TYPE_GET, 'key', InputParam::OPTIONAL),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $authorization = $this->getAuthorization();
        if (!($authorization instanceof UsersApiAuthorizationInterface)) {
            throw new \Exception("Wrong authorization service used. Should be 'ServiceTokenAuthorization'");
        }

        $paramsProcessor = new ParamsProcessor($this->params());
        $params = $paramsProcessor->getValues();

        $key = $params['key'] ?? null;

        $userMetaRows = [];
        foreach ($authorization->getAuthorizedUsers() as $authorizedUser) {
            $query = $this->userMetaRepository
                ->userMetaRows($authorizedUser)
                ->where(['is_public' => true]);

            if ($key !== null) {
                $query->where('key = ?', $key);
            }
            $userMetaRows[] = $query->fetchAll();
        }
        $userMetaRows = array_merge([], ...$userMetaRows);
        usort($userMetaRows, function ($a, $b) {
            return $a['created_at'] < $b['created_at'];
        });

        $meta = array_map(function ($data) {
            return [
                'user_id' => $data->user_id,
                'key' => $data->key,
                'value' => $data->value,
            ];
        }, array_values($userMetaRows));

        $response = new JsonApiResponse(Response::S200_OK, $meta);
        return $response;
    }
}
