<?php

namespace Stratease\Salesforcery\Salesforce\Connection;

use Stratease\Salesforcery\Salesforce\Query\Builder as QueryBuilder;
use Stratease\Salesforcery\Salesforce\Query\Grammar\Grammar;

abstract class Connection implements ConnectionInterface
{

    protected $queryGrammar;

    public function getQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar()
        );
    }

    public function getQueryGrammar(): Grammar
    {
        return $this->queryGrammar;
    }
}
