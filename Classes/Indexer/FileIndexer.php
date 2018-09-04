<?php
namespace HMMH\SolrFileIndexer\Indexer;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Sascha Wilking <sascha.wilking@hmmh.de>, hmmh multimediahaus AG
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

use Apache_Solr_Document;
use ApacheSolrForTypo3\Solr\IndexQueue\Indexer;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\NoSolrConnectionFoundException;
use HMMH\SolrFileIndexer\Configuration\ExtensionConfig;
use HMMH\SolrFileIndexer\Interfaces\DocumentUrlInterface;
use HMMH\SolrFileIndexer\Service\Adapter\SolrConnection;
use HMMH\SolrFileIndexer\Service\ServiceFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException;

/**
 * Class FileIndexer
 *
 * @package HMMH\SolrFileIndexer\Indexer
 */
class FileIndexer extends Indexer
{
    const FILE_TABLE = 'sys_file_metadata';

    /**
     * @var array
     */
    protected $fileCache = [];

    /**
     * @var Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * @var ExtensionConfig
     */
    protected $extensionConfiguration;

    /**
     * Indexes an item from the indexing queue.
     *
     * @param Item $item An index queue item
     *
     * @return true
     */
    public function index(Item $item)
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfig::class);

        $this->type = $item->getType();
        $this->setLogging($item);

        $solrConnections = $this->getSolrConnectionsByItem($item);
        foreach ($solrConnections as $systemLanguageUid => $solrConnection) {
            $this->solr = $solrConnection;
            // check whether we should move on at all
            $indexableFile = $this->getIndexableFile($item, (int)$systemLanguageUid);
            if ($indexableFile !== null) {
                $this->indexItem($item, $systemLanguageUid);
            }
        }

        return true;
    }

    /**
     * @param Item $item
     * @param int  $language
     *
     * @return Apache_Solr_Document
     * @throws \TYPO3\CMS\Core\Package\Exception\UnknownPackageException
     */
    protected function itemToDocument(Item $item, $language = 0)
    {
        $content = '';

        $document = parent::itemToDocument($item, $language);
        if (!($document instanceof Apache_Solr_Document) && $this->extensionConfiguration->ignoreLocalization()) {
            $document = parent::itemToDocument($item, 0);
        }
        $baseContent = $document->getField('content');
        if (!empty($baseContent['value']) && is_string($baseContent['value'])) {
            $content .= $baseContent['value'];
        }
        $content .= $this->txtFromFile($this->fetchFile($item));

        $content = $this->emitPostAddContentAfterSignal($document, $content);

        $document->setField('content', $content);
        $url = $document->getField('url');
        if ($url === false) {
            $this->addDocumentUrl($item, $document);
        }

        return $document;
    }

    /**
     * @param File $file
     *
     * @return string
     * @throws \TYPO3\CMS\Core\Package\Exception\UnknownPackageException
     */
    protected function txtFromFile(File $file)
    {
        try {
            $service = ServiceFactory::getTika();
            $content = $service->extractText($file);
            $content = $this->cleanupContent($content);
        } catch (NoSolrConnectionFoundException $e) {
            $content = '';
        }

        return $content;
    }

    /**
     * @param Item $item
     * @param int $languageId
     *
     * @return mixed|null
     */
    protected function getIndexableFile(Item $item, $languageId)
    {
        $record = $item->getRecord();
        $rootPage = $item->getRootPageUid();
        $allowedRootPages = empty($record['enable_indexing']) ? [] : GeneralUtility::trimExplode(',', $record['enable_indexing']);
        $indexableLanguage = $this->checkLanguageForIndexing($languageId, $record);

        $storedFile = $this->fetchFile($item);
        if ($storedFile !== null && $indexableLanguage && in_array($rootPage, $allowedRootPages)) {
            return $storedFile;
        }

        return null;
    }

    /**
     * @param Item $item
     *
     * @return File
     */
    protected function fetchFile(Item $item)
    {
        $sysFileUid = (int)$item->getRecord()['file'];
        if (array_key_exists($sysFileUid, $this->fileCache)) {
            return $this->fileCache[$sysFileUid];
        }

        $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
        return $this->fileCache[$sysFileUid] = $fileRepository->findByUid($sysFileUid);
    }

    /**
     * @param Item $item
     * @param Apache_Solr_Document $document
     */
    protected function addDocumentUrl(Item $item, Apache_Solr_Document $document)
    {
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_file_indexer']['addDocumentUrl'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_file_indexer']['addDocumentUrl'] as $classReference) {
                $documentUrlObject = GeneralUtility::makeInstance($classReference);

                if ($documentUrlObject instanceof DocumentUrlInterface) {
                    $documentUrlObject->addDocumentUrl($item, $document);
                } else {
                    throw new \UnexpectedValueException(
                        get_class($documentUrlObject) . ' must implement interface ' . DocumentUrlInterface::class,
                        1345807460
                    );
                }
            }
        } else {
            $file = $this->fetchFile($item);
            if ($file instanceof FileInterface) {
                $document->setField('url', $file->getPublicUrl());
            }
        }
    }

    /**
     * @param string $content
     *
     * @return string
     */
    protected function cleanupContent($content): string
    {
        $content = trim($content);

        return $this->emitPostCleanContentSignal($content);
    }

    /**
     * @param $content
     *
     * @return string
     */
    protected function emitPostCleanContentSignal($content)
    {
        try {
            $result = $this->getSignalSlotDispatcher()->dispatch(self::class, 'cleanupContent', [$content]);
            $returnValue = $result[0];
        } catch (InvalidSlotException $ise) {
            $returnValue = $content;
        } catch (InvalidSlotReturnException $isre) {
            $returnValue = $content;
        }

        return $returnValue;
    }

    /**
     * @param Apache_Solr_Document $document
     * @param string $content
     *
     * @return string
     */
    protected function emitPostAddContentAfterSignal(Apache_Solr_Document $document, $content)
    {
        try {
            $result = $this->getSignalSlotDispatcher()->dispatch(self::class, 'addContentAfter', [$document, $content]);
            $returnValue = $result[1];
        } catch (InvalidSlotException $ise) {
            $returnValue = $content;
        } catch (InvalidSlotReturnException $isre) {
            $returnValue = $content;
        }

        return $returnValue;
    }

    /**
     * @return Dispatcher
     */
    protected function getSignalSlotDispatcher(): Dispatcher
    {
        if ($this->signalSlotDispatcher === null) {
            $this->signalSlotDispatcher = GeneralUtility::makeInstance(Dispatcher::class);
        }

        return $this->signalSlotDispatcher;
    }

    /**
     * @param int $languageId
     * @param array $record
     *
     * @return bool
     */
    protected function checkLanguageForIndexing($languageId, $record)
    {
        $languageField = $this->getLanguageField();
        $indexableLanguage = (int)$record[$languageField] === $languageId;

        if ($this->extensionConfiguration->ignoreLocalization() === true &&
            $languageId > 0 &&
            $indexableLanguage === false &&
            (int)$record[$languageField] === 0
        ) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::FILE_TABLE);
            $metadata = $queryBuilder->select('uid')
                ->from(self::FILE_TABLE)
                ->where(
                    $queryBuilder->expr()->eq(
                        $this->getLanguageParentField(),
                        $queryBuilder->createNamedParameter($record['uid'], \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        $languageField,
                        $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            if (empty($metadata['uid'])) {
                $indexableLanguage = true;
            }
        }

        if ($languageId > 0) {
            if ((int)$record[$languageField] > 0) {
                $this->removeOriginalFromIndex($record[$this->getLanguageParentField()]);
            } else {
                $this->removeOriginalFromIndex($record['uid']);
            }
        }

        return $indexableLanguage;
    }

    /**
     * @param int $uid
     */
    protected function removeOriginalFromIndex($uid)
    {
        try {
            $connectionAdapter = GeneralUtility::makeInstance(SolrConnection::class);
            $connectionAdapter->deleteByQuery($this->solr, 'type:' . self::FILE_TABLE . ' AND uid:' . (int)$uid);
        } catch (\Apache_Solr_HttpTransportException $e) {
            // do nothing
        }
    }

    /**
     * @return string
     */
    protected function getLanguageField()
    {
        return $GLOBALS['TCA'][self::FILE_TABLE]['ctrl']['languageField'];
    }

    /**
     * @return string
     */
    protected function getLanguageParentField()
    {
        return $GLOBALS['TCA'][self::FILE_TABLE]['ctrl']['transOrigPointerField'];
    }
}
