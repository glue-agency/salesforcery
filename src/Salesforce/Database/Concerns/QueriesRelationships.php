<?php

namespace Stratease\Salesforcery\Salesforce\Database\Concerns;

use Closure;

trait QueriesRelationships
{

    public function has($relation, Closure $closure = null)
    {
        $relation = $this->getRelation($relation);

        $relation->getRelationExistenceQuery(
            $relation, $this, $closure
        );

        return $this;
    }

    public function whereHas($relation, Closure $callback)
    {
        return $this->has($relation, $callback);
    }
}
