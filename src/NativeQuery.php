<?php

declare(strict_types=1);

namespace ModernORM;

use Doctrine\DBAL\Result;
use ModernORM\Query\ParameterTypeInferer;

use function array_values;
use function is_int;
use function key;
use function ksort;

/**
 * Represents a native SQL query.
 */
class NativeQuery extends AbstractQuery
{
    private string $sql;

    /** @return $this */
    public function setSQL(string $sql): self
    {
        $this->sql = $sql;

        return $this;
    }

    public function getSQL(): string
    {
        return $this->sql;
    }

    /** SQL query clones retain independent bindings, hints and result mappings. */
    public function __clone()
    {
        $this->parameters = $this->parameters->map(static fn (\ModernORM\Query\Parameter $parameter) => clone $parameter);
        $this->resultSetMapping = $this->resultSetMapping === null ? null : clone $this->resultSetMapping;
        $this->queryCacheProfile = $this->queryCacheProfile === null ? null : clone $this->queryCacheProfile;
        $this->hydrationCacheProfile = $this->hydrationCacheProfile === null ? null : clone $this->hydrationCacheProfile;
    }

    protected function _doExecute(): Result|int
    {
        $parameters = [];
        $types      = [];

        foreach ($this->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($parameter->typeWasSpecified()) {
                $parameters[$name] = $parameter->getValue();
                $types[$name]      = $parameter->getType();

                continue;
            }

            $value = $this->processParameterValue($parameter->getValue());
            $type  = $parameter->getValue() === $value
                ? $parameter->getType()
                : ParameterTypeInferer::inferType($value);

            $parameters[$name] = $value;
            $types[$name]      = $type;
        }

        if ($parameters && is_int(key($parameters))) {
            ksort($parameters);
            ksort($types);

            $parameters = array_values($parameters);
            $types      = array_values($types);
        }

        return $this->em->getConnection()->executeQuery(
            $this->getSQL(),
            $parameters,
            $types,
            $this->queryCacheProfile,
        );
    }
}
