<?php

/*
 * This file is part of the phlexible tinymce package.
 *
 * (c) Stephan Wentz <sw@brainbits.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Phlexible\Bundle\IndexerPageMediaBundle\Tests;

use Phlexible\Bundle\IndexerPageMediaBundle\PhlexibleIndexerPageMediaBundle;
use PHPUnit\Framework\TestCase;

/**
 * Indexer page media bundle test.
 *
 * @author Stephan Wentz <sw@brainbits.net>
 *
 * @covers \Phlexible\Bundle\IndexerPageMediaBundle\PhlexibleIndexerPageMediaBundle
 */
class PhlexibleIndexerPageMediaBundleTest extends TestCase
{
    public function testBundle()
    {
        $bundle = new PhlexibleIndexerPageMediaBundle();

        $this->assertSame('PhlexibleIndexerPageMediaBundle', $bundle->getName());
    }
}
