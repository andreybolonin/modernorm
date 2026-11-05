<?php

declare(strict_types=1);

namespace ModernORM\Tools\Console;

use Doctrine\DBAL\Tools\Console as DBALConsole;
use ModernORM\Tools\Console\EntityManagerProvider\ConnectionFromManagerProvider;
use OutOfBoundsException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function class_exists;

/**
 * Handles running the Console Tools inside Symfony Console context.
 */
final class ConsoleRunner
{
    use ApplicationCompatibility;

    /**
     * Runs console with the given helper set.
     *
     * @param SymfonyCommand[] $commands
     */
    public static function run(EntityManagerProvider $entityManagerProvider, array $commands = []): void
    {
        $cli = self::createApplication($entityManagerProvider, $commands);
        $cli->run();
    }

    /**
     * Creates a console application with the given helperset and
     * optional commands.
     *
     * @param SymfonyCommand[] $commands
     *
     * @throws OutOfBoundsException
     */
    public static function createApplication(
        EntityManagerProvider $entityManagerProvider,
        array $commands = [],
    ): Application {
        if (! class_exists(Application::class)) {
            throw new \LogicException('Install symfony/console (^7.2 || ^8.0) to use ModernORM CLI tools.');
        }

        $cli = new Application('ModernORM Command Line Interface');
        $cli->setCatchExceptions(true);

        self::addCommands($cli, $entityManagerProvider);
        $cli->addCommands($commands);

        return $cli;
    }

    public static function addCommands(Application $cli, EntityManagerProvider $entityManagerProvider): void
    {
        $connectionProvider = new ConnectionFromManagerProvider($entityManagerProvider);

        if (class_exists(DBALConsole\Command\ReservedWordsCommand::class)) {
            self::addCommandToApplication(
                $cli,
                new DBALConsole\Command\ReservedWordsCommand($connectionProvider),
            );
        }

        $cli->addCommands(
            [
                // DBAL Commands
                new DBALConsole\Command\RunSqlCommand($connectionProvider),

                // ORM Commands
                new Command\ClearCache\EntityCommand($entityManagerProvider),
                new Command\ClearCache\MetadataCommand($entityManagerProvider),
                new Command\ClearCache\QueryCommand($entityManagerProvider),
                new Command\ClearCache\ResultCommand($entityManagerProvider),
                new Command\SchemaTool\CreateCommand($entityManagerProvider),
                new Command\SchemaTool\UpdateCommand($entityManagerProvider),
                new Command\SchemaTool\DropCommand($entityManagerProvider),
                new Command\ValidateSchemaCommand($entityManagerProvider),
                new Command\InfoCommand($entityManagerProvider),
                new Command\MappingDescribeCommand($entityManagerProvider),
            ],
        );
    }
}
