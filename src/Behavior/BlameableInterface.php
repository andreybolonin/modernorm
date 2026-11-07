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

namespace ModernORM\Behavior;

interface BlameableInterface
{
    public function getCreatedBy(): ?object;

    public function getUpdatedBy(): ?object;

    public function setCreatedBy(?object $actor): static;

    public function setUpdatedBy(?object $actor): static;
}
