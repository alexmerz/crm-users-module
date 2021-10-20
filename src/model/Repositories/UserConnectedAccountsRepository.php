<?php

namespace Crm\UsersModule\Repository;

use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;

class UserConnectedAccountsRepository extends Repository
{
    public const TYPE_APPLE_SIGN_IN = 'apple_sign_in';

    public const TYPE_GOOGLE_SIGN_IN = 'google_sign_in';

    protected $tableName = 'user_connected_accounts';

    public function __construct(
        Explorer $database,
        AuditLogRepository $auditLogRepository
    ) {
        parent::__construct($database);
        $this->database = $database;
        $this->auditLogRepository = $auditLogRepository;
    }

    final public function add(
        ActiveRow $user,
        string $type,
        string $externalId,
        ?string $email,
        $meta = null
    ) {
        return $this->insert([
            'user_id' => $user->id,
            'external_id' => $externalId,
            'email' => $email,
            'type' => $type,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
            'meta' => $meta ? Json::encode($meta) : null,
        ]);
    }

    final public function getByExternalId(string $type, string $externalId)
    {
        return $this->getTable()->where([
            'external_id' => $externalId,
            'type' => $type,
        ])->fetch();
    }

    final public function getForUser(ActiveRow $user, string $type)
    {
        return $this->getTable()->where([
            'user_id' => $user->id,
            'type' => $type,
        ])->fetch();
    }

    public function removeAccountsForUser(ActiveRow $user): int
    {
        return $this->getTable()
            ->where(['user_id' => $user->id])
            ->delete();
    }

    public function removeAccountForUser(ActiveRow $user, int $id): int
    {
        return $this->getTable()
            ->where([
                'user_id' => $user->id,
                'id' => $id
            ])
            ->delete();
    }

    public function connectUser(ActiveRow $user, $type, $externalId, $email, $meta = null)
    {
        $connectedAccount = $this->getForUser($user, $type);
        if (!$connectedAccount) {
            $connectedAccount = $this->add(
                $user,
                $type,
                $externalId,
                $email,
                $meta
            );
        }

        return $connectedAccount;
    }
}
