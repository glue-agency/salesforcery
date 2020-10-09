<?php

namespace Stratease\Salesforcery\Salesforce\Database\Concerns;

use Closure;

trait QueriesRelationships
{

    public function whereHas($relation, Closure $callback)
    {
        $relation = $this->getRelation($relation);

        $relation->getRelationExistenceQuery(
            $relation, $this, $callback,
        );

        return $this;
    }
}
