<?php

declare(strict_types=1);

namespace ModernORMExample\Entity;

use ModernORM\Mapping as ORM;
use ModernORMExample\Repository\NoteRepository;

// Mapping attributes keep their original Doctrine names.
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'modernorm_example_notes')]
final class Note
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $title) {}
}
