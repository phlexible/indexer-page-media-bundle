<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\Indexer;

use Doctrine\DBAL\Connection;
use Elastica\Filter\Term;
use Elastica\Query;
use Elastica\Result;
use Phlexible\Bundle\ElasticaBundle\Elastica\Index;
use Phlexible\Bundle\IndexerPageBundle\Document\PageDocument;
use Phlexible\Bundle\IndexerPageMediaBundle\Event\MapDocumentEvent;
use Phlexible\Bundle\IndexerPageMediaBundle\IndexerPageMediaEvents;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\SiterootBundle\Entity\Siteroot;
use Phlexible\Bundle\SiterootBundle\Model\SiterootManagerInterface;
use Phlexible\Bundle\TreeBundle\Tree\TreeManager;
use Phlexible\Component\MediaManager\Usage\FileUsageManager;
use Phlexible\Component\MediaManager\Usage\FolderUsageManager;
use Phlexible\Component\Volume\Model\FileInterface;
use Phlexible\Component\Volume\VolumeManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Document mapper
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class DocumentMapper
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
     * @param VolumeManager            $volumeManager
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
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
        $this->volumeManager = $volumeManager;
        $this->treeManager = $treeManager;
        $this->fileUsageManager = $fileUsageManager;
        $this->folderUsageManager = $folderUsageManager;
        $this->siterootManager = $siterootManager;
        $this->index = $index;
    }

    /**
     * Add element information to document
     *
     * @param MediaDocument $mediaDocument
     * @param FileInterface $file
     */
    public function applyPageDataToMediaDocument(MediaDocument $mediaDocument, FileInterface $file)
    {
        $fields = array('typeIds', 'nodeIds', 'siterootIds', 'languages');

        foreach ($fields as $field) {
            $mediaDocument->set($field, '');
        }

        $mediaType = $mediaDocument->get('media_type');

        $eids = $this->fetchNodeIdsByUsage($mediaDocument, $file);

        foreach ($eids as $eid) {
            $results = $this->findPageDocuments($eid);

            foreach ($results as $result) {
                /* @var $result Result */
                $siterootId = $result->getData()['siterootId'];
                $siteroot   = $this->siterootManager->find($siterootId);

                if (!$this->isAssetTypeIndexible($siteroot, $mediaType)) {
                    continue;
                }

                if ($this->elementContainsFile($mediaDocument, $result, $siteroot)) {
                    $this->applyPageFields($mediaDocument, $result);
                }
            }
        }
    }

    private function findPageDocuments($nodeId)
    {
        $query = new Query();
        $filter = new Term();
        $filter->setTerm('typeId', $nodeId);
        $query->setPostFilter($filter);
        $resultSet = $this->index->getType('page')->search($query);

        return $resultSet;
    }

    /**
     * Checks if a media file should be indexed.
     *
     * @param Siteroot $siteroot
     * @param string   $type
     *
     * @return boolean
     */
    private function isAssetTypeIndexible(Siteroot $siteroot, $type)
    {
        return true;

        // TODO: fix properties
        $key = 'indexer.elements.media.' . strtolower($type);

        $value = (boolean) $siteroot->getProperty($key);

        return $value;
    }

    /**
     * Checks if a media folders shoulb be scanned recursive.
     *
     * @param Siteroot $siteroot
     *
     * @return boolean
     */
    private function scanFolderRecursive(Siteroot $siteroot)
    {
        $value = (boolean) $siteroot->getProperty('indexer.elements.media.folder.recursiv');

        return $value;
    }

    /**
     * Checks if any media file should be indexed.
     *
     * @param Siteroot $siteroot
     *
     * @return boolean
     */
    private function isFileIndexingEnabled(Siteroot $siteroot)
    {
        return $this->isAssetTypeIndexible($siteroot, 'audio')
            || $this->isAssetTypeIndexible($siteroot, 'document')
            || $this->isAssetTypeIndexible($siteroot, 'flash')
            || $this->isAssetTypeIndexible($siteroot, 'image')
            || $this->isAssetTypeIndexible($siteroot, 'video');
    }

    /**
     * Checks if a media file should be indexed (by field type).
     *
     * @param Siteroot $siteroot
     * @param string   $type
     *
     * @return boolean
     */
    private function isFieldTypeIndexible(Siteroot $siteroot, $type)
    {
        $key = 'indexer.elements.media.field.' . strtolower($type);

        $value = (bool) $siteroot->getProperty($key);

        return $value;
    }

    /**
     * Get all field types which should be indexed.
     *
     * @param Siteroot $siteroot
     *
     * @return array
     */
    private function getIndexibleFieldTypes(Siteroot $siteroot)
    {
        $fieldTypes = array('file', 'folder');

        $indexibleFieldTypes = array();
        foreach ($fieldTypes as $fieldType) {
            if ($this->isFieldTypeIndexible($siteroot, $fieldType)) {
                $indexibleFieldTypes[] = $fieldType;
            }
        }

        return $indexibleFieldTypes;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param FileInterface $file
     *
     * @return array
     */
    private function fetchNodeIdsByUsage(MediaDocument $mediaDocument, FileInterface $file)
    {
        $parentFolderIds = $mediaDocument->get('parent_folder_ids');

        $eids = array_unique(
            array_merge(
                $this->fetchNodeIdsWhereFileIsUsedIn($file),
                $this->fetchNodeIdsWhereParentFolderOfFileIsUsedIn($file, $parentFolderIds)
            )
        );

        return $eids;
    }

    /**
     * @param FileInterface $file
     *
     * @return array
     */
    private function fetchNodeIdsWhereFileIsUsedIn(FileInterface $file)
    {
        $fileUsages = $this->fileUsageManager->getUsedIn($file);

        return array_column($fileUsages, 'usage_id');
    }

    /**
     * @param array $parentFolderIds
     *
     * @return array
     */
    private function fetchNodeIdsWhereParentFolderOfFileIsUsedIn(FileInterface $file, array $parentFolderIds)
    {
        // TODO: weird
        return array();

        $folderUsages = $this->folderUsageManager->getUsedIn(FolderUsage::STATUS_ONLINE);

        $eids = array();
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
        $data = $pageDocument->getData();
        $siterootId = $data['siterootId'];
        $language = $data['language'];
        $nodeId = $data['nodeId'];
        $typeId = $data['typeId'];

        $tree = $this->treeManager->getBySiteRootId($siterootId);
        $node = $tree->get($nodeId);
        $onlineVersion = $tree->getPublishedVersion($node, $language);

        if (!$onlineVersion) {
            return false;
        }

        $folderId        = $mediaDocument->get('folder_id');
        $fileId          = $mediaDocument->get('file_id');
        $fileVersion     = $mediaDocument->get('file_version');
        $parentFolderIds = $mediaDocument->get('parent_folder_ids');


        if ($this->scanFolderRecursive($siteroot)) {
            $mediaIds   = $parentFolderIds;
            $mediaIds[] = "$fileId;$fileVersion";
        } else {
            $mediaIds = array("$fileId;$fileVersion", $folderId);
        }

        $qb = $this->connection->createQueryBuilder();

        foreach ($mediaIds as $index => $mediaId) {
            $mediaIds[$index] = $qb->expr()->literal($mediaId);
        }

        $qb
            ->select('esv.id')
            ->from('element_structure_value', 'esv')
            ->where($qb->expr()->eq('esv.eid', $typeId))
            ->andWhere($qb->expr()->eq('esv.language', $qb->expr()->literal($language)))
            ->andWhere($qb->expr()->eq('esv.version', $onlineVersion))
            ->andWhere($qb->expr()->in('esv.content', $mediaIds))
            ->setMaxResults(1);

        $indexibleFieldTypes = $this->getIndexibleFieldTypes($siteroot);

        if (count($indexibleFieldTypes)) {
            $qb->andWhere($qb->expr()->in('esv.type', $indexibleFieldTypes));
        }

        $result = $this->connection->fetchAssoc($qb->getSQL());

        return (bool) $result;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param Result        $pageDocument
     */
    private function applyPageFields(MediaDocument $mediaDocument, Result $pageDocument)
    {
        $mapping = array(
            'typeIds' => 'typeId',
            'nodeIds' => 'nodeId',
            'siterootIds' => 'siterootId',
            'languages' => 'language',
        );

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
        $mediaValue   = $mediaDocument->get($mediaField);
        if ($mediaValue) {
            $mediaValue = (array) $mediaValue;
        } else {
            $mediaValue = array();
        }
        $elementValue = (array) $pageDocument->getData()[$elementField];

        $newValue = array_unique(array_merge($mediaValue, $elementValue));

        $mediaDocument->set($mediaField, $newValue);
    }
}
