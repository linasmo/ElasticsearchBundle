<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Test;

use ONGR\ElasticsearchBundle\Client\Connection;
use ONGR\ElasticsearchBundle\ORM\Manager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base test which creates unique connection to test with.
 */
abstract class ElasticsearchTestCase extends WebTestCase
{
    /**
     * @var Manager[] Holds used managers.
     */
    private $managers = [];

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * {@inheritdoc}
     */
    public function runTest()
    {
        if ($this->getNumberOfRetries() < 1) {
            return parent::runTest();
        }

        foreach (range(1, $this->getNumberOfRetries()) as $try) {
            try {
                return parent::runTest();
            } catch (\PHPUnit_Framework_SkippedTestError $e) {
                throw $e;
            } catch (\PHPUnit_Framework_IncompleteTestError $e) {
                throw $e;
            } catch (\Exception $e) {
                // Try more.
            }
        }

        throw $e;
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->getContainer();
        $this->getManager();
    }

    /**
     * Returns number of retries tests should execute.
     *
     * @return int
     */
    protected function getNumberOfRetries()
    {
        return 3;
    }

    /**
     * Can be overwritten in child class to populate elasticsearch index with data.
     *
     * Example:
     *      "managername" =>
     *      [
     *          'acmetype' => [
     *              [
     *                  '_id' => 1,
     *                  'title' => 'foo',
     *              ],
     *              [
     *                  '_id' => 2,
     *                  'title' => 'bar',
     *              ]
     *          ]
     *      ]
     *
     * @return array
     */
    protected function getDataArray()
    {
        return [];
    }

    /**
     * Ignores versions specified.
     *
     * Returns two dimensional array, first item in sub array is version to ignore, second is comparator.
     * Comparator types can be found in `version_compare` documentation.
     *
     * Example: [
     *   ['1.2.7', '<='],
     *   ['1.2.9', '==']
     * ]
     *
     * @return array
     */
    protected function getIgnoredVersions()
    {
        return [];
    }

    /**
     * Ignores version specified.
     *
     * @param Connection $connection
     */
    protected function ignoreVersions(Connection $connection)
    {
        $currentVersion = $connection->getVersionNumber();
        foreach ($this->getIgnoredVersions() as $ignoredVersion) {
            if (version_compare($currentVersion, $ignoredVersion[0], $ignoredVersion[1])) {
                $this->markTestSkipped("Elasticsearch version {$currentVersion} not supported by this test.");

                return;
            }
        }
    }

    /**
     * Removes manager from local cache and drops its index.
     *
     * @param string $name
     */
    protected function removeManager($name)
    {
        if (isset($this->managers[$name])) {
            $this->managers[$name]->getConnection()->dropIndex();
            unset($this->managers[$name]);
        }
    }

    /**
     * Populates elasticsearch with data.
     *
     * @param Manager $manager
     * @param array   $data
     */
    protected function populateElasticsearchWithData($manager, array $data)
    {
        if (!empty($data)) {
            foreach ($data as $type => $documents) {
                foreach ($documents as $document) {
                    $manager->getConnection()->bulk('index', $type, $document);
                }
            }
            $manager->commit();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        foreach ($this->managers as $name => $manager) {
            try {
                $manager->getConnection()->dropIndex();
            } catch (\Exception $e) {
                // Do nothing.
            }
        }
    }

    /**
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        if ($this->container) {
            return $this->container;
        }

        $this->container = self::createClient()->getContainer();

        return $this->container;
    }

    /**
     * Returns manager instance with injected connection if does not exist creates new one.
     *
     * @param string $name          Manager name.
     * @param bool   $createIndex   Create index or not.
     * @param array  $customMapping Custom index mapping config.
     *
     * @return Manager
     * @throws \LogicException
     */
    protected function getManager($name = 'default', $createIndex = true, array $customMapping = [])
    {
        $serviceName = sprintf('es.manager.%s', $name);

        // Looks for cached manager.
        if (array_key_exists($name, $this->managers)) {
            $manager = $this->managers[$name];
        } elseif ($this->getContainer()->has($serviceName)) {
            /** @var Manager $manager */
            $manager = $this
                ->getContainer()
                ->get($serviceName);
            $this->managers[$name] = $manager;
        } else {
            throw new \LogicException(sprintf("Manager '%s' does not exist", $name));
        }

        $connection = $manager->getConnection();

        if ($connection instanceof Connection) {
            $this->ignoreVersions($connection);
        }

        // Updates settings.
        if (!empty($customMapping)) {
            $connection->updateSettings(['body' => ['mappings' => $customMapping]]);
        }

        // Drops and creates index.
        $createIndex && $connection->dropAndCreateIndex();

        // Populates elasticsearch index with data.
        $data = $this->getDataArray();
        if ($createIndex && isset($data[$name]) && !empty($data[$name])) {
            $this->populateElasticsearchWithData($manager, $data[$name]);
        }

        return $manager;
    }
}
