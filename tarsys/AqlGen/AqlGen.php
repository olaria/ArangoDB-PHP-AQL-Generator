<?php

namespace tarsys\AqlGen;

use InvalidArgumentException;
use tarsys\AqlGen\InnerOperations\Collect;
use tarsys\AqlGen\InnerOperations\Let;


/**
 * Class to build AQL strings
 *
 * @author Társis Lima
 */
class AqlGen extends AbstractAql
{
    /** Command Types constants */
    const TYPE_FOR = 'FOR';
    const TYPE_LET = 'LET';
    const TYPE_COLLECT = 'COLLECT';
    const TYPE_FILTER = 'FILTER';
    const SORT_ASC = 'ASC';
    const SORT_DESC = 'DESC';

    /** Operation Types constants */
    const OPERATION_RETURN = 'RETURN';
    const OPERATION_INSERT = 'INSERT';
    const OPERATION_UPDATE = 'UPDATE';
    const OPERATION_REPLACE = 'REPLACE';
    const OPERATION_DELETE = 'DELETE';


    protected $for;
    protected $in;
    protected $inner = [];
    protected $sort = [];
    protected $skip;
    protected $limit;
    protected $return;
    protected $update;
    protected $params = [];
    protected $isSubQuery = false;

    protected $operation = self::OPERATION_RETURN;

    /**
     * Build a FOR <var> IN <Expression>
     *
     * @param string $for alias to the collection or list <var>
     * @param string $inExpression collection name
     */
    public function __construct($for, $inExpression)
    {
        $this->for = $for;
        $this->in = $inExpression;
        return $this;
    }

    /**
     * Build a FOR <var> IN <Expression>
     *
     * @param string $for alias to the collection or list <var>
     * @param string $inExpression collection name
     * @return \tarsys\AqlGen\AqlGen
     */
    public static function query($for, $inExpression)
    {
        $self = new self($for, $inExpression);
        return $self;
    }

    /**
     * Add a subquery
     *
     * @param mixed|String|AqlGen $subquery
     * @return \AqlGen
     */
    public function subquery(AqlGen $subquery)
    {
        $subquery->setSubquery();
        $this->bindParams($subquery->getParams());
        $this->inner[] = [self::TYPE_FOR => $subquery];
        return $this;
    }

    /**
     * Add a LET expression
     *
     * @param String $var de variable name
     * @param mixed|string|AqlGen $expression
     * @return \AqlGen
     */
    public function let($var, $expression)
    {
        if ($expression instanceof AqlGen) {
            $this->bindParams($expression->getParams());
        }

        $this->inner[] = [self::TYPE_LET => new Let($var, $expression)];
        return $this;
    }

    /**
     * Add a COLLECT expression
     *
     * @param string $var
     * @param string $expression a atribute name
     * @param string $into variable name to group
     * @return \AqlGen
     */
    public function collect($var, $expression, $into = null)
    {
        $this->inner[] = [self::TYPE_COLLECT => new Collect($var, $expression, $into)];
        return $this;
    }

    /**
     * Filter expression
     *
     * @param string $filterCriteria
     * @param Array $params the params that bind to filter
     *
     * eg 1 : $aql->filter('u.name == @name', ['name'=>'John']);
     * eg 2 : $aql->filter('u.name == @name && u.age == @age')->bindParams(['name'=>'John', 'age'=> 20]);
     * eg 3 : $filter = new AqlFilter();
     *        $filter->filter('u.name == @name')->bindParams(['name' => 'John']);
     * $aql->filter($filter);
     *
     * @return \AqlGen
     */
    public function filter($filterCriteria, $params = [])
    {
        $this->addFilter($filterCriteria, $params, AqlFilter::AND_OPERATOR);
        return $this;
    }

    /**
     * Add filter with OR operator
     * @param string $filterCriteria
     * @param Array $params the params that bind to filter
     * @return \AqlGen
     */
    public function orFilter($filterCriteria, $params = [])
    {
        $this->addFilter($filterCriteria, $params, AqlFilter::OR_OPERATOR);
        return $this;
    }

    /**
     * Add SORT fields
     *
     * @param mixed|string|array $sort
     * @param string $direction
     * @return $this
     */
    public function sort($sort, $direction = self::SORT_ASC)
    {
        if (is_array($sort)) {
            $sort = implode(', ', $sort);
        }
        $this->sort[] = $sort . ' ' . $direction;
        return $this;
    }

    public function skip($skip)
    {
        $this->skip = (int)$skip;
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = (int)$limit;
        return $this;
    }

    /**
     * The mounted Aql query string
     * @return string
     */
    public function get()
    {
        $query = $this->getForString();
        $query .= $this->getInnerExpressionsString();
        $query .= $this->getSortString();
        $query .= $this->getLimitString();
        $query .= $this->getReturnString();
        $query .= $this->getUpdateString();
        return $query;
    }

    /**
     * Get expresions in order that are call
     *
     * @return string
     */
    protected function getInnerExpressionsString()
    {
        $query = '';
        foreach ($this->inner as $expressions) {
            foreach ($expressions as $type => $expression) {
                $query .= self::TAB_SEPARATOR . $expression->get();
            }
        }
        return $query;
    }

    /**
     * the IN part of query
     * @return type
     */
    protected function getForString()
    {
        $return = "FOR {$this->for} IN {$this->in}" . self::LINE_SEPARATOR;
        return $return;
    }

    /**
     * the SORT part of query
     * @return string
     */
    protected function getSortString()
    {
        $query = '';
        if (!empty($this->sort)) {
            $sort = implode(', ', $this->sort);
            $query = self::TAB_SEPARATOR . "SORT " . $sort . self::LINE_SEPARATOR;
        }
        return $query;
    }

    /**
     * The LIMIT part of query
     * @return string
     */
    protected function getLimitString()
    {
        $str = '';
        if (!empty($this->limit)) {
            $str = self::TAB_SEPARATOR;
            if (!empty($this->skip)) {
                $this->limit = $this->skip . ', ' . $this->limit;
            }
            $str .= 'LIMIT ' . $this->limit . self::LINE_SEPARATOR;
        }
        return $str;
    }

    /**
     * the RETURN part of query
     * @return string
     */
    protected function getReturnString()
    {
        if ($this->isSubQuery) {
            if (is_null($this->return)) {
                return '';
            } else {
                throw new InvalidArgumentException("A subquery not should have a {$this->operation} operation.");
            }
        }

        if (is_null($this->return) && is_null($this->update)) {
            $this->setReturn($this->for);
        }

        return !is_null($this->return) ? $this->return->get() : '';
    }

    protected function getUpdateString()
    {
        return !is_null($this->update) ? $this->update->get() : '';
    }

    /**
     * Set a list of params to bind
     *
     * @param Array $params Key => values of variables to bind
     * eg: $query->bindParams(array('name' => 'john', 'status' => 'OK'));
     * @return string
     */
    public function bindParams($params)
    {
        if (!empty($params)) {
            $this->params = array_merge($this->params, $params);
        }
        return $this;
    }

    /**
     * Set a specific param to bind
     * @return string
     */
    public function bindParam($key, $value)
    {
        $this->params[$key] = $value;
        return $this;
    }

    /**
     * Get all params to bind
     * @return Array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * set a RETURN part of query
     * @params string|array $return
     * @return $this
     */
    public function setReturn($return)
    {
        $this->return = new AqlReturn($return);
        return $this;
    }

    /**
     * Set a UPDATE part of query
     * @param string $collectionId
     * @param string|array $changedAttributes
     * @return $this
     */
    public function update($collectionId, $changedAttributes)
    {
        $this->update = new AqlUpdate($this->for, $collectionId, $changedAttributes);
        return $this;
    }

    /**
     * Set operation over current query
     * @param string $document document to
     * @param string $collection
     * @param string $with
     * @return \tarsys\AqlGen\AqlGen
     */
    protected function setCollectionOperation($document = null, $collection = null, $with = null)
    {
        if (is_null($document)) {
            $document = $this->for;
        }
        if (is_null($collection)) {
            $collection = $this->in;
        }
        $return = $document . " {$with} IN " . $collection;
        return $this->setOperationReturn($return);
    }

    /**
     * check if the Operation is valid
     * @return $this
     */
    private function checkOperationReturn()
    {
        if ($this->isSubQuery == true && !$this->return instanceof AqlReturn) {
            throw new InvalidArgumentException("A subquery not should have a {$this->operation} operation.");
        }
        return $this;
    }

    /**
     * Set if RETURN operator is required. Optional only in subqueries
     * @return $this
     */
    public function setSubquery()
    {
        $this->isSubQuery = true;
        return $this;
    }

    /**
     * Add filter item
     * @param String | AqlFilter $filterCriteria
     * @param array $params
     * @param string $operator
     */
    protected function addFilter($filterCriteria, $params = [], $operator = AqlFilter::AND_OPERATOR)
    {
        if (!$filterCriteria instanceof AqlFilter) {
            $filterCriteria = new AqlFilter($filterCriteria);
            if (!empty($params)) {
                $filterCriteria->bindParams($params);
            }
        }

        $currentFilter = $this->getCurrentIndexFilter();
        $this->bindParams($filterCriteria->getParams());

        if (!is_null($currentFilter)) {
            $criteria = $filterCriteria->getConditionsString();
            if ($operator == AqlFilter::AND_OPERATOR) {
                $currentFilter->andFilter($criteria);
            } else {
                $currentFilter->orFilter($criteria);
            }
            return;
        }

        $this->inner[] = [self::TYPE_FILTER => $filterCriteria];
    }

    /**
     * Return the index of filter item if this is last inner item added
     * @return null|AqlFilter
     */
    protected function getCurrentIndexFilter()
    {
        if (!empty($this->inner)) {
            $filter = end($this->inner);
            $currentIndex = key($this->inner);
            if (key($filter) == self::TYPE_FILTER) {
                return $this->inner[$currentIndex][self::TYPE_FILTER];
            }
        }
        return null;
    }

    public function __toString()
    {
        return $this->get();
    }
}
