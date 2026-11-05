<?php

declare(strict_types=1);

namespace ModernORMExample\Repository;

// Migration: replace Doctrine\ORM\EntityRepository with ModernORM\EntityRepository.
use ModernORM\EntityRepository;
use ModernORMExample\Entity\Note;

/** @extends EntityRepository<Note> */
final class NoteRepository extends EntityRepository
{
    public function findByTitle(string $title): ?Note
    {
        return $this->createQueryBuilder('n')->where('n.title = :title')
            ->setParameter('title', $title)->getQuery()->getOneOrNullResult();
    }
}
