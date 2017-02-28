<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\EventListener;

use Phlexible\Bundle\IndexerBundle\Event\DocumentEvent;
use Phlexible\Bundle\IndexerBundle\IndexerEvents;
use Phlexible\Bundle\IndexerPageBundle\Document\PageDocument;
use Phlexible\Bundle\QueueBundle\Entity\Job;
use Phlexible\Bundle\QueueBundle\Model\JobManagerInterface;
use Phlexible\Bundle\TreeBundle\Event\NodeEvent;
use Phlexible\Bundle\TreeBundle\Event\SetNodeOfflineEvent;
use Phlexible\Bundle\TreeBundle\TreeEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Node listener
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class NodeListener implements EventSubscriberInterface
{
    /**
     * @var JobManagerInterface
     */
    private $jobManager;

    /**
     * @param JobManagerInterface $jobManager
     */
    public function __construct(JobManagerInterface $jobManager)
    {
        $this->jobManager = $jobManager;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            TreeEvents::SET_NODE_OFFLINE => 'onSetNodeOffline',
            TreeEvents::DELETE_NODE => 'onDeleteNode',
            IndexerEvents::STORAGE_ADD_DOCUMENT => 'onUpdateDocument',
            IndexerEvents::STORAGE_UPDATE_DOCUMENT => 'onUpdateDocument',
        );
    }
    /**
     * @param SetNodeOfflineEvent $event
     */
    public function onSetNodeOffline(SetNodeOfflineEvent $event)
    {
        $node = $event->getNode();

        $this->queueUpdateElementData($node->getTypeId());
    }

    /**
     * @param DocumentEvent $event
     */
    public function onUpdateDocument(DocumentEvent $event)
    {
        $document = $event->getDocument();

        if (!$document instanceof PageDocument) {
            return;
        }

        $this->queueUpdateElementData($document->get('typeId'));
    }

    /**
     * @param NodeEvent $event
     */
    public function onDeleteNode(NodeEvent $event)
    {
        $node = $event->getNode();

        $this->queueUpdateElementData($node->getTypeId());
    }

    /**
     * Schedule document update.
     *
     * @param int $eid
     */
    private function queueUpdateElementData($eid)
    {
        $job = new Job('indexer-page-media:update-element-data', array("--eid $eid"));

        echo 'update queue item'.PHP_EOL;
        //$this->jobManager->updateQueueItem($job);
    }
}
