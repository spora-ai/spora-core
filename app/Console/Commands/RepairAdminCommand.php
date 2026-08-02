<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Delight\Auth\Role;
use Spora\Core\Database;
use Spora\Models\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Promote an existing user to verified/admin/active. Operator-driven, idempotent.
 * Refuses to act when the row is missing so it cannot recreate a deleted admin.
 */
#[AsCommand(
    name: 'db:repair-admin',
    description: 'Promote an existing user to verified/admin/active. Idempotent. Operator-only.',
)]
final class RepairAdminCommand extends Command
{
    public function __construct(private readonly Database $database)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'email',
            InputArgument::OPTIONAL,
            'Email of the admin row to repair.',
            'admin@spora.local',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->database->bootDatabaseConnectionOnly();

            $email = (string) $input->getArgument('email');
            $user  = User::where('email', $email)->first();

            if ($user === null) {
                $output->writeln("<error>No user with email '{$email}' exists. Run `db:seed` on a fresh install instead.</error>");
                return Command::FAILURE;
            }

            $before = [
                'verified'   => (int) $user->verified,
                'roles_mask' => (int) $user->roles_mask,
                'status'     => (int) $user->status,
            ];

            User::where('email', $email)->update([
                'verified'   => 1,
                'roles_mask' => (int) $user->roles_mask | Role::ADMIN,
                'status'     => 1,
            ]);

            $after = User::where('email', $email)->firstOrFail();

            $output->writeln("<info>Repaired admin row '{$email}' (id={$after->id}).</info>");
            $output->writeln(sprintf('  verified:   %d → %d', $before['verified'], (int) $after->verified));
            $output->writeln(sprintf('  roles_mask: %d → %d', $before['roles_mask'], (int) $after->roles_mask));
            $output->writeln(sprintf('  status:     %d → %d', $before['status'], (int) $after->status));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Repair failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
