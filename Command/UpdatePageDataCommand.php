<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\Command;

use Phlexible\Bundle\IndexerBundle\Document\DocumentInterface;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\MediaManagerBundle\Entity\FileUsage;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update page data job
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class UpdatePageDataCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('indexer-page-media:update-page-data')
            ->setDescription('Update media document with page data.')
            ->addArgument('eid', InputArgument::REQUIRED, 'EID')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $eid = $input->getArgument('eid');

        $mediaMapper = $this->getContainer()->get('phlexible_indexer_page_media.mapper');

        $affectedDocuments = array_merge(
            $this->fetchDocumentsByFolderId($eid),
            $this->fetchDocumentsByFileId($eid),
            $this->fetchDocumentsByEid($eid)
        );

        $output->writeln('Number of affected documents: ' . count($affectedDocuments));

        foreach ($affectedDocuments as $document) {
            /* @var $document MediaDocument */
            $mediaMapper->applyElementDataToMediaDocument($document);
            unset($document['copy']);
            unset($document['score']);
            unset($document['cleantitle']);
        }

        $this->storeDocuments($affectedDocuments);

        return 0;
    }

    /**
     * @return array of <indexer id> => <indexer document>
     */
    protected function fetchDocumentsByFolderId($eid)
    {
        $folderUsage = $this->getContainer()->get('phlexible_element.folder_usage');
        $mediaQuery  = $this->getContainer()->get('phlexible_indexer_media.query');
        $search      = $this->getContainer()->get('phlexible_indexer.search');

        $result = array();

        $usage = $folderUsage->getAllByEid($eid, FileUsage::STATUS_ONLINE);

        foreach ($usage as $folderUsage) {
            $mediaQuery
                ->parseInput('')
                ->setFilters(array('parent_folder_ids' => $folderUsage['folder_id']));

            $documents = $search->query($mediaQuery);

            foreach ($documents as $document) {
                /* @var $document DocumentInterface */
                $result[$document->getIdentifier()] = $document;
            }
        }

        return $result;
    }

    /**
     * @return array of <indexer id> => <indexer document>
     */
    private function fetchDocumentsByFileId($eid)
    {
        $fileUsage  = $this->getContainer()->get('phlexible_element.file.usage');
        $mediaQuery = $this->getContainer()->get('phlexible_indexer_media.query');
        $search     = $this->getContainer()->get('phlexible_indexer.search');

        $result = array();

        $usage = $fileUsage->getAllByEid($eid, FileUsage::STATUS_ONLINE);

        foreach ($usage as $fileUsage) {
            $mediaQuery
                ->parseInput('')
                ->setFilters(array('file_id' => $fileUsage['file_id']));

            $documents = $search->query($mediaQuery);

            foreach ($documents as $document) {
                /* @var $document DocumentInterface */
                $result[$document->getIdentifier()] = $document;
            }
        }

        return $result;
    }

    /**
     * @return array of <indexer id> => <indexer document>
     */
    protected function fetchDocumentsByEid($eid)
    {
        $mediaQuery = $this->getContainer()->get('phlexible_indexer_media.query');
        $search     = $this->getContainer()->get('phlexible_indexer.search');

        $result = array();

        $mediaQuery
            ->parseInput('')
            ->setFilters(array('eids' => $eid));

        $documents = $search->query($mediaQuery);

        foreach ($documents as $document) {
            /* @var $document DocumentInterface */
            $result[$document->getIdentifier()] = $document;
        }

        return $result;
    }

    /**
     * @param MediaDocument[] $documents
     */
    private function storeDocuments(array $documents)
    {
        $storage = $this->getContainer()->get('phlexible_indexer_media.storage');

        $updateQuery = $storage->createUpdate();
        foreach ($documents as $document) {
            $updateQuery->add($document);
        }
        $storage->update($updateQuery);
    }
}
