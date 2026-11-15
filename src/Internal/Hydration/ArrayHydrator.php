<?php

declare(strict_types=1);

namespace ModernORM\Internal\Hydration;

/** Produces one flat mapped array per SQL row, without managed objects or graph assembly. */
class ArrayHydrator extends AbstractHydrator
{
    private ?string $alias = null;

    protected function prepare(): void
    {
        $this->resultSetMapping()->assertFlatArrayMapping($this->em);
        $this->alias = array_key_first($this->resultSetMapping()->aliasMap);
    }

    protected function hydrateAllData(): array
    {
        $result = [];
        while (($row = $this->statement()->fetchAssociative()) !== false) {
            $this->hydrationRows();
            $this->hydrateRowData($row, $result);
        }
        return $result;
    }

    protected function hydrateRowData(array $row, array &$result): void
    {
        $id = $this->alias === null ? [] : [$this->alias => ''];
        $nonempty = [];
        $data = $this->gatherRowData($row, $id, $nonempty);

        // Preserve the existing null-root representation for a mapped SQL NULL identifier.
        if ($this->alias !== null && ! isset($nonempty[$this->alias])) {
            $result[] = null;
            return;
        }
        $value = $this->alias === null ? ($data['scalars'] ?? []) : ($data['data'][$this->alias] ?? []);
        $index = $this->resultSetMapping()->indexByMap[$this->alias ?? 'scalars'] ?? null;
        if ($index === null) {
            $result[] = $value;
        } else {
            $result[$row[$index]] = $value;
        }
    }
}
