<?php

/**
 * WorldCat backend.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */

namespace VuFindSearch\Backend\WorldCatDiscovery;

use VuFindSearch\Query\AbstractQuery;

use VuFindSearch\ParamBag;

use VuFindSearch\Response\RecordCollectionInterface;
use VuFindSearch\Response\RecordCollectionFactoryInterface;

use VuFindSearch\Backend\AbstractBackend;
use VuFindSearch\Backend\Exception\BackendException;

use Zend\Session\Container;

use WorldCat\Discovery\Bib;
use WorldCat\Discovery\Offer;

use OCLC\Auth\WSKey;
use OCLC\Auth\AccessToken;


/**
 * WorldCat backend.
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class Backend extends AbstractBackend
{
    /**
     * Query builder.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder = null;

    /**
     * Constructor.
     *
     * @param RecordCollectionFactoryInterface $factory   Record collection factory
     * (null for default)
     *
     * @return void
     */
    public function __construct(RecordCollectionFactoryInterface $factory = null, $wskey, $secret, $institution, $heldBy, $databaseIDs
    ) {
        if (null !== $factory) {
            $this->setRecordCollectionFactory($factory);
        }
        $this->identifier   = null;
        
        $this->wcDiscovery = new Container('WorldCatDiscovery');
        
        $this->wskey = $wskey;
        $this->secret = $secret;
        $this->institution = $institution;
        $this->heldBy = $heldBy;
        $this->databaseIDs = $databaseIDs;
    }
    
    protected function getAccessToken()
    {
        if (empty($this->wcDiscovery->accessToken)){
            $options = array(
                    'services' => array('WorldCatDiscoveryAPI refresh_token')
            );
            $wskey = new WSKey($this->wskey, $this->secret, $options);
            $accessToken = $wskey->getAccessTokenWithClientCredentials($this->institution, $this->institution);
            $this->wcDiscovery->accessToken = $accessToken;
        }
        return $this->wcDiscovery->accessToken; 
    }

    /**
     * Perform a search and return record collection.
     *
     * @param AbstractQuery $query  Search query
     * @param integer       $offset Search offset
     * @param integer       $limit  Search limit
     * @param ParamBag      $params Search backend parameters
     *
     * @return RecordCollectionInterface
     */
    public function search(AbstractQuery $query, $offset, $limit,
        ParamBag $params = null
    ) {
        if (null === $params) {
            $params = new ParamBag();
        }
        
        $options = array();
        $options['dbIds'] = $this->databaseIDs;
        $facets = $params->get('facets');
        if (!empty($facets)) {
            $options['facetFields'] = $facets;
        }
        $sort = $params->get('sortBy');
        if (!empty($sort)) {
            $options['sortBy'] = current($sort);
        }
        //$options['facetQueries'] = $facetQueries;
        $options['startIndex'] = $offset;
        $options['itemsPerPage'] = $limit;
        
        $params->mergeWith($this->getQueryBuilder()->build($query));
        $response = Bib::search(current($params->get('query')), $this->getAccessToken(), $options);
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Retrieve a single document.
     *
     * @param string   $id     Document identifier
     * @param ParamBag $params Search backend parameters
     *
     * @return RecordCollectionInterface
     */
    public function retrieve($id, ParamBag $params = null)
    {
        $options = array('heldBy' => $this->heldBy);
        $response   = Offer::findByOclcNumber($id, $this->getAccessToken(), $options);
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Set the query builder.
     *
     * @param QueryBuilder $queryBuilder Query builder
     *
     * @return void
     */
    public function setQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Return query builder.
     *
     * Lazy loads an empty QueryBuilder if none was set.
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        if (!$this->queryBuilder) {
            $this->queryBuilder = new QueryBuilder();
        }
        return $this->queryBuilder;
    }

    /**
     * Return the record collection factory.
     *
     * Lazy loads a generic collection factory.
     *
     * @return RecordCollectionFactoryInterface
     */
    public function getRecordCollectionFactory()
    {
        if ($this->collectionFactory === null) {
            $this->collectionFactory = new Response\RecordCollectionFactory();
        }
        return $this->collectionFactory;
    }

    /// Internal API

    /**
     * Create record collection.
     *
     * @param array $records Records to process
     *
     * @return RecordCollectionInterface
     */
    protected function createRecordCollection($records)
    {
        if ($records instanceof \WorldCat\Discovery\Error) {
            throw new BackendException(
                $records->getErrorCode() . ': ' . $records->getErrorMessage()
            );
        }
        return $this->getRecordCollectionFactory()->factory($records);
    }
}