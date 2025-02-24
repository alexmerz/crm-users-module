<?php

namespace Crm\UsersModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\AddressesRepository;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class AddressesHandler extends ApiHandler
{
    private $userManager;

    private $addressesRepository;

    public function __construct(
        UserManager $userManager,
        AddressesRepository $addressesRepository
    ) {
        $this->userManager = $userManager;
        $this->addressesRepository = $addressesRepository;
    }

    public function params(): array
    {
        return [
            new InputParam(InputParam::TYPE_GET, 'email', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_GET, 'type', InputParam::OPTIONAL),
        ];
    }

    public function handle(array $params): ResponseInterface
    {
        $paramsProcessor = new ParamsProcessor($this->params());
        $params = $paramsProcessor->getValues();

        $user = $this->userManager->loadUserByEmail($params['email']);
        if (!$user) {
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, ['status' => 'error', 'message' => "user doesn't exist: {$params['email']}"]);
            return $response;
        }

        $type = null;
        if ($params['type']) {
            $type = $params['type'];
        }

        $addresses = $this->addressesRepository->addresses($user, $type);
        $addressesArray = [];
        foreach ($addresses as $address) {
            $addressesArray[] = [
                'user_id' => $user->id,
                'type' => $address->type,
                'created_at' => $address->created_at->format('c'),
                'email' => $user->email,
                'company_name' => $address->company_name,
                'phone_number' => $address->phone_number,
                'company_id' => $address->company_id,
                'tax_id' => $address->company_tax_id,
                'vat_id' => $address->company_vat_id,
                'first_name' => $address->first_name,
                'last_name' => $address->last_name,
                'address' => $address->address,
                'number' => $address->number,
                'zip' => $address->zip,
                'city' => $address->city,
                'country' => $address->country ? $address->country->name : null,
            ];
        }

        $result = [
            'status' => 'ok',
            'addresses' => $addressesArray,
        ];

        $response = new JsonApiResponse(Response::S200_OK, $result);
        return $response;
    }
}
