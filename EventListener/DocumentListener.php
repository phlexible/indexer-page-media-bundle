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

use Phlexible\Bundle\IndexerBundle\Document\DocumentInterface;
use Phlexible\Bundle\IndexerBundle\IndexerEvents;
use Phlexible\Bundle\IndexerPageMediaBundle\Event\MapDocumentEvent;
use Phlexible\Bundle\IndexerBundle\Event\DocumentEvent;
use Phlexible\Bundle\IndexerPageMediaBundle\Indexer\DocumentMapper;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Document listener
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class DocumentListener implements EventSubscriberInterface
{
    /**
     * @var DocumentMapper
     */
    private $documentMapper;

    /**
     * @param DocumentMapper $documentMapper
     */
    public function __construct(DocumentMapper $documentMapper)
    {
        $this->documentMapper = $documentMapper;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            IndexerEvents::CREATE_DOCUMENT => 'onCreateDocument',
            //IndexerEvents::MAP_DOCUMENT    => 'onMapDocument',
        );
    }

    /**
     * @param DocumentEvent $event
     */
    public function onCreateDocument(DocumentEvent $event)
    {
        $document = $event->getDocument();

        if (!$document instanceof MediaDocument) {
            return;
        }

        $document
            ->setField('eids', array('type' => DocumentInterface::TYPE_INTEGER, 'array' => true))
            ->setField('tids', array('type' => DocumentInterface::TYPE_INTEGER, 'array' => true))
            ->setField('siteroots', array('type' => DocumentInterface::TYPE_STRING, 'array' => true))
            ->setField('languages', array('type' => DocumentInterface::TYPE_STRING, 'array' => true))
            ->setField('restricted', array('type' => DocumentInterface::TYPE_BOOLEAN));
    }

    /**
     * @param MapDocumentEvent $event
     */
    public function onMapDocument(MapDocumentEvent $event)
    {
        $mediaDocument = $event->getMediaDocument();

        $this->documentMapper->applyPageDataToMediaDocument($mediaDocument);
    }
}
