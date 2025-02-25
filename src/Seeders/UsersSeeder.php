<?php

namespace Crm\UsersModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\UsersModule\Auth\Repository\AdminAccessRepository;
use Crm\UsersModule\Auth\Repository\AdminGroupsRepository;
use Crm\UsersModule\Builder\UserBuilder;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Output\OutputInterface;

class UsersSeeder implements ISeeder
{
    private $userBuilder;

    private $usersRepository;

    private $adminGroupsRepository;

    private $adminAccessRepository;

    public function __construct(
        UserBuilder $userBuilder,
        UsersRepository $usersRepository,
        AdminGroupsRepository $adminGroupsRepository,
        AdminAccessRepository $adminAccessRepository
    ) {
        $this->userBuilder = $userBuilder;
        $this->usersRepository = $usersRepository;
        $this->adminGroupsRepository = $adminGroupsRepository;
        $this->adminAccessRepository = $adminAccessRepository;
    }

    public function seed(OutputInterface $output)
    {
        $name = 'superadmin';

        $superGroup = $this->adminGroupsRepository->findByName($name);
        if (!$superGroup) {
            $superGroup = $this->adminGroupsRepository->add($name);
            $output->writeln("  <comment>* admin group <info>{$name}</info> created</comment>");
        } else {
            $output->writeln("  * admin group <info>{$name}</info> exists");
        }

        $this->seedAccessToHandlers($output);

        $accesses = $this->adminAccessRepository->all();
        foreach ($accesses as $access) {
            if ($superGroup->related('admin_groups_access')->where(['admin_group_id' => $superGroup->id, 'admin_access_id' => $access->id])->count('*') == 0) {
                $superGroup->related('admin_groups_access')->insert([
                    'admin_group_id' => $superGroup->id,
                    'admin_access_id' => $access->id,
                    'created_at' => new DateTime(),
                ]);
            }
        }

        $user = $this->userBuilder->createNew()
            ->setEmail('admin@admin.sk')
            ->setPassword('password')
            ->setFirstName('Test')
            ->setLastName('Admin')
            ->setPublicName('admin@admin.sk')
            ->setAddTokenOption(false)
            ->setRole(UsersRepository::ROLE_ADMIN)
            ->save();

        if (!$user) {
            $output->writeln('  * user <info>admin@admin.sk</info> exists');
            $user = $this->usersRepository->getByEmail('admin@admin.sk');
        } else {
            $output->writeln('  <comment>* user <info>admin@admin.sk</info> created</comment>');
        }

        if (!$user->related('admin_user_groups')->where(['admin_group_id' => $superGroup->id])->count('*')) {
            $user->related('admin_user_groups')->insert([
                'created_at' => new DateTime(),
                'updated_at' => new DateTime(),
                'admin_group_id' => $superGroup->id,
                'user_id' => $user->id,
            ]);
        }

        $user = $this->userBuilder->createNew()
            ->setEmail('user@user.sk')
            ->setPassword('password')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setPublicName('admin@admin.sk')
            ->setAddTokenOption(false)
            ->save();
        if (!$user) {
            $output->writeln('  * user <info>user@user.sk</info> exists');
        } else {
            $output->writeln('  <comment>* user <info>user@user.sk</info> created</comment>');
        }
    }

    /**
     * In the past, all admin roles had access to all signals. From now signals
     * will be listed as separate access resource. We don't want to completely
     * break installed instances (and admin groups), so first time this seeder
     * is launched after signals are added by `GenerateAccessCommand`, we'll
     * seed access to all signals for all admin groups.
     */
    private function seedAccessToHandlers(OutputInterface $output)
    {
        // 1. load signals (handle prefix)
        $handleAccesses = $this->adminAccessRepository->all()
            ->where('type = "handle"')
            ->fetchAssoc('id=id');

        // check if any admin group was given access to signal
        // if yes, abort seeding rights to signals
        $count = $this->adminAccessRepository->getDatabase()
            ->table('admin_groups_access')
            ->where(['admin_access_id IN (?)' => $handleAccesses])
            ->count('*');

        if ($count > 0) {
            return;
        }

        $output->writeln("  * seeding rights to signals to <info>all admin groups</info>");

        $adminGroups = $this->adminGroupsRepository->all();

        foreach ($handleAccesses as $handleAccess) {
            foreach ($adminGroups as $adminGroup) {
                // only inserting; no signals should be assigned to admin groups; update is not needed
                $adminGroup->related('admin_groups_access')->insert([
                    'admin_group_id' => $adminGroup->id,
                    'admin_access_id' => $handleAccess,
                    'created_at' => new DateTime(),
                ]);
            }
        }
    }
}
