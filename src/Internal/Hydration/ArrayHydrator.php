<?php

declare(strict_types=1);

namespace ModernORM\Internal\Hydration;

use function array_key_last;
use function count;
use function is_array;
use function key;
use function reset;

/**
 * The ArrayHydrator produces a nested array "graph" that is often (not always)
 * interchangeable with the corresponding object graph for read-only access.
 */
class ArrayHydrator extends AbstractHydrator
{
    /** @var array<string,bool> */
    private array $rootAliases = [];

    private bool $isSimpleQuery = false;

    /** @var mixed[] */
    private array $identifierMap = [];

    /** @var mixed[] */
    private array $resultPointers = [];

    /** @var array<string,string> */
    private array $idTemplate = [];

    private int $resultCounter = 0;

    protected function prepare(): void
    {
        $this->isSimpleQuery = count($this->resultSetMapping()->aliasMap) <= 1;

        foreach ($this->resultSetMapping()->aliasMap as $resultAlias => $className) {
            $this->identifierMap[$resultAlias]  = [];
            $this->resultPointers[$resultAlias] = [];
            $this->idTemplate[$resultAlias]     = '';
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateAllData(): array
    {
        $result = [];

        while ($data = $this->statement()->fetchAssociative()) {
            $this->hydrationRows();
            $this->hydrateRowData($data, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        // 1) Initialize
        $id                 = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        $rowData            = $this->gatherRowData($row, $id, $nonemptyComponents);

        // 2) Now hydrate the data found in the current row.
        foreach ($rowData['data'] as $resultAlias => $data) {
            $index = false;

            if (isset($this->resultSetMapping()->parentAliasMap[$resultAlias])) {
                // It's a joined result

                $parent = $this->resultSetMapping()->parentAliasMap[$resultAlias];
                $path   = $parent . '.' . $resultAlias;

                // missing parent data, skipping as RIGHT JOIN hydration is not supported.
                if (! isset($nonemptyComponents[$parent])) {
                    continue;
                }

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->resultSetMapping()->isMixed && isset($this->rootAliases[$parent])) {
                    $first = reset($this->resultPointers);
                    // TODO: Exception if $key === null ?
                    $baseElement = & $this->resultPointers[$parent][key($first)];
                } elseif (isset($this->resultPointers[$parent])) {
                    $baseElement = & $this->resultPointers[$parent];
                } else {
                    unset($this->resultPointers[$resultAlias]); // Ticket #1228

                    continue;
                }

                $relationAlias = $this->resultSetMapping()->relationMap[$resultAlias];
                $parentClass   = $this->metadataCache[$this->resultSetMapping()->aliasMap[$parent]];
                $relation      = $parentClass->associationMappings[$relationAlias];

                // Check the type of the relation (many or single-valued)
                if (! $relation->isToOne()) {
                    $oneToOne = false;

                    if (! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = [];
                    }

                    if (isset($nonemptyComponents[$resultAlias])) {
                        $indexExists  = isset($this->identifierMap[$path][$id[$parent]][$id[$resultAlias]]);
                        $index        = $indexExists ? $this->identifierMap[$path][$id[$parent]][$id[$resultAlias]] : false;
                        $indexIsValid = $index !== false ? isset($baseElement[$relationAlias][$index]) : false;

                        if (! $indexExists || ! $indexIsValid) {
                            $element = $data;

                            if (isset($this->resultSetMapping()->indexByMap[$resultAlias])) {
                                $baseElement[$relationAlias][$row[$this->resultSetMapping()->indexByMap[$resultAlias]]] = $element;
                            } else {
                                $baseElement[$relationAlias][] = $element;
                            }

                            $this->identifierMap[$path][$id[$parent]][$id[$resultAlias]] = array_key_last($baseElement[$relationAlias]);
                        }
                    }
                } else {
                    $oneToOne = true;

                    if (
                        ! isset($nonemptyComponents[$resultAlias]) &&
                        (! isset($baseElement[$relationAlias]))
                    ) {
                        $baseElement[$relationAlias] = null;
                    } elseif (! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = $data;
                    }
                }

                $coll = & $baseElement[$relationAlias];

                if (is_array($coll)) {
                    $this->updateResultPointer($coll, $index, $resultAlias, $oneToOne);
                }
            } else {
                // It's a root result element

                $this->rootAliases[$resultAlias] = true; // Mark as root
                $entityKey                    = $this->resultSetMapping()->entityMappings[$resultAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$resultAlias])) {
                    $result[] = $this->resultSetMapping()->isMixed
                        ? [$entityKey => null]
                        : null;

                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;

                    continue;
                }

                // Check for an existing element
                if ($this->isSimpleQuery || ! isset($this->identifierMap[$resultAlias][$id[$resultAlias]])) {
                    $element = $this->resultSetMapping()->isMixed
                        ? [$entityKey => $data]
                        : $data;

                    if (isset($this->resultSetMapping()->indexByMap[$resultAlias])) {
                        $resultKey          = $row[$this->resultSetMapping()->indexByMap[$resultAlias]];
                        $result[$resultKey] = $element;
                    } else {
                        $resultKey = $this->resultCounter;
                        $result[]  = $element;

                        ++$this->resultCounter;
                    }

                    $this->identifierMap[$resultAlias][$id[$resultAlias]] = $resultKey;
                } else {
                    $index     = $this->identifierMap[$resultAlias][$id[$resultAlias]];
                    $resultKey = $index;
                }

                $this->updateResultPointer($result, $index, $resultAlias, false);
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                // this only ever happens when no object is fetched (scalar result only)
                $resultKey = isset($this->resultSetMapping()->indexByMap['scalars'])
                    ? $row[$this->resultSetMapping()->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $result[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $args = $newObject['args'];
                $obj  = $newObject['obj'];

                if (count($args) === $scalarCount || ($scalarCount === 0 && count($rowData['newObjects']) === 1)) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /**
     * Updates the result pointer for an Entity. The result pointers point to the
     * last seen instance of each Entity type. This is used for graph construction.
     *
     * @param mixed[]|null     $coll     The element.
     * @param string|int|false $index    Index of the element in the collection.
     * @param bool             $oneToOne Whether it is a single-valued association or not.
     */
    private function updateResultPointer(
        array|null &$coll,
        string|int|false $index,
        string $resultAlias,
        bool $oneToOne,
    ): void {
        if ($coll === null) {
            unset($this->resultPointers[$resultAlias]); // Ticket #1228

            return;
        }

        if ($oneToOne) {
            $this->resultPointers[$resultAlias] = & $coll;

            return;
        }

        if ($index !== false) {
            $this->resultPointers[$resultAlias] = & $coll[$index];

            return;
        }

        if (! $coll) {
            return;
        }

        $this->resultPointers[$resultAlias] = & $coll[array_key_last($coll)];
    }
}
