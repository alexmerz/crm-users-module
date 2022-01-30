<?php

namespace Crm\UsersModule\Authenticator;

use Crm\ApplicationModule\Authenticator\AuthenticatorInterface;
use Crm\ApplicationModule\Authenticator\BaseAuthenticator;
use Crm\UsersModule\Auth\AutoLogin\AutoLogin;
use Crm\UsersModule\Auth\UserAuthenticator;
use Crm\UsersModule\Repository\LoginAttemptsRepository;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\IRow;
use Nette\Http\Request;
use Nette\Security\AuthenticationException;

/**
 * AutoLoginTokenAuthenticator authenticates user based on autologinToken.
 *
 * Required credentials (use setCredentials()):
 *
 * - 'autologinToken'
 */
class AutoLoginTokenAuthenticator extends BaseAuthenticator
{
    private $usersRepository;

    private $autoLogin;

    /** @var string */
    private $autologinToken = null;

    public function __construct(
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        Request $request,
        UsersRepository $usersRepository,
        AutoLogin $autoLogin
    ) {
        parent::__construct($emitter, $hermesEmitter, $request);

        $this->usersRepository = $usersRepository;
        $this->autoLogin = $autoLogin;
    }

    public function authenticate()
    {
        if ($this->autologinToken !== null) {
            return $this->process();
        }
        return false;
    }

    public function setCredentials(array $credentials) : AuthenticatorInterface
    {
        parent::setCredentials($credentials);

        $this->autologinToken = $credentials['autologin_token'] ?? null;

        return $this;
    }

    /**
     * @throws AuthenticationException
     */
    private function process() : IRow
    {
        $tokenRow = $this->autoLogin->getToken($this->autologinToken);

        if (isset($tokenRow->email)) {
            $user = $this->usersRepository->find($tokenRow->user_id);
            if (!$user) {
                $this->addAttempt($tokenRow->email, null, $this->source, LoginAttemptsRepository::STATUS_NOT_FOUND_EMAIL);
                throw new AuthenticationException('Invalid token', UserAuthenticator::IDENTITY_NOT_FOUND);
            }

            if ($user->role === UsersRepository::ROLE_ADMIN) {
                throw new AuthenticationException('Autologin for this account is disabled', UserAuthenticator::NOT_APPROVED);
            }

            $actualDate = new \DateTime();
            if (!($tokenRow->valid_from < $actualDate && $tokenRow->valid_to > $actualDate)) {
                $this->addAttempt($user->email, $user, $this->source, LoginAttemptsRepository::STATUS_TOKEN_DATE_EXPIRED);
                throw new AuthenticationException('Invalid token', UserAuthenticator::IDENTITY_NOT_FOUND);
            }

            if ($tokenRow->used_count >= $tokenRow->max_count) {
                $this->addAttempt($user->email, $user, $this->source, LoginAttemptsRepository::STATUS_TOKEN_COUNT_EXPIRED);
                throw new AuthenticationException('Token reached max users count', UserAuthenticator::IDENTITY_NOT_FOUND);
            }

            $this->autoLogin->incrementTokenUse($tokenRow);
            $this->addAttempt($user->email, $user, $this->source, LoginAttemptsRepository::STATUS_TOKEN_OK);
            return $user;
        } else {
            throw new AuthenticationException('Invalid token', UserAuthenticator::IDENTITY_NOT_FOUND);
        }
    }
}
