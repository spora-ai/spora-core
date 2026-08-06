<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Models\MailTemplate;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\Mail\MailTemplateSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Reconcile DB-stored mail templates against the YAML defaults shipped in
 * `email-templates/` (project override takes precedence over framework).
 *
 *   bin/spora mail:templates:sync          # interactive: prompts per divergent row
 *   bin/spora mail:templates:sync --check  # CI drift guard: exit 1 on any difference
 *   bin/spora mail:templates:sync --force  # overwrite divergent rows without prompting
 *
 * DB-level work (insert / update / diff) lives in {@see MailTemplateSyncService}
 * so {@see \Spora\Core\DatabaseSeeder} can reuse the same code path.
 */
#[AsCommand(
    name: 'mail:templates:sync',
    description: 'Reconcile mail templates in the DB against YAML defaults on disk.',
)]
final class MailTemplatesSyncCommand extends Command
{
    public function __construct(
        private readonly MailTemplateSyncService $sync,
        private readonly EmailTemplateLoader $loader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Dry-run: print current vs. desired value for every changed field and exit non-zero on any drift.',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite divergent DB rows without prompting. Inert under --check.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $check = (bool) $input->getOption('check');
        $force = (bool) $input->getOption('force');

        if ($check && $force) {
            $io->warning('--check ignores --force; running in check mode.');
        }

        try {
            $defaults = $this->loader->getAll();
        } catch (Throwable $e) {
            $io->error('Setup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($defaults === []) {
            $io->warning('No email templates found on disk. Nothing to do.');
            return Command::SUCCESS;
        }

        return $this->reconcileAll($io, $defaults, $check, $force);
    }

    /**
     * @param array<string, array{name: string, subject: string, body: string|null, body_html: string|null}> $defaults
     */
    private function reconcileAll(SymfonyStyle $io, array $defaults, bool $check, bool $force): int
    {
        $rows  = [];
        $drift = 0;
        $exit  = Command::SUCCESS;

        foreach ($defaults as $template) {
            $name = (string) $template['name'];
            $row  = MailTemplate::where('name', $name)->first();
            $status = $this->reconcile($io, $template, $row, $check, $force);
            $rows[] = [$name, $status];
            if ($status === 'drift' || $status === 'skipped') {
                $drift++;
            }
        }

        $io->section('Mail template sync summary');
        $io->table(['Template', 'Status'], $rows);

        if ($check && $drift > 0) {
            $io->error(sprintf('%d template(s) drifted from YAML defaults.', $drift));
            $exit = Command::FAILURE;
        } else {
            $io->success('Mail templates reconciled.');
        }

        return $exit;
    }

    /**
     * @param array{name: string, subject: string, body: string|null, body_html: string|null} $template
     */
    private function reconcile(
        SymfonyStyle $io,
        array $template,
        ?MailTemplate $row,
        bool $check,
        bool $force,
    ): string {
        $name = (string) $template['name'];

        if ($row === null) {
            if ($check) {
                $io->writeln("  <comment>[new]</comment>     {$name}");
                return 'drift';
            }
            $this->sync->createMissing($template);
            $io->writeln("  <info>[created]</info> {$name}");
            return 'created';
        }

        $diff = $this->sync->diffDefaults($template, $row);
        if ($diff === []) {
            return 'unchanged';
        }

        return $this->applyDiff($io, $name, $diff, $row, $check, $force);
    }

    /**
     * @param array<string, string|null> $diff
     */
    private function applyDiff(SymfonyStyle $io, string $name, array $diff, MailTemplate $row, bool $check, bool $force): string
    {
        if ($check) {
            $io->writeln("  <comment>[drift]</comment>  {$name}");
            foreach ($diff as $field => $want) {
                $have  = $this->abbreviate($row->{$field});
                $wantA = $this->abbreviate($want);
                $io->writeln(sprintf('             %s: %s', $field, $wantA));
                $io->writeln(sprintf('             %s: %s → %s', str_pad('', strlen($field)), $have, $wantA));
            }
            return 'drift';
        }

        if (!$force && !$io->confirm("Overwrite '{$name}'?", false)) {
            $io->writeln("  <comment>[skipped]</comment> {$name}");
            return 'skipped';
        }

        $this->sync->updateTemplate((int) $row->id, $diff);
        $io->writeln("  <info>[{$this->labelFor($force)}]</info> {$name}");
        return $force ? 'forced' : 'applied';
    }

    private function labelFor(bool $force): string
    {
        return $force ? 'forced' : 'applied';
    }

    private function abbreviate(?string $value): string
    {
        if ($value === null) {
            return '(null)';
        }
        $oneLine = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($oneLine) > 60 ? substr($oneLine, 0, 57) . '...' : $oneLine;
    }
}
