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

use Doctrine\ORM\EntityManagerInterface;
use Elastica\Filter\BoolAnd;
use Elastica\Filter\Term;
use Elastica\Index;
use Elastica\Query;
use Phlexible\Bundle\IndexerBundle\Document\DocumentIdentity;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Phlexible\Bundle\IndexerMediaBundle\Indexer\MediaIndexer;
use Phlexible\Bundle\IndexerStorageElasticaBundle\Storage\ElasticaMapper;
use Phlexible\Bundle\MediaManagerBundle\Entity\FileUsage;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update page data job.
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
        $this->elasticaMapper = $this->getContainer()->get('phlexible_indexer_storage_elastica.elastica_mapper');
        $this->searchIndex    = $this->getContainer()->get('phlexible_elastica.index');

        $eid = $input->getArgument('eid');

        $affectedDocumentIdentities = array_merge(
            $this->fetchDocumentsFromDb($eid),
            $this->fetchDocumentsFromIndex($eid)
        );

        $output->writeln('Number of affected documents (all languages): '.count($affectedDocumentIdentities));

        /* @var MediaIndexer $mediaIndexer */
        $mediaIndexer = $this->getContainer()->get('phlexible_indexer_media.media_indexer');

        /* @var $document MediaDocument */
        foreach ($affectedDocumentIdentities as $documentIdentity) {
            $mediaIndexer->update($documentIdentity);
        }

        return 0;
    }

    /**
     * @param int $eid
     *
     * @return DocumentIdentity[]
     */
    private function fetchDocumentsFromDb($eid)
    {
        /* @var EntityManagerInterface $entityManager */
        $entityManager       = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $fileUsageRepository = $entityManager->getRepository(FileUsage::class);

        $usage = $fileUsageRepository->findBy(['usageType' => 'element', 'usageId' => $eid]);

        $result = [];

        /** @var FileUsage $fileUsage */
        foreach ($usage as $fileUsage) {
            if (!($fileUsage->getStatus() & FileUsage::STATUS_ONLINE)) {
                continue;
            }

            $_id          = 'media_'.$fileUsage->getFile()->getId().'_'.$fileUsage->getFile()->getVersion();
            $result[$_id] = new DocumentIdentity($_id);
        }

        return $result;
    }

    /**
     * @param int $eid
     *
     * @return DocumentIdentity[]
     */
    protected function fetchDocumentsFromIndex($eid)
    {
        $filter = (new BoolAnd())
            ->addFilter(new Term(['typeIds' => $eid]));
        $query  = (new Query([]))
            ->setPostFilter($filter);

        $elasticaResultSet = $this->searchIndex->search($query);

        $result = [];

        foreach ($elasticaResultSet as $elasticaResult) {
            $hit                 = $elasticaResult->getHit();
            $result[$hit['_id']] = new DocumentIdentity($hit['_id']);
        }

        return $result;
    }
}
