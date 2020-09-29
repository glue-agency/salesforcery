<?php

namespace Stratease\Salesforcery\Salesforce\Query\Grammar;

use Stratease\Salesforcery\Salesforce\Query\Builder;

class Grammar
{

    protected $selectComponents = [
        'columns',
        'from',
        'wheres',
        'limit',
        'offset',
    ];

    /**
     * @param Builder $query
     *
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        return trim($this->concatenate(
            $this->compileComponents($query))
        );
    }

    protected function compileColumns(Builder $query, $columns): string
    {
        $columnized = implode(', ', $columns);

        return "SELECT {$columnized}";
    }

    protected function compileWheres(Builder $query, $wheres): string
    {
        if(empty($wheres)) {
            return '';
        }

        if(count($sql = $this->compileWheresToArray($query)) > 0) {
            return $this->concatenateWhereClauses($query, $sql);
        }

        return '';
    }

    protected function compileWheresToArray($query)
    {
        return collect($query->wheres)->map(function($where) use ($query) {
            return 'AND ' . $this->{"where{$where['type']}"}($query, $where);
        })->all();
    }

    protected function whereBasic(Builder $query, $where): string
    {
        return "{$where['field']} {$where['operator']} {$this->wrap($where['value'])}";
    }

    protected function whereBoolean(Builder $query, $where): string
    {
        $boolean = $where['value'] ? 'true' : 'false';

        return "{$where['field']} {$where['operator']} {$boolean}";
    }

    protected function whereIn(Builder $query, $where): string
    {
        $stringifiedValues = implode(', ', $this->wrap($where['values']));

        return "{$where['column']} {$where['type']} ({$stringifiedValues})";
    }

    protected function whereNotIn(Builder $query, $where): string
    {
        return $this->whereIn($query, $where);
    }

    protected function whereNull(Builder $query, $where): string
    {
        return "{$where['column']} = NULL";
    }

    protected function whereNotNull(Builder $query, $where): string
    {
        return "{$where['column']} != NULL";
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return "LIMIT {$limit}";
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return "OFFSET {$offset}";
    }

    protected function compileFrom(Builder $query, $table): string
    {
        return "FROM {$table}";
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param Builder $query
     *
     * @return array
     */
    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach($this->selectComponents as $component) {
            if(isset($query->{$component})) {
                $method = 'compile' . ucfirst($component);

                $sql[$component] = $this->{$method}($query, $query->{$component});
            }
        }

        return $sql;
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param array $segments
     *
     * @return string
     */
    protected function concatenate($segments): string
    {
        return implode(' ', array_filter($segments, function($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Wrap values for use in the query.
     *
     * @param $data
     *
     * @return mixed
     */
    protected function wrap($data)
    {
        if(is_array($data)) {
            return array_map(function($item) {
                return "'{$item}'";
            }, $data);
        }

        return "'{$data}'";
    }

    protected function concatenateWhereClauses($query, $sql)
    {
        return "WHERE {$this->removeLeadingBoolean(implode(' ', $sql))}";
    }

    protected function removeLeadingBoolean($value)
    {
        return preg_replace('/AND /i', '', $value, 1);
    }
}
