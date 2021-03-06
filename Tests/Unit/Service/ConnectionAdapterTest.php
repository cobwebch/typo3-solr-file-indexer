<?php
namespace HMMH\SolrFileIndexer\Tests\Unit\Service;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2018 Sascha Wilking <sascha.wilking@hmmh.de>, hmmh
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use HMMH\SolrFileIndexer\Service\ConnectionAdapter;
use Nimut\TestingFramework\MockObject\AccessibleMockObjectInterface;
use Nimut\TestingFramework\TestCase\UnitTestCase;
use PHPUnit_Framework_MockObject_MockObject;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\ProphecySubjectInterface;

/**
 * Class ConnectionAdapterTest
 *
 * @package HMMH\SolrFileIndexer\Tests\Unit\Service
 */
class ConnectionAdapterTest extends UnitTestCase
{

    /**
     * @var ConnectionAdapter|AccessibleMockObjectInterface|PHPUnit_Framework_MockObject_MockObject
     */
    protected $instance;

    /**
     * @var ObjectProphecy|ConnectionManager
     */
    protected $connectionManagerProphecy;

    /**
     * @var ProphecySubjectInterface|ConnectionManager
     */
    protected $connectionManager;

    public function setUp()
    {
        parent::setUp();

        $this->instance = new ConnectionAdapter();

        $this->connectionManagerProphecy = $this->prophesize(ConnectionManager::class);
        $this->connectionManager = $this->connectionManagerProphecy->reveal();
        $this->inject($this->instance, 'connectionManager', $this->connectionManager);
    }

    /**
     * @test
     */
    public function getConnectionManagerReturnConnectionManager()
    {
        $result = $this->instance->getConnectionManager();
        $this->assertInstanceOf(ConnectionManager::class, $result);
    }

    /**
     * @test
     */
    public function getConnectionByPageIdReturnObjectByVersion()
    {
        $solrConnection = $this->prophesize(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $this->connectionManagerProphecy->getConnectionByPageId(1)->willReturn($solrConnection->reveal());
        $result = $this->instance->getConnectionByPageId(1);

        $this->assertInstanceOf(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class, $result);
    }

    /**
     * @test
     */
    public function getConnectionsBySiteReturnObjectByVersion()
    {
        $siteMock = $this->getAccessibleMock(Site::class, ['getSolrConnectionConfiguration'], [], '', false);
        $solrConnection = $this->prophesize(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $this->connectionManagerProphecy->getConnectionsBySite($siteMock)->willReturn([$solrConnection->reveal()]);
        $result = $this->instance->getConnectionsBySite($siteMock);

        $this->assertInstanceOf(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class, $result[0]);
    }

    /**
     * @test
     */
    public function getSolrWriteServiceReturnObjectByVersion()
    {
        $instance = $this->getAccessibleMock(ConnectionAdapter::class, ['dummy']);

        $solrWriteServiceMock = $this->getAccessibleMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService::class,
            ['dummy'],
            [],
            '',
            false
        );
        $solrConnection = $this->getAccessibleMock(
            \ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class,
            ['getWriteService'],
            [],
            '',
            false
        );
        $solrConnection->expects($this->once())->method('getWriteService')->willReturn($solrWriteServiceMock);

        $result = $instance->_callRef('getSolrWriteService', $solrConnection);

        $this->assertInstanceOf(\ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService::class, $result);
    }
}
