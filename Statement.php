<?php
/**
 * This file is part of DoctrineRestDriver.
 *
 * DoctrineRestDriver is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * DoctrineRestDriver is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DoctrineRestDriver.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Circle\DoctrineRestDriver;

use Circle\DoctrineRestDriver\Annotations\RoutingTable;
use Circle\DoctrineRestDriver\Enums\HttpMethods;
use Circle\DoctrineRestDriver\Exceptions\Exceptions;
use Circle\DoctrineRestDriver\Exceptions\RequestFailedException;
use Circle\DoctrineRestDriver\Security\AuthStrategy;
use Circle\DoctrineRestDriver\Transformers\MysqlToRequest;
use Circle\DoctrineRestDriver\Types\Authentication;
use Circle\DoctrineRestDriver\Types\Format;
use Circle\DoctrineRestDriver\Types\Request;
use Circle\DoctrineRestDriver\Types\Result;
use Circle\DoctrineRestDriver\Types\SqlQuery;
use Circle\DoctrineRestDriver\Validation\Assertions;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Symfony\Component\HttpFoundation\Response;
use Circle\DoctrineRestDriver\Formatters\Formatter;

/**
 * Executes the statement - sends requests to an api
 *
 * @author    Tobias Hauck <tobias@circle.ai>
 * @copyright 2015 TeeAge-Beatz UG
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class Statement implements \IteratorAggregate, StatementInterface {

    /**
     * @var string
     */
    private $query;

    /**
     * @var MysqlToRequest
     */
    private $mysqlToRequest;

    /**
     * @var array
     */
    private $params = [];

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var array
     */
    private $result;

    /**
     * @var int
     */
    private $id;

    /**
     * @var int
     */
    private $errorCode;

    /**
     * @var string
     */
    private $errorMessage;

    /**
     * @var int
     */
    private $fetchMode;

    /**
     * @var AuthStrategy
     */
    private $authStrategy;

    /**
     * @var RoutingTable
     */
    private $routings;

    /**
     * @var Formatter
     */
    private $formatter;

    /**
     * Statement constructor
     *
     * @param  string       $query
     * @param  array        $options
     * @param  RoutingTable $routings
     * @throws \Exception
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function __construct($query, array $options, RoutingTable $routings) {
        $this->query          = $query;
        $this->routings       = $routings;
        $this->mysqlToRequest = new MysqlToRequest($options, $this->routings);
        $this->restClient     = new RestClient();

        $this->authStrategy = Authentication::create($options);
        $this->formatter    = Format::create($options);
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = null) {
        $this->params[$param] = $value;
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function bindParam($column, &$variable, $type = null, $length = null) {
        return Exceptions::MethodNotImplementedException(get_class($this), 'bindParam');
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode() {
        return $this->errorCode;
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo() {
        return $this->errorMessage;
    }

    /**
     * {@inheritdoc}
     * @throws RequestFailedException
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function execute($params = null) {
        $transformedQuery = SqlQuery::quoteUrl(SqlQuery::setParams($this->query, $params !== null ? $params : $this->params));
        $rawRequest       = $this->mysqlToRequest->transform($transformedQuery);
        $request          = $this->authStrategy->transformRequest($rawRequest);

        $response = $this->restClient->send($request);
        $result   = new Result($transformedQuery, $this->formatter->decode($response->getContent()));

        $this->result = $result->get();
        $this->id     = $result->id();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount() {
        return count($this->result);
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor() {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount() {
        return empty($this->result) ? 0 : count($this->result[0]);
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null) {
        $this->fetchMode = $fetchMode;

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function fetch($fetchMode = null) {
        $fetchMode = empty($fetchMode) ? $this->fetchMode : $fetchMode;
        Assertions::assertSupportedFetchMode($fetchMode);

        return count($this->result) === 0 ? false : array_pop($this->result);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($fetchMode = null) {
        $result    = [];
        $fetchMode = empty($fetchMode) ? $this->fetchMode : $fetchMode;

        while (($row = $this->fetch($fetchMode))) array_push($result, $row);

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function fetchColumn($columnIndex = 0) {
        return Exceptions::MethodNotImplementedException(get_class($this), 'fetchColumn');
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator() {
        return $this->query;
    }

    /**
     * Returns the last auto incremented id
     *
     * @return int
     */
    public function getId() {
        return $this->id;
    }
}