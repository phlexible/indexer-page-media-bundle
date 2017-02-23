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
use Phlexible\Bundle\IndexerBundle\Document\DocumentInterface;
use Phlexible\Bundle\IndexerPageBundle\Document\PageDocument;
use Phlexible\Bundle\IndexerPageMediaBundle\Event\MapDocumentEvent;
use Phlexible\Bundle\IndexerPageMediaBundle\IndexerPageMediaEvents;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\MediaSiteBundle\Site\SiteManager;
use Phlexible\Bundle\SiterootBundle\Entity\Siteroot;
use Phlexible\Bundle\SiterootBundle\Model\SiterootManagerInterface;
use Phlexible\Bundle\TreeBundle\Tree\TreeManager;
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
     * @var VolumeManagerInterface
     */
    private $volumeManager;

    /**
     * @var TreeManager
     */
    private $treeManager;

    /**
     * @var FileUsage
     */
    private $fileUsage;

    /**
     * @var FolderUsage
     */
    private $folderUsage;

    /**
     * @var SiterootManagerInterface
     */
    private $siterootManager;

    /**
     * @param Connection               $connection
     * @param EventDispatcherInterface $dispatcher
     * @param VolumeManagerInterface   $volumeManager
     * @param TreeManager              $treeManager
     * @param FileUsage                $fileUsage
     * @param FolderUsage              $folderUsage
     * @param SiterootManagerInterface $siterootManager
     */
    public function __construct(
        Connection $connection,
        EventDispatcherInterface $dispatcher,
        VolumeManagerInterface $volumeManager,
        TreeManager $treeManager,
        FileUsage $fileUsage,
        FolderUsage $folderUsage,
        SiterootManagerInterface $siterootManager
    ) {
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
        $this->volumeManager = $volumeManager;
        $this->treeManager = $treeManager;
        $this->fileUsage = $fileUsage;
        $this->folderUsage = $folderUsage;
        $this->siterootManager = $siterootManager;
    }

    /**
     * Add element information to document
     *
     * @param MediaDocument $mediaDocument
     */
    public function applyPageDataToMediaDocument(MediaDocument $mediaDocument)
    {
        $fields = array('eids', 'tids', 'siteroots', 'languages', 'context', 'restricted');

        foreach ($fields as $field) {
            unset($mediaDocument[$field]);
        }

        $assetType = $mediaDocument->getValue('asset_type');

        $eids = $this->fetchEidsByUsage($mediaDocument);

        foreach ($eids as $eid) {
            $this->elementsQueryEid->parseInput($eid);

            $results = $this->indexerSearch->query($this->elementsQueryEid);

            foreach ($results as $pageDocument) {
                /* @var $pageDocument PageDocument */
                $siterootId = $pageDocument->getValue('siteroot');
                $siteroot   = $this->siterootManager->getById($siterootId);

                if (!$this->isAssetTypeIndexible($siteroot, $assetType)) {
                    continue;
                }

                if ($this->elementContainsFile($mediaDocument, $pageDocument)) {
                    $this->applyPageFields($mediaDocument, $pageDocument);
                }
            }
        }
    }

    /**
     * Checks if a media file should be indexed.
     *
     * @param Siteroot $siteroot
     * @param string   $type
     *
     * @return boolean
     */
    public function isAssetTypeIndexible(Siteroot $siteroot, $type)
    {
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
    public function scanFolderRecursive(Siteroot $siteroot)
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
    public function isFileIndexingEnabled(Siteroot $siteroot)
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
    public function isFieldTypeIndexible(Siteroot $siteroot, $type)
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
    public function getIndexibleFieldTypes(Siteroot $siteroot)
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
     *
     * @return array
     */
    private function fetchEidsByUsage(MediaDocument $mediaDocument)
    {
        $parentFolderIds = $mediaDocument->get('parent_folder_ids');
        $fileId          = $mediaDocument->get('file_id');
        $fileVersion     = $mediaDocument->get('file_version');

        $eids = array_unique(
            array_merge(
                $this->fetchEidsWhereFileIsUsedIn($fileId, $fileVersion),
                $this->fetchEidsWhereParentFolderOfFileIsUsedIn($parentFolderIds)
            )
        );

        return $eids;
    }

    /**
     * @param string $fileId
     * @param int    $fileVersion
     *
     * @return int
     */
    private function fetchEidsWhereFileIsUsedIn($fileId, $fileVersion)
    {
        $fileUsages = $this->fileUsage->getAllByFileId(
            $fileId,
            $fileVersion,
            FileUsage::STATUS_ONLINE
        );

        return ArrayUtil::column($fileUsages, 'usage_id', true, true);
    }

    /**
     * @param array $parentFolderIds
     *
     * @return array
     */
    private function fetchEidsWhereParentFolderOfFileIsUsedIn(array $parentFolderIds)
    {
        $folderUsages = $this->folderUsage->getAllByStatus(FolderUsage::STATUS_ONLINE);

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
     * @param PageDocument  $pageDocument
     *
     * @return bool
     */
    private function elementContainsFile(MediaDocument $mediaDocument, PageDocument $pageDocument)
    {
        $siterootId = $pageDocument->get('siteroot');
        $language   = $pageDocument->get('language');
        $tid        = $pageDocument->get('tid');
        $eid        = $pageDocument->get('eid');

        $tree          = $this->treeManager->getBySiteRootId($siterootId);
        $onlineVersion = $tree->getOnlineVersion($tid, $language);
        $siteroot      = $tree->getSiteRoot();

        if (!$onlineVersion) {
            return false;
        }

        $folderId        = $mediaDocument->getValue('folder_id');
        $fileId          = $mediaDocument->getValue('file_id');
        $fileVersion     = $mediaDocument->getValue('file_version');
        $parentFolderIds = $mediaDocument->getValue('parent_folder_ids');


        if ($this->scanFolderRecursive($siteroot)) {
            $mediaIds   = $parentFolderIds;
            $mediaIds[] = "$fileId;$fileVersion";
        } else {
            $mediaIds = array("$fileId;$fileVersion", $folderId);
        }

        $db = $this->dbPool->read;

        $select = $db->select()
            ->from(array('edl' => $db->prefix . 'element_data_language'), 'eid')
            ->where('edl.eid = ?', $eid)
            ->where('edl.language = ?', $language)
            ->where('edl.version = ?', $onlineVersion)
            ->where('edl.content IN (?)', $mediaIds)
            ->limit(1);

        $indexibleFieldTypes = $this->getIndexibleFieldTypes($siteroot);

        if (count($indexibleFieldTypes)) {
            $select
                ->join(
                    array('ed' => $db->prefix . 'element_data'),
                    'ed.data_id = edl.data_id AND ed.eid = edl.eid AND ed.version = edl.version',
                    array()
                )
                ->join(
                    array('ets' => $db->prefix . 'elementtype_structure'),
                    'ets.ds_id = ed.ds_id',
                    array()
                )
                ->where('ets.field_type in (?)', $indexibleFieldTypes);
        }

        $result = (integer) $db->fetchOne($select);

        return 0 !== $result;
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param PageDocument  $pageDocument
     */
    private function applyPageFields(MediaDocument $mediaDocument, PageDocument $pageDocument)
    {
        $mapping = array(
            'eids'      => 'eid',
            'tids'      => 'tid',
            'siteroots' => 'siteroot',
            'languages' => 'language',
            'context'   => 'context',
        );

        $this->mergeFields($mediaDocument, $pageDocument, $mapping);

        if ('1' == $pageDocument->getValue('restricted')) {
            $mediaDocument->setValue('restricted', 1);
        } else {
            $mediaDocument->setValue('restricted', 0);
        }

        $event = new MapDocumentEvent($mediaDocument, $pageDocument);
        $this->dispatcher->dispatch(IndexerPageMediaEvents::MAP_DOCUMENT, $event);
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param PageDocument  $pageDocument
     * @param array         $mapping
     */
    private function mergeFields(MediaDocument $mediaDocument, PageDocument $pageDocument, array $mapping)
    {
        foreach ($mapping as $mediaField => $elementField) {
            $this->mergeField($mediaDocument, $mediaField, $pageDocument, $elementField);
        }
    }

    /**
     * @param MediaDocument $mediaDocument
     * @param string        $mediaField
     * @param PageDocument  $pageDocument
     * @param string        $elementField
     */
    private function mergeField(
        MediaDocument $mediaDocument,
        $mediaField,
        PageDocument $pageDocument,
        $elementField
    ) {
        $mediaValue   = (array) $mediaDocument->getValue($mediaField);
        $elementValue = (array) $pageDocument->getValue($elementField);

        $newValue = array_unique(array_merge($mediaValue, $elementValue));

        $mediaDocument->setValue($mediaField, $newValue);
    }
}
