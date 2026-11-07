<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal Observed facts about one candidate; reading ORM state belongs to the caller. */
final readonly class AssociationMembership
{
    public function __construct(
        public object $originalOwner,
        public bool $containsCandidate,
        public ?object $owningSideOwner = null,
    ) {
    }
}
