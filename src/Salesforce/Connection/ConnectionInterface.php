<?php

namespace Stratease\Salesforcery\Salesforce\Connection;

use Stratease\Salesforcery\Salesforce\Query\Builder as QueryBuilder;
use Stratease\Salesforcery\Salesforce\Query\Grammar\Grammar;

interface ConnectionInterface
{

    public function getQueryBuilder(): QueryBuilder;

    public function getQueryGrammar(): Grammar;
}
