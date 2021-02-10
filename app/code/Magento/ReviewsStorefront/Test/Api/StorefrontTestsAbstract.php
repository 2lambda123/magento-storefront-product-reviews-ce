<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\ReviewsStorefront\Test\Api;

use Magento\Framework\Amqp\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Indexer\Model\Indexer;
use Magento\StorefrontStorage\Model\Client\DataDefinitionInterface;
use Magento\StorefrontStorage\Model\State;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Helper\CompareArraysRecursively;
use Magento\TestFramework\Workaround\ConsumerInvoker;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;

/**
 * Test abstract class for store front tests
 * Storefront API tests should be run as WebAPI test due to Message Broker do a REST call to the Export API to receive
 * reviews and ratings data.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class StorefrontTestsAbstract extends TestCase
{
    /**
     * List of storefront feed names
     *
     * @var array
     */
    private const FEEDS = [
        'catalog_data_exporter_categories',
        'catalog_data_exporter_products',
        'catalog_data_exporter_product_reviews',
        'catalog_data_exporter_rating_metadata',
    ];

    /**
     * List of queues used for storefront
     *
     * @var array
     */
    private const QUEUES = [
        'catalog.category.export.queue',
        'catalog.product.export.queue',
        'export.product.reviews.queue',
        'export.rating.metadata.queue',
    ];

    /**
     * @var CompareArraysRecursively
     */
    private $compareArraysRecursively;

    /**
     * @var DataDefinitionInterface
     */
    private $dataDefinition;

    /**
     * @var State
     */
    private $storageState;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->compareArraysRecursively = Bootstrap::getObjectManager()->create(CompareArraysRecursively::class);
        $this->dataDefinition = Bootstrap::getObjectManager()->create(DataDefinitionInterface::class);
        $this->storageState = Bootstrap::getObjectManager()->create(State::class);
    }

    /**
     * Review & rating storage and feeds are need to be cleared after test execution to prevent "dependency" tests fail
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->cleanStorage();
        $this->cleanFeeds();
        $this->cleanOldMessages();
    }

    /**
     * @inheritdoc
     */
    public function run(TestResult $result = null): TestResult
    {
        $this->cleanOldMessages();
        $this->resetIndexerToOnSave();

        return parent::run($result);
    }

    /**
     * Remove review & rating storage to prevent data duplication in tests
     */
    private function cleanStorage(): void
    {
        /** @var StoreManagerInterface $storeManager */
        $storeManager = Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
        $availableStores = $storeManager->getStores();

        $this->deleteDataSource('review', null);

        foreach (['rating_metadata', 'category', 'product'] as $entityType) {
            foreach ($availableStores as $store) {
                $this->deleteDataSource($entityType, $store->getCode());
            }
        }
    }

    /**
     * Delete data source
     *
     * @param string $entityType
     * @param string|null $storeCode
     *
     * @return void
     */
    private function deleteDataSource(string $entityType, ?string $storeCode): void
    {
        try {
            $sourceName = $this->storageState->getCurrentDataSourceName([$storeCode, $entityType]);
            $this->dataDefinition->deleteDataSource($sourceName);
        } catch (\Exception $e) {
            // Do nothing if no source
        }
    }

    /**
     * On each tear down we need to clean all feed data
     *
     * @return void
     */
    private function cleanFeeds(): void
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();

        foreach (self::FEEDS as $feed) {
            $feedTable = $resourceConnection->getTableName($feed);
            $connection->delete($feedTable);
        }
    }

    /**
     * Clean old messages from rabbitmq
     *
     * @return void
     *
     * @throws \LogicException
     * @throws AMQPTimeoutException
     */
    private function cleanOldMessages(): void
    {
        if ($this->isSoap()) {
            return;
        }

        /** @var Config $amqpConfig */
        $amqpConfig = Bootstrap::getObjectManager()->get(Config::class);

        foreach (self::QUEUES as $queue) {
            $amqpConfig->getChannel()->queue_purge($queue);
        }
    }

    /**
     * Runs consumers before test execution
     *
     * @throws \Throwable
     */
    protected function runTest()
    {
        if (!$this->isSoap()) {
            /* @var ConsumerInvoker $consumerInvoker */
            $consumerInvoker = Bootstrap::getObjectManager()->create(ConsumerInvoker::class);
            $consumerInvoker->invoke();

            $this->dataDefinition->refreshDataSource($this->storageState->getCurrentDataSourceName(['review']));

            parent::runTest();
        }
    }

    /**
     * Check if it is SOAP request
     *
     * @return bool
     */
    private function isSoap(): bool
    {
        return TESTS_WEB_API_ADAPTER === 'soap';
    }

    /**
     * Resetting indexer to 'on save' mode
     *
     * @return void
     */
    private function resetIndexerToOnSave(): void
    {
        $indexer =  \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(Indexer::class);

        foreach (self::FEEDS as $indexerName) {
            $indexer->load($indexerName);
            $indexer->setScheduled(false);
        }
    }

    /**
     * Compare expected and actual results
     *
     * @param array $expected
     * @param array $actual
     * @param string|null $message
     *
     * @throws AssertionFailedError
     */
    protected function compare(array $expected, array $actual, string $message = null): void
    {
        $diff = $this->compareArraysRecursively->execute($expected, $actual);

        if (!empty($diff)) {
            $message = $message ?? "Actual response doesn't equal expected data";
            $message .= "\n Diff:\n" . var_export($diff, true);
            $message .= "\n Actual:\n" . var_export($actual, true);
            self::fail($message);
        }
    }
}
