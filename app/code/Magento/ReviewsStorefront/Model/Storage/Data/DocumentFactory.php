<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\ReviewsStorefront\Model\Storage\Data;

use Magento\Framework\ObjectManagerInterface;

/**
 * Document factory class.
 */
class DocumentFactory
{
    /**
     * Object Manager instance
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        ObjectManagerInterface $objectManager
    ) {
        $this->objectManager = $objectManager;
    }

    /**
     * Create class instance with specified parameters
     *
     * @param array $data
     *
     * @return Document
     */
    public function create(array $data = []): Document
    {
        return $this->objectManager->create(Document::class, ['data' => $data]);
    }
}
