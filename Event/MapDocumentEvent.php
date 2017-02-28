<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\Event;

use Elastica\Result;
use Phlexible\Bundle\IndexerMediaBundle\Document\MediaDocument;
use Symfony\Component\EventDispatcher\Event;

/**
 * Map document event
 *
 * @author Stephan Wentz <sw@brainbits.net>
 */
class MapDocumentEvent extends Event
{
    /**
     * @var MediaDocument
     */
    private $mediaDocument;

    /**
     * @var Result
     */
    private $pageDocument;

    /**
     * @param MediaDocument $mediaDocument
     * @param Result        $pageDocument
     */
    public function __construct(MediaDocument $mediaDocument, Result $pageDocument)
    {
        $this->mediaDocument   = $mediaDocument;
        $this->pageDocument = $pageDocument;
    }

    /**
     * @return MediaDocument
     */
    public function getMediaDocument()
    {
        return $this->mediaDocument;
    }

    /**
     * @return Result
     */
    public function getPageDocument()
    {
        return $this->pageDocument;
    }

}
