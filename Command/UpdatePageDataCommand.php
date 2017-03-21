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

use Doctrine\ORM\EntityManager;
use Elastica\Filter\BoolAnd;
use Elastica\Filter\Term;
use Elastica\Index;
use Elastica\Query;
use Elastica\Result;
use Phlexible\Bundle\IndexerBundle\Document\DocumentIdentity;
use Phlexible\Bundle\IndexerBundle\Document\DocumentInterface;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\IndexerMediaBundle\Indexer\MediaContentIdentifier;
use Phlexible\Bundle\IndexerPageMediaBundle\Mapper\PageToMediaMapper;
use Phlexible\Bundle\IndexerStorageElasticaBundle\Storage\ElasticaMapper;
use Phlexible\Bundle\IndexerStorageElasticaBundle\Storage\ElasticaStorage;
use Phlexible\Bundle\MediaManagerBundle\Entity\FileUsage;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update page data job
 *
 * @author Phillip Look <pl@brainbits.net>
 * @author Jens Schulze <jdschulze@brainbits.net> (Migration to Phlexible 1.x)
 */
class UpdatePageDataCommand extends ContainerAwareCommand
{
    /**
     * @var ElasticaMapper
     */
    private $elasticaMapper;

    /**
     * @var Index
     */
    private $searchIndex;


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('indexer-page-media:update-page-data')
            ->setDescription('Update media document with page data.')
            ->addArgument('eid', InputArgument::REQUIRED, 'EID');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $this->elasticaMapper = $this->getContainer()->get('phlexible_indexer_page_media.page_to_media_mapper');
        $this->elasticaMapper = $this->getContainer()->get('phlexible_indexer_storage_elastica.elastica_mapper');
        $this->searchIndex    = $this->getContainer()->get('phlexible_elastica.index');

        $eid = $input->getArgument('eid');

        $affectedDocuments = array_merge(
            $this->fetchDocumentsByFileId($eid),
            $this->fetchDocumentsByEid($eid)
        );

        $output->writeln('Number of affected documents: ' . count($affectedDocuments));

        /** @var PageToMediaMapper $mediaMapper */
        $mediaMapper = $this->getContainer()->get('phlexible_indexer_page_media.page_to_media_mapper');
        /** @var MediaContentIdentifier $contentIdentifier */
        $contentIdentifier = $this->getContainer()->get('phlexible_indexer_media.media_content_identifier');

        /* @var $document MediaDocument */
        foreach ($affectedDocuments as $document) {
            $identity   = $document->getIdentity();
            $descriptor = $contentIdentifier->createDescriptorFromIdentity($identity);
            $mediaMapper->applyPageDataToMediaDocument($document, $descriptor);
            unset($document['copy']);
            unset($document['score']);
            unset($document['cleantitle']);
        }

        $this->storeDocuments($affectedDocuments);

        return 0;
    }


    /**
     * @param int $eid
     *
     * @return DocumentInterface[]
     */
    private function fetchDocumentsByFileId($eid)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getContainer()->get('doctrine.orm.entity_manager');

        $result = [];

        $fileUsageRepository = $entityManager->getRepository('PhlexibleMediaManagerBundle:FileUsage');

        $usage = $fileUsageRepository->findBy(['usageType' => 'element', 'usageId' => $eid]);

        /** @var FileUsage $fileUsage */
        foreach ($usage as $fileUsage) {
            if (!($fileUsage->getStatus() & FileUsage::STATUS_ONLINE)) {
                continue;
            }

            $filter = (new BoolAnd())
                ->addFilter(new Term(['file_id' => $fileUsage->getId()]));
            $query  = (new Query([]))
                ->setPostFilter($filter);

            $elasticaResultSet = $this->searchIndex->search($query);
            $documents         = $this->elasticaMapper->mapResultSet($elasticaResultSet);

            /* @var $document DocumentInterface */
            foreach ($documents as $document) {
                $result[] = $document;
            }
        }

        return $result;
    }


    /**
     * @param  int $eid
     *
     * @return DocumentInterface[]
     */
    protected function fetchDocumentsByEid($eid)
    {
        $filter = (new BoolAnd())
            ->addFilter(new Term(['typeIds' => $eid]));
        $query  = (new Query([]))
            ->setPostFilter($filter);

        $elasticaResultSet = $this->searchIndex->search($query);

        $result = [];

        foreach ($elasticaResultSet as $elasticaResult) {
            //FIXME: THIS IS SO DIRTY :(((
            $hit            = $elasticaResult->getHit();
            $hit['_type']   = MediaDocument::class;
            $elasticaResult = new Result($hit);

            /* @var $document DocumentInterface */
            $document = $this->elasticaMapper->mapResult($elasticaResult);
            $document->setIdentity(new DocumentIdentity($hit['_id']));
            $result[] = $document;
        }

        return $result;
    }

    /**
     * @param MediaDocument[] $documents
     */
    private function storeDocuments(array $documents)
    {
        /** @var ElasticaStorage $storage */
        $storage    = $this->getContainer()->get('phlexible_indexer_media.storage');
        $operations = $storage->createOperations();
        foreach ($documents as $document) {
            $operations->updateDocument($document);
        }
        $storage->execute($operations);
    }
}

