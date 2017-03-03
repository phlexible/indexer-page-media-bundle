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
use Phlexible\Bundle\IndexerMediaBundle\Event\MapDocumentEvent;
use Phlexible\Bundle\IndexerMediaBundle\IndexerMediaEvents;
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
            IndexerMediaEvents::MAP_DOCUMENT => 'onMapDocument',
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
            ->setField('typeIds', array('type' => DocumentInterface::TYPE_INTEGER, 'array' => true))
            ->setField('nodeIds', array('type' => DocumentInterface::TYPE_INTEGER, 'array' => true))
            ->setField('siterootIds', array('type' => DocumentInterface::TYPE_STRING, 'array' => true))
            ->setField('languages', array('type' => DocumentInterface::TYPE_STRING, 'array' => true));
    }

    /**
     * @param MapDocumentEvent $event
     */
    public function onMapDocument(MapDocumentEvent $event)
    {
        $mediaDocument = $event->getDocument();
        $descriptor = $event->getDescriptor();

        $this->documentMapper->applyPageDataToMediaDocument($mediaDocument, $descriptor);
    }
}
