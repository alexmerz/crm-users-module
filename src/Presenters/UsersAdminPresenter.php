<?php

namespace Crm\UsersModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\User\DeleteUserData;
use Crm\UsersModule\AdminFilterFormData;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Components\Widgets\DetailWidgetFactoryInterface;
use Crm\UsersModule\DataProvider\FilterUsersFormDataProviderInterface;
use Crm\UsersModule\Events\AddressRemovedEvent;
use Crm\UsersModule\Events\NotificationEvent;
use Crm\UsersModule\Forms\AbusiveUsersFilterFormFactory;
use Crm\UsersModule\Forms\AdminUserGroupFormFactory;
use Crm\UsersModule\Forms\UserFormFactory;
use Crm\UsersModule\Forms\UserGroupsFormFactory;
use Crm\UsersModule\Forms\UserNoteFormFactory;
use Crm\UsersModule\Repository\AddressesRepository;
use Crm\UsersModule\Repository\CantDeleteAddressException;
use Crm\UsersModule\Repository\ChangePasswordsLogsRepository;
use Crm\UsersModule\Repository\GroupsRepository;
use Crm\UsersModule\Repository\PasswordResetTokensRepository;
use Crm\UsersModule\Repository\UserActionsLogRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette;
use Nette\Application\UI\Form;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapRenderer;

class UsersAdminPresenter extends AdminPresenter
{
    /** @persistent */
    public $formData = [];

    private $usersRepository;

    private $factory;

    private $groupsRepository;

    private $userGroupsFormFactory;

    private $adminUserGroupsFormFactory;

    private $userNoteFormFactory;

    private $addressesRepository;

    private $deleteUserData;

    private $dataProviderManager;

    private $userManager;

    private $changePasswordsLogsRepository;

    private $passwordResetTokensRepository;

    private $abusiveUsersFilterFormFactory;

    private $userActionsLogRepository;

    private $adminFilterFormData;

    public function __construct(
        AdminFilterFormData $adminFilterFormData,
        UsersRepository $usersRepository,
        UserFormFactory $userFormFactory,
        GroupsRepository $groupsRepository,
        UserGroupsFormFactory $userGroupsFormFactory,
        AdminUserGroupFormFactory $adminUserGroupsFormFactory,
        UserNoteFormFactory $userNoteFormFactory,
        AddressesRepository $addressesRepository,
        DeleteUserData $deleteUserData,
        DataProviderManager $dataProviderManager,
        UserManager $userManager,
        ChangePasswordsLogsRepository $changePasswordsLogsRepository,
        PasswordResetTokensRepository $passwordResetTokensRepository,
        AbusiveUsersFilterFormFactory $abusiveUsersFilterFormFactory,
        UserActionsLogRepository $userActionsLogRepository
    ) {
        parent::__construct();
        $this->usersRepository = $usersRepository;
        $this->factory = $userFormFactory;
        $this->groupsRepository = $groupsRepository;
        $this->userGroupsFormFactory = $userGroupsFormFactory;
        $this->adminUserGroupsFormFactory = $adminUserGroupsFormFactory;
        $this->userNoteFormFactory = $userNoteFormFactory;
        $this->addressesRepository = $addressesRepository;
        $this->deleteUserData = $deleteUserData;
        $this->dataProviderManager = $dataProviderManager;
        $this->userManager = $userManager;
        $this->changePasswordsLogsRepository = $changePasswordsLogsRepository;
        $this->passwordResetTokensRepository = $passwordResetTokensRepository;
        $this->abusiveUsersFilterFormFactory = $abusiveUsersFilterFormFactory;
        $this->userActionsLogRepository = $userActionsLogRepository;
        $this->adminFilterFormData = $adminFilterFormData;
    }

    public function startup()
    {
        parent::startup();
        $this->adminFilterFormData->parse($this->formData);
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $filteredCount = $this->adminFilterFormData->getFilteredUsers()->count('distinct(users.id)');

        $users = $this->adminFilterFormData->getFilteredUsers();

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($filteredCount);
        $paginator->setItemsPerPage($this->onPage);
        $this->template->filteredCount = $filteredCount;
        $this->template->vp = $vp;
        $this->template->users = $users->limit($paginator->getLength(), $paginator->getOffset());
        $this->template->totalUsers = $this->usersRepository->totalCount();
    }

    /**
     * @admin-access-level read
     */
    public function renderShow($id)
    {
        $user = $this->usersRepository->find($id);
        if (!$user) {
            throw new Nette\Application\BadRequestException();
        }
        $this->template->user = $user;
        $this->template->invoiceAddress = $this->addressesRepository->address($user, 'invoice');
        $this->template->printAddresses = array_filter($this->addressesRepository->addresses($user), function ($item) {
            return $item->type != 'invoice';
        });

        $this->template->lastSuspicious = $this->changePasswordsLogsRepository->lastUserLog($user->id, ChangePasswordsLogsRepository::TYPE_SUSPICIOUS);
        $this->template->canEditRoles = $this->getUser()->isAllowed('Users:AdminGroupAdmin', 'edit');
    }

    /**
     * @admin-access-level write
     */
    public function renderEdit($id)
    {
        $user = $this->usersRepository->find($id);
        if (!$user) {
            throw new Nette\Application\BadRequestException();
        }
        $this->template->user = $user;
    }

    /**
     * @admin-access-level write
     */
    public function renderNew()
    {
    }

    /**
     * @admin-access-level write
     */
    public function handleLogOut($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new Nette\Application\BadRequestException("User with id: {$userId} doesn't exist.");
        }

        $abusiveInformationSelection = $this->usersRepository->getAbusiveUsers(
            new DateTime('-1 month'),
            new DateTime(),
            1,
            1,
            'device_count',
            $user->email
        );
        $abusiveInformation = $abusiveInformationSelection->where('users.id = ?', $userId)->fetch();

        $this->userActionsLogRepository->add(
            $userId,
            'users.admin.logout_user',
            [
                'admin_email' => $this->user->getIdentity()->email,
                'active_logins' => $abusiveInformation->token_count ?? 0,
                'active_devices' => $abusiveInformation->device_count ?? 0,
            ]
        );

        $this->userManager->logoutUser($user);

        $this->presenter->flashMessage($this->translator->translate('users.admin.logout_user.all_devices'));
        $this->redirect('show', $userId);
    }

    /**
     * You need to process NotificationEvent in order to
     * send user email containing new password.
     *
     * @admin-access-level write
     *
     * @param $userId
     */
    public function handleResetPassword($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new Nette\Application\BadRequestException("User with id: {$userId} doesn't exist.");
        }

        $password = $this->userManager->resetPassword($user, null, false);

        $this->emitter->emit(new NotificationEvent($this->emitter, $user, 'admin_reset_password_with_password', [
            'email' => $user->email,
            'password' => $password
        ]));

        $this->presenter->flashMessage($this->translator->translate('users.admin.reset_password.success'));
        $this->redirect('show', $userId);
    }

    /**
     * @admin-access-level write
     */
    public function handleConfirm($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new Nette\Application\BadRequestException("User with id: {$userId} doesn't exist.");
        }

        $this->userManager->confirmUser($user, new DateTime(), true);
        $this->presenter->flashMessage($this->translator->translate('users.admin.confirm.success'));
    }

    public function createComponentUserForm()
    {
        $id = null;
        if (isset($this->params['id'])) {
            $id = $this->params['id'];
        }

        $form = $this->factory->create($id);
        $this->factory->onSave = function ($form, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_form.user_created'));
            $this->redirect('UsersAdmin:Show', $user->id);
        };
        $this->factory->onUpdate = function ($form, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_form.user_updated'));
            $this->redirect('UsersAdmin:Show', $user->id);
        };
        return $form;
    }

    public function createComponentAdminFilterForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);

        $mainGroup = $form->addGroup('main')->setOption('label', null);
        $collapseGroup = $form->addGroup('collapse', false)
            ->setOption('container', 'div class="collapse"')
            ->setOption('label', null)
            ->setOption('id', 'formCollapse');
        $buttonGroup = $form->addGroup('button', false)->setOption('label', null);

        $form->addText('text', $this->translator->translate('users.admin.admin_filter_form.text.label'))
            ->setHtmlAttribute('placeholder', $this->translator->translate('users.admin.admin_filter_form.text.placeholder'))
            ->setHtmlAttribute('autofocus');
        $form->addText('address', $this->translator->translate('users.admin.admin_filter_form.address.label'))
            ->setHtmlAttribute('placeholder', $this->translator->translate('users.admin.admin_filter_form.address.placeholder'));

        $form->setCurrentGroup($collapseGroup);

        $form->addSelect('group', $this->translator->translate('users.admin.admin_filter_form.group.label'), $this->groupsRepository->all()->fetchPairs('id', 'name'))
            ->setPrompt('--')
            ->getControlPrototype()->addAttributes(['class' => 'select2']);
        $form->addSelect('source', $this->translator->translate('users.admin.admin_filter_form.source.label'), $this->usersRepository->getUserSources())
            ->setPrompt('--')
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        /** @var FilterUsersFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('users.dataprovider.users_filter_form', FilterUsersFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form, 'formData' => $this->formData]);
        }

        $form->setCurrentGroup($buttonGroup);

        $form->addSubmit('send', $this->translator->translate('users.admin.admin_filter_form.submit'))
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('users.admin.admin_filter_form.submit'));
        $presenter = $this;
        $form->addSubmit('cancel', $this->translator->translate('users.admin.admin_filter_form.cancel_filter'))->onClick[] = function () use ($presenter, $form) {
            $emptyDefaults = array_fill_keys(array_keys((array) $form->getComponents()), null);
            $presenter->redirect('UsersAdmin:Default', ['formData' => $emptyDefaults]);
        };
        $form->addButton('more')
            ->setHtmlAttribute('data-toggle', 'collapse')
            ->setHtmlAttribute('data-target', '#formCollapse')
            ->setHtmlAttribute('class', 'btn btn-info')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fas fa-caret-down"></i> ' . $this->translator->translate('users.admin.admin_filter_form.more'));

        $form->onSuccess[] = [$this, 'adminFilterSubmitted'];

        $form->setDefaults($this->adminFilterFormData->getFormValues());
        return $form;
    }

    public function adminFilterSubmitted($form, $values)
    {
        $this->redirect($this->action, ['formData' => array_map(function ($item) {
            return $item ?: null;
        }, (array)$values)]);
    }

    public function createComponentUserGroupsForm()
    {
        if (!isset($this->params['id'])) {
            return null;
        }

        $form = $this->userGroupsFormFactory->create($this->params['id']);
        $this->userGroupsFormFactory->onAddedUserToGroup = function ($form, $group, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_groups_form.user_added') . ' ' . $group->name);
            $this->redirect('UsersAdmin:Show', $user->id);
        };
        $this->userGroupsFormFactory->onRemovedUserFromGroup = function ($form, $group, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_groups_form.user_removed') . ' ' . $group->name);
            $this->redirect('UsersAdmin:Show', $user->id);
        };

        return $form;
    }

    public function createComponentAdminUserGroupsForm()
    {
        if (!isset($this->params['id'])) {
            return null;
        }

        $user = $this->getUser();

        $form = $this->adminUserGroupsFormFactory->create($this->params['id']);
        $this->adminUserGroupsFormFactory->authorize = function () use ($user) {
            return $user->isAllowed('Users:AdminGroupAdmin', 'edit');
        };
        $this->adminUserGroupsFormFactory->onAddedUserToGroup = function ($form, $group, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_groups_form.user_added') . ' ' . $group->name);
            $this->redirect('UsersAdmin:Show', $user->id);
        };
        $this->adminUserGroupsFormFactory->onRemovedUserFromGroup = function ($form, $group, $user) {
            $this->flashMessage($this->translator->translate('users.admin.user_groups_form.user_removed') . ' ' . $group->name);
            $this->redirect('UsersAdmin:Show', $user->id);
        };

        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleChangeActivation($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new Nette\Application\BadRequestException();
        }

        $this->usersRepository->toggleActivation($user);

        $this->flashMessage($this->translator->translate('users.admin.change_activation.activated'));
        $this->redirect('UsersAdmin:Show', $user->id);
    }

    /**
     * @admin-access-level write
     */
    public function handleDeleteUser($id)
    {
        $user = $this->usersRepository->find($id);
        if (!$user) {
            throw new Nette\Application\BadRequestException();
        }

        // checking if customer can be deleted (e.g. due to active subscription within last three months - customer claim managment)
        [$canBeDeleted, $errors] = $this->deleteUserData->canBeDeleted($user->id);
        if ($canBeDeleted) {
            $this->deleteUserData->deleteData($user->id);
            $user = $this->usersRepository->find($id);
            $this->usersRepository->update($user, ['note' => $this->translator->translate('users.deletion_note.admin_deleted_account')]);
            $this->flashMessage($this->translator->translate('users.admin.delete_user.deleted'));
        } else {
            $this->flashMessage("<br/>" . implode("<br/>", $errors), 'error');
        }
        $this->redirect('UsersAdmin:Show', $user->id);
    }

    /**
     * @admin-access-level write
     */
    public function handleSuspicious($userId)
    {
        $user = $this->usersRepository->find($userId);
        if (!$user) {
            throw new Nette\Application\BadRequestException();
        }

        $abusiveInformationSelection = $this->usersRepository->getAbusiveUsers(
            new DateTime('-1 month'),
            new DateTime(),
            1,
            1,
            'device_count',
            $user->email
        );
        $abusiveInformation = $abusiveInformationSelection->where('users.id = ?', $userId)->fetch();

        $this->userActionsLogRepository->add(
            $userId,
            'users.admin.suspicious_account',
            [
                'admin_email' => $this->user->getIdentity()->email,
                'active_logins' => $abusiveInformation->token_count ?? 0,
                'active_devices' => $abusiveInformation->device_count ?? 0,
            ]
        );

        $this->userManager->suspiciousUser($user);

        $this->flashMessage("OK"); // todo preklady
        $this->redirect('show', $user->id);
    }

    /**
     * @admin-access-level read
     */
    public function renderExport()
    {
        $this->getHttpResponse()->addHeader('Content-Type', 'application/csv');
        $this->getHttpResponse()->addHeader('Content-Disposition', 'attachment; filename=export.csv');

        $this->template->users = $this->adminFilterFormData->getFilteredUsers()->limit(100000);
    }

    protected function createComponentDetailWidget(DetailWidgetFactoryInterface $factory)
    {
        $control = $factory->create();
        return $control;
    }

    public function createComponentUserNoteForm()
    {
        $userRow = $this->usersRepository->find($this->params['id']);
        $form = $this->userNoteFormFactory->create($userRow);
        $presenter = $this;
        $this->userNoteFormFactory->onUpdate = function ($form, $user) use ($presenter) {
            $presenter->flashMessage($this->translator->translate('users.admin.user_note_form.note_updated'));
            $presenter->redirect('UsersAdmin:Show', $user->id);
        };
        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleRemoveAddress($addressId)
    {
        $address = $this->addressesRepository->find($addressId);
        try {
            $this->addressesRepository->softDelete($address);
        } catch (CantDeleteAddressException $exception) {
            $this->flashMessage($exception->getMessage(), 'error');
            $this->redirect('this');
        }
        $this->emitter->emit(new AddressRemovedEvent($address));
    }
}
