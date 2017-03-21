<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\Mapper;

use Doctrine\DBAL\Connection;
use Elastica\Filter\Term;
use Elastica\Query;
use Elastica\Result;
use Phlexible\Bundle\ElasticaBundle\Elastica\Index;
use Phlexible\Bundle\IndexerMediaBundle\Indexer\MediaDocumentDescriptor;
use Phlexible\Bundle\IndexerPageMediaBundle\Event\MapDocumentEvent;
use Phlexible\Bundle\IndexerPageMediaBundle\IndexerPageMediaEvents;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\MediaManagerBundle\Entity\FolderUsage;
use Phlexible\Bundle\SiterootBundle\Entity\Siteroot;
use Phlexible\Bundle\SiterootBundle\Model\SiterootManagerInterface;
use Phlexible\Bundle\TreeBundle\Tree\TreeManager;
use Phlexible\Component\MediaManager\Usage\FileUsageManager;
use Phlexible\Component\MediaManager\Usage\FolderUsageManager;
use Phlexible\Component\MediaManager\Volume\ExtendedFileInterface;
use Phlexible\Component\Volume\VolumeManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Page to media mapper
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class PageToMediaMapper
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * TODO: NEVER USED!!!
     * @var VolumeManager
     */
    private $volumeManager;

    /**
     * @var TreeManager
     */
    private $treeManager;

    /**
     * @var FileUsageManager
     */
    private $fileUsageManager;

    /**
     * @var FolderUsageManager
     */
    private $folderUsageManager;

    /**
     * @var SiterootManagerInterface
     */
    private $siterootManager;

    /**
     * @var Index
     */
    private $index;

    /**
     * @param Connection               $connection
     * @param EventDispatcherInterface $dispatcher
     * @param VolumeManager            $volumeManager      NEVER USED!!!
     * @param TreeManager              $treeManager
     * @param FileUsageManager         $fileUsageManager
     * @param FolderUsageManager       $folderUsageManager
     * @param SiterootManagerInterface $siterootManager
     * @param Index                    $index
     */
    public function __construct(
        Connection $connection,
        EventDispatcherInterface $dispatcher,
        VolumeManager $volumeManager,
        TreeManager $treeManager,
        FileUsageManager $fileUsageManager,
        FolderUsageManager $folderUsageManager,
        SiterootManagerInterface $siterootManager,
        Index $index
    ) {
        $this->connection         = $connection;
        $this->dispatcher         = $dispatcher;
        $this->volumeManager      = $volumeManager; // NEVER USED!!!
        $this->treeManager        = $treeManager;
        $this->fileUsageManager   = $fileUsageManager;
        $this->folderUsageManager = $folderUsageManager;
        $this->siterootManager    = $siterootManager;
        $this->index              = $index;
    }

    /**
     * Add element information to document
     *
     * @param MediaDocument           $mediaDocument
     * @param MediaDocumentDescriptor $descriptor
     */
    public function applyPageDataToMediaDocument(MediaDocument $mediaDocument, MediaDocumentDescriptor $descriptor)
    {
        $file = $descriptor->getFile();

        $fields = ['typeIds', 'nodeIds', 'siterootIds', 'languages'];

        foreach ($fields as $field) {
            $mediaDocument->set($field, '');
        }

        $mediaType = $mediaDocument->get('media_type');

        $eids = $this->fetchElementIdsByUsage($mediaDocument, $file);

        foreach ($eids as $eid) {
            $results = $this->findPageDocuments($eid);

            foreach ($results as $result) {
                /* @var $result Result */
                $siterootId = $result->getData()['siterootId'];
                $siteroot   = $this->siterootManager->find($siterootId);

                if ($this->elementContainsFile($mediaDocument, $result, $siteroot)) {
                    $this->applyPageFields($mediaDocument, $result);
                }
            }
        }
    }

    private function findPageDocuments($nodeId)
    {
        $query  = new Query();
        $filter = new Term();
        $filter->setTerm('typeId', $nodeId);
        $query->setPostFilter($filter);
        $resultSet = $this->index->getType('page')->search($query);

        return $resultSet;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param ExtendedFileInterface $file
     *
     * @return array
     */
    private function fetchElementIdsByUsage(MediaDocument $mediaDocument, ExtendedFileInterface $file)
    {
        $parentFolderIds = $mediaDocument->get('parent_folder_ids');

        $eids = array_unique(
            array_merge(
                $this->fetchElementIdsWhereFileIsUsedIn($file),
                $this->fetchElementIdsWhereParentFolderOfFileIsUsedIn($file, $parentFolderIds)
            )
        );

        return $eids;
    }

    /**
     * @param ExtendedFileInterface $file
     *
     * @return array
     */
    private function fetchElementIdsWhereFileIsUsedIn(ExtendedFileInterface $file)
    {
        $fileUsages = $this->fileUsageManager->getUsedIn($file);

        return array_column($fileUsages, 'usage_id');
    }

    /**
     * @param ExtendedFileInterface $file
     * @param string[]      $parentFolderIds
     *
     * @return array
     */
    private function fetchElementIdsWhereParentFolderOfFileIsUsedIn(ExtendedFileInterface $file, array $parentFolderIds)
    {
        $eids = [];

        // TODO: AS LONG AS WE DON'T KNOW WHY WE SHOULD NEED PARENT FOLDERS WE STOP RIGHT HERE!
        return $eids;

        $folderUsages = $this->folderUsageManager->getUsedIn(FolderUsage::STATUS_ONLINE);

        foreach ($folderUsages as $usage) {
            if (in_array($usage['folder_id'], $parentFolderIds)) {
                $eids[] = $usage['usage_id'];
            }
        }

        return $eids;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param Result        $pageDocument
     * @param Siteroot      $siteroot
     *
     * @return bool
     */
    private function elementContainsFile(MediaDocument $mediaDocument, Result $pageDocument, Siteroot $siteroot)
    {
        $data       = $pageDocument->getData();
        $siterootId = $data['siterootId'];
        $language   = $data['language'];
        $nodeId     = $data['nodeId'];
        $typeId     = $data['typeId'];

        $tree          = $this->treeManager->getBySiteRootId($siterootId);
        $node          = $tree->get($nodeId);
        $onlineVersion = $tree->getPublishedVersion($node, $language);

        if (!$onlineVersion) {
            return false;
        }

        $folderId    = $mediaDocument->get('folder_id');
        $fileId      = $mediaDocument->get('file_id');
        $fileVersion = $mediaDocument->get('file_version');

        $qb = $this->connection->createQueryBuilder();

        $mediaIds = [
            $qb->expr()->literal("$fileId;$fileVersion"),
            $qb->expr()->literal($folderId),
        ];

        $indexibleFieldTypes = [
            $qb->expr()->literal('file'),
            $qb->expr()->literal('folder'),
        ];

        $qb
            ->select('esv.id')
            ->from('element_structure_value', 'esv')
            ->where($qb->expr()->eq('esv.eid', $typeId))
            ->andWhere($qb->expr()->eq('esv.language', $qb->expr()->literal($language)))
            ->andWhere($qb->expr()->eq('esv.version', $onlineVersion))
            ->andWhere($qb->expr()->in('esv.content', $mediaIds))
            ->andWhere($qb->expr()->in('esv.type', $indexibleFieldTypes))
            ->setMaxResults(1);


        $result = $this->connection->fetchAssoc($qb->getSQL());

        return (bool) $result;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param Result        $pageDocument
     */
    private function applyPageFields(MediaDocument $mediaDocument, Result $pageDocument)
    {
        $mapping = [
            'typeIds'     => 'typeId',
            'nodeIds'     => 'nodeId',
            'siterootIds' => 'siterootId',
            'languages'   => 'language',
        ];

        $this->mergeFields($mediaDocument, $pageDocument, $mapping);

        $event = new MapDocumentEvent($mediaDocument, $pageDocument);
        $this->dispatcher->dispatch(IndexerPageMediaEvents::MAP_DOCUMENT, $event);
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param Result        $pageDocument
     * @param array         $mapping
     */
    private function mergeFields(MediaDocument $mediaDocument, Result $pageDocument, array $mapping)
    {
        foreach ($mapping as $mediaField => $elementField) {
            $this->mergeField($mediaDocument, $mediaField, $pageDocument, $elementField);
        }
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param string        $mediaField
     * @param Result        $pageDocument
     * @param string        $elementField
     */
    private function mergeField(MediaDocument $mediaDocument, $mediaField, Result $pageDocument, $elementField)
    {
        $mediaValue = $mediaDocument->get($mediaField);
        if ($mediaValue) {
            $mediaValue = (array) $mediaValue;
        } else {
            $mediaValue = [];
        }
        $elementValue = (array) $pageDocument->getData()[$elementField];

        $newValue = array_unique(array_merge($mediaValue, $elementValue));

        $mediaDocument->set($mediaField, $newValue);
    }
}

