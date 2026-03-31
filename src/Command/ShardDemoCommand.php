<?php

namespace App\Command;

use App\Sharding\ShardRouter;
use App\Sharding\ShardException;
use App\Sharding\Migration\MigrationThroughService;
use App\Sharding\Migration\MigrationWorkerService;
use App\Sharding\Storage\ShardConnectionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

/**
 * Commande exemple pour tester le ShardRouter et les migrations
 * 
 * Utilisation:
 *   php bin/console app:shard:demo --action=insert
 *   php bin/console app:shard:demo --action=find
 *   php bin/console app:shard:demo --action=migrate-through
 *   php bin/console app:shard:demo --action=migrate-batch
 */
class ShardDemoCommand extends Command
{
    protected static $defaultName = 'app:shard:demo';
    protected static $defaultDescription = 'Démontre les opérations de sharding et migration';

    public function __construct(
        private ShardRouter $shardRouter,
        private ShardConnectionManager $connectionManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'action',
                'a',
                InputOption::VALUE_REQUIRED,
                'Action à exécuter: insert|find|migrate-through|migrate-batch',
                'insert'
            )
            ->addOption(
                'email',
                'e',
                InputOption::VALUE_OPTIONAL,
                'Email de l\'utilisateur (pour actions find)',
                'test@example.com'
            )
            ->addOption(
                'name',
                'n',
                InputOption::VALUE_OPTIONAL,
                'Nom de l\'utilisateur (pour actions insert)',
                'Test User'
            )
            ->addOption(
                'old-shard',
                null,
                InputOption::VALUE_OPTIONAL,
                'ID du shard source pour migration batch',
                'shard_1'
            )
            ->addOption(
                'new-shard',
                null,
                InputOption::VALUE_OPTIONAL,
                'ID du shard destination pour migration batch',
                'shard_1_v2'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Taille du batch pour migration worker',
                '500'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getOption('action');

        try {
            switch ($action) {
                case 'insert':
                    return $this->handleInsert($input, $output);

                case 'find':
                    return $this->handleFind($input, $output);

                case 'migrate-through':
                    return $this->handleMigrateThrough($input, $output);

                case 'migrate-batch':
                    return $this->handleMigrateBatch($input, $output);

                default:
                    $output->writeln('<error>Action inconnue: ' . $action . '</error>');
                    return Command::FAILURE;
            }
        } catch (\Exception $exception) {
            $output->writeln('<error>Erreur: ' . $exception->getMessage() . '</error>');
            $this->logger->error('Shard demo error', ['error' => $exception->getMessage()]);

            return Command::FAILURE;
        }
    }

    /**
     * Démontre l'insertion d'un utilisateur
     */
    private function handleInsert(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        $name = $input->getOption('name');

        $output->writeln("<info>Insertion d'un utilisateur</info>");
        $output->writeln("  Email: $email");
        $output->writeln("  Nom: $name");

        $this->shardRouter->insertUser($email, $name);

        $output->writeln("<fg=green>✓</> Utilisateur inséré avec succès");

        return Command::SUCCESS;
    }

    /**
     * Démontre la recherche d'un utilisateur
     */
    private function handleFind(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');

        $output->writeln("<info>Recherche d'un utilisateur</info>");
        $output->writeln("  Email: $email");

        $user = $this->shardRouter->findUser($email);

        if ($user === null) {
            $output->writeln("<fg=yellow>⚠</> Utilisateur non trouvé");
            return Command::SUCCESS;
        }

        $output->writeln("<fg=green>✓</> Utilisateur trouvé:");
        foreach ($user as $key => $value) {
            $output->writeln("  $key: $value");
        }

        return Command::SUCCESS;
    }

    /**
     * Démontre la migration lazy (through service)
     */
    private function handleMigrateThrough(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Démonstration: Migration Lazy (Through Service)</info>");

        $throughService = new MigrationThroughService($this->shardRouter, $this->logger);

        // Simule 5 accès (certains peuvent migrer lazily)
        $testEmails = [
            'user1@example.com',
            'user2@example.com',
            'user3@example.com',
            'user4@example.com',
            'user5@example.com',
        ];

        foreach ($testEmails as $email) {
            try {
                $user = $throughService->findUserWithMigration($email);
                if ($user) {
                    $output->writeln("<fg=green>✓</> Trouvé (et migré si nécessaire): $email");
                } else {
                    $output->writeln("<fg=yellow>-</> Pas trouvé: $email");
                }
            } catch (ShardException $exception) {
                $output->writeln("<fg=red>✗</> Erreur: " . $exception->getMessage());
            }
        }

        $output->writeln("\n<info>Statistiques de migration:</info>");
        $output->write($throughService->getMigrationReport());

        return Command::SUCCESS;
    }

    /**
     * Démontre la migration batch (worker service)
     */
    private function handleMigrateBatch(InputInterface $input, OutputInterface $output): int
    {
        $oldShardId = $input->getOption('old-shard');
        $newShardId = $input->getOption('new-shard');
        $batchSize = (int)$input->getOption('batch-size');

        $output->writeln("<info>Démonstration: Migration Batch (Worker Service)</info>");
        $output->writeln("  Source (old): $oldShardId");
        $output->writeln("  Destination (new): $newShardId");
        $output->writeln("  Batch size: $batchSize");

        $worker = new MigrationWorkerService(
            $this->connectionManager,
            $oldShardId,
            $newShardId,
            $this->logger,
            $batchSize
        );

        $output->writeln("\n<fg=cyan>► Starting batch migration...</>\n");
        $stats = $worker->migrate();
        $output->write($worker->getMigrationReport());

        $output->writeln("\n<fg=green>✓</> Migration batch terminée");

        return Command::SUCCESS;
    }
}
