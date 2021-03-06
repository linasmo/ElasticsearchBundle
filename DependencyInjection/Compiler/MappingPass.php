<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\DependencyInjection\Compiler;

use ONGR\ElasticsearchBundle\Document\Warmer\WarmerInterface;
use ONGR\ElasticsearchBundle\DSL\Search;
use ONGR\ElasticsearchBundle\Mapping\MetadataCollector;
use Psr\Log\LogLevel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiles elastic search data.
 */
class MappingPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $connections = $container->getParameter('es.connections');
        $managers = $container->getParameter('es.managers');

        foreach ($managers as $managerName => $settings) {
            $bundlesMetadata = $this->getBundlesMetadata($container, $settings);

            $classMetadataCollection = new Definition(
                'ONGR\ElasticsearchBundle\Mapping\ClassMetadataCollection',
                [
                    $bundlesMetadata,
                ]
            );

            $managerDefinition = new Definition(
                'ONGR\ElasticsearchBundle\ORM\Manager',
                [
                    $this->getConnectionDefinition($container, $connections, $settings),
                    $classMetadataCollection,
                ]
            );

            $managerName = strtolower($managerName);

            $container->setDefinition(
                sprintf('es.manager.%s', $managerName),
                $managerDefinition
            );

            if ($managerName === 'default') {
                $container->setAlias('es.manager', 'es.manager.default');
            }

            foreach ($bundlesMetadata as $repo => $data) {
                $repository = new Definition(
                    'ONGR\ElasticsearchBundle\ORM\Repository',
                    [
                        $managerDefinition,
                        [$repo],
                    ]
                );
                $container->setDefinition(
                    sprintf('es.manager.%s.%s', $managerName, strtolower($data['class'])),
                    $repository
                );
            }
        }
    }

    /**
     * Fetches bundles metadata for specific manager settings.
     *
     * @param ContainerBuilder $container
     * @param array            $settings
     *
     * @return array
     */
    private function getBundlesMetadata(ContainerBuilder $container, $settings)
    {
        $out = [];

        /** @var MetadataCollector $collector */
        $collector = $container->get('es.metadata_collector');
        foreach ($settings['mappings'] as $bundle) {
            foreach ($collector->getBundleMapping($bundle) as $typeName => $typeParams) {
                $typeParams['type'] = $typeName;
                $out[$bundle . ':' . $typeParams['class']] = $typeParams;
            }
        }

        return $out;
    }

    /**
     * Builds connection definition.
     *
     * @param ContainerBuilder $container
     * @param array            $connections
     * @param array            $settings
     *
     * @return Definition
     *
     * @throws InvalidConfigurationException
     */
    private function getConnectionDefinition(ContainerBuilder $container, $connections, $settings)
    {
        if (!isset($connections[$settings['connection']])) {
            throw new InvalidConfigurationException(
                'There is no ES connection with name ' . $settings['connection']
            );
        }

        $client = new Definition(
            'Elasticsearch\Client',
            [
                $this->getClientParams($connections[$settings['connection']], $settings, $container),
            ]
        );
        $connection = new Definition(
            'ONGR\ElasticsearchBundle\Client\Connection',
            [
                $client,
                $this->getIndexParams($connections[$settings['connection']], $settings, $container),
            ]
        );

        $this->setWarmers($connection, $settings['connection'], $container);

        return $connection;
    }

    /**
     * Returns params for client.
     *
     * @param array            $connection
     * @param array            $manager
     * @param ContainerBuilder $container
     *
     * @return array
     */
    private function getClientParams(array $connection, array $manager, ContainerBuilder $container)
    {
        $params = ['hosts' => $connection['hosts']];

        if (!empty($connection['auth'])) {
            $params['connectionParams']['auth'] = array_values($connection['auth']);
        }

        if ($manager['debug']) {
            $params['logging'] = true;
            $params['logPath'] = $container->getParameter('es.logging.path');
            $params['logLevel'] = LogLevel::WARNING;
            $params['traceObject'] = new Reference('es.logger.trace');
        }

        return $params;
    }

    /**
     * Returns params for index.
     *
     * @param array            $connection
     * @param array            $manager
     * @param ContainerBuilder $container
     *
     * @return array
     */
    private function getIndexParams(array $connection, array $manager, ContainerBuilder $container)
    {
        $index = ['index' => $connection['index_name']];

        if (!empty($connection['settings'])) {
            $index['body']['settings'] = $connection['settings'];
        }

        $mappings = [];
        /** @var MetadataCollector $metadataCollector */
        $metadataCollector = $container->get('es.metadata_collector');

        if (!empty($manager['mappings'])) {
            foreach ($manager['mappings'] as $bundle) {
                $mappings = array_replace_recursive(
                    $mappings,
                    $metadataCollector->getMapping($bundle)
                );
            }
        } else {
            foreach ($container->getParameter('kernel.bundles') as $bundle => $path) {
                $mappings = array_replace_recursive(
                    $mappings,
                    $metadataCollector->getMapping($bundle)
                );
            }
        }

        $paths = $metadataCollector->getProxyPaths();
        if ($container->hasParameter('es.proxy_paths')) {
            $paths = array_merge($paths, $container->getParameter('es.proxy_paths'));
        }
        $container->setParameter('es.proxy_paths', $paths);

        if (!empty($mappings)) {
            $index['body']['mappings'] = $mappings;
        }

        return $index;
    }

    /**
     * Returns warmers for client.
     *
     * @param Definition       $connectionDefinition
     * @param string           $connection
     * @param ContainerBuilder $container
     *
     * @return array
     * @throws \LogicException If connection is not found.
     */
    private function setWarmers($connectionDefinition, $connection, ContainerBuilder $container)
    {
        $warmers = [];
        foreach ($container->findTaggedServiceIds('es.warmer') as $id => $tags) {
            if (array_key_exists('connection', $tags[0])) {
                $connections = [];
                if (strpos($tags[0]['connection'], ',')) {
                    $connections = explode(',', $tags[0]['connection']);
                }

                if (in_array($connection, $connections) || $tags[0]['connection'] === $connection) {
                    $connectionDefinition->addMethodCall('addWarmer', [new Reference($id)]);
                }
            }
        }

        return $warmers;
    }
}
