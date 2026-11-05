<?php

declare(strict_types=1);

namespace ModernORM\Tools\Console\Command\ClearCache;

use ModernORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Invalidate one entity key or class without exposing regions or flushing shared storage. */
final class EntityCommand extends AbstractEntityManagerCommand
{
    protected function configure(): void
    {
        $this->setName('orm:clear-cache:entity')
            ->setDescription('Invalidate L2 cache entries for one entity class or identifier')
            ->addArgument('entity-class', InputArgument::REQUIRED, 'Mapped entity class')
            ->addArgument('entity-id', InputArgument::OPTIONAL, 'Identifier; omit to invalidate the whole class')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'Name of the entity manager');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = $this->getEntityManager($input)->getCache()
            ?? throw new \InvalidArgumentException('No entity L2 cache is configured.');
        $entity = $input->getArgument('entity-class');
        $id = $input->getArgument('entity-id');
        if ($id === null) {
            $cache->evictAll($entity);
        } else {
            $cache->evict($entity, $id);
        }
        $output->writeln('Entity L2 cache invalidated.');
        return 0;
    }
}
