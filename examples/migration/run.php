<?php

declare(strict_types=1);

use ModernORM\Tools\SchemaTool;
use ModernORMExample\Entity\Note;
use ModernORMExample\Repository\NoteRepository;

if (! in_array('--test-schema', $argv, true)) {
    fwrite(STDERR, "Use --test-schema with a dedicated test database: this example creates and drops modernorm_example_notes.\n");
    exit(1);
}
$em = require __DIR__ . '/bootstrap.php';
$created = false;
try {
    (new SchemaTool($em))->createSchema([$em->getClassMetadata(Note::class)]);
    $created = true;
    $note = new Note('Migration works');
    $em->persist($note);
    $em->flush();
    $id = $note->id;
    $em->clear();
    $repository = $em->getRepository(Note::class);
    if (! $repository instanceof NoteRepository) {
        throw new RuntimeException('Custom repository was not selected.');
    }
    $loaded = $repository->findByTitle('Migration works');
    if ($loaded === null || $loaded->id !== $id || $loaded !== $em->find(Note::class, $id)) {
        throw new RuntimeException('Migration example failed.');
    }
    echo "Migration example passed.\n";
} finally {
    if ($created) {
        $em->getConnection()->executeStatement('DROP TABLE modernorm_example_notes');
    }
    $em->getConnection()->close();
}
