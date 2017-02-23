<?php

/*
 * This file is part of the phlexible indexer page media package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Element media indexer bundle
 *
 * @author Phillip Look <pl@brainbits.net>
 */
class PhlexibleIndexerPageMediaBundle extends Bundle
{
    public function getSiterootProperties()
    {
        return array(
            'indexer.elements.media.folder.recursiv',
            'indexer.elements.media.audio',
            'indexer.elements.media.document',
            'indexer.elements.media.flash',
            'indexer.elements.media.image',
            'indexer.elements.media.video',
            'indexer.elements.media.archive',
            'indexer.elements.media.field.download',
            'indexer.elements.media.field.flash',
            'indexer.elements.media.field.folder',
            'indexer.elements.media.field.image',
            'indexer.elements.media.field.video',
        );
    }
}
