<?php

namespace Crm\UsersModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\UsersModule\Repository\LoginAttemptsRepository;
use Nette\Application\UI\Form;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapRenderer;

class LoginAttemptsAdminPresenter extends AdminPresenter
{
    /** @persistent */
    public $created_at_from;

    /** @persistent */
    public $created_at_to;

    /** @persistent */
    public $email;

    /** @persistent */
    public $user_agent;

    private $loginAttemptsRepository;

    public function __construct(LoginAttemptsRepository $loginAttemptsRepository)
    {
        parent::__construct();
        $this->loginAttemptsRepository = $loginAttemptsRepository;
    }

    public function startup()
    {
        parent::startup();
        $this->created_at_from = $this->created_at_from ?? DateTime::from('-1 months')->format('Y-m-d 00:00:00');
        $this->created_at_to = $this->created_at_to ?? DateTime::from('today')->format('Y-m-d 23:59:59');
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $filteredLoginAttempts = $this->getFilteredLoginAttempts();

        $filteredCount = $filteredLoginAttempts->count('*');
        $this->template->filteredCount = $filteredCount;
        $this->template->createdAtFrom = $this->created_at_from;
        $this->template->createdAtTo = $this->created_at_to;

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');

        $paginator = $vp->getPaginator();
        $paginator->setItemCount($filteredCount);
        $paginator->setItemsPerPage($this->onPage);

        $this->template->vp = $vp;
        $this->template->loginAttempts = $filteredLoginAttempts->limit($paginator->getLength(), $paginator->getOffset());
    }

    private function getFilteredLoginAttempts()
    {
        $loginAttempts = $this->loginAttemptsRepository->getTable()->order('created_at DESC');

        if ($this->request->getParameter('status')) {
            $loginAttempts->where('status IN ?', $this->request->getParameter('status'));
        }
        if ($this->email) {
            $loginAttempts->where('email LIKE ?', "%{$this->email}%");
        }
        if ($this->user_agent) {
            $loginAttempts->where('user_agent LIKE ?', "%{$this->user_agent}%");
        }
        if ($this->created_at_from) {
            $loginAttempts->where('created_at >= ?', $this->created_at_from);
        }
        if ($this->created_at_to) {
            $loginAttempts->where('created_at <= ?', $this->created_at_to);
        }

        return $loginAttempts;
    }

    public function createComponentLoginAttemptsForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);

        $form->addText('email', 'users.admin.login_attempts_form.email.label')
            ->setHtmlAttribute('placeholder', 'users.admin.login_attempts_form.email.placeholder')
            ->setHtmlAttribute('autofocus');

        $statuses = $this->loginAttemptsRepository->statuses();
        $form->addMultiSelect('status', 'users.admin.login_attempts_form.status.label', array_combine($statuses, $statuses))
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        $sources = $this->loginAttemptsRepository->getTable()->select("DISTINCT source")->fetchPairs("source", "source");
        $form->addMultiSelect('source', 'users.admin.login_attempts_form.source.label', $sources)
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addText('created_at_from', 'users.admin.login_attempts_form.created_at_from.label')
            ->setHtmlAttribute('placeholder', 'users.admin.login_attempts_form.created_at_from.placeholder');

        $form->addText('created_at_to', 'users.admin.login_attempts_form.created_at_to.label')
            ->setHtmlAttribute('placeholder', 'users.admin.login_attempts_form.created_at_to.placeholder');

        $form->addText('user_agent', 'users.admin.login_attempts_form.user_agent.label')
            ->setHtmlAttribute('placeholder', 'users.admin.login_attempts_form.user_agent.placeholder');

        $form->addSubmit('send', 'users.admin.login_attempts_form.submit')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('users.admin.login_attempts_form.submit'));

        $form->setDefaults([
            'created_at_from' => $this->created_at_from,
            'created_at_to' => $this->created_at_to,
            'email' => $this->email,
            'user_agent' => $this->user_agent,
            'status' => $this->request->getParameter('status'),
            'source' => $this->request->getParameter('source'),
        ]);

        $form->onSuccess[] = [$this, 'adminFilterSubmitted'];
        return $form;
    }
}
