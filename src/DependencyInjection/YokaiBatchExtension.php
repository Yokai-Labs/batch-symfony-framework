<?php

declare(strict_types=1);

namespace Yokai\Batch\Bridge\Symfony\Framework\DependencyInjection;

use Composer\InstalledVersions;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader as ConfigLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Loader as DependencyInjectionLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Yokai\Batch\Bridge\Doctrine\DBAL\DoctrineDBALJobExecutionStorage;
use Yokai\Batch\Launcher\JobLauncherInterface;
use Yokai\Batch\Storage\FilesystemJobExecutionStorage;
use Yokai\Batch\Storage\JobExecutionStorageInterface;
use Yokai\Batch\Storage\ListableJobExecutionStorageInterface;
use Yokai\Batch\Storage\QueryableJobExecutionStorageInterface;

final class YokaiBatchExtension extends Extension
{
    /**
     * @inheritDoc
     * @phpstan-param list<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = $this->getLoader($container);
        $loader->load('global/');

        $bridges = [
            'doctrine/orm/' => $this->installed('doctrine-orm'),
            'symfony/console/' => $this->installed('symfony-console'),
            'symfony/messenger/' => $this->installed('symfony-messenger'),
            'symfony/serializer/' => $this->installed('symfony-serializer'),
            'symfony/validator/' => $this->installed('symfony-validator'),
        ];

        foreach (array_keys(array_filter($bridges)) as $resource) {
            $loader->load($resource);
        }

        $this->configureStorage($container, $config['storage']);

        $launchers = [
            'yokai_batch.job_launcher.dispatch_message' => $this->installed('symfony-messenger'),
            'yokai_batch.job_launcher.run_command' => $this->installed('symfony-console'),
        ];
        $container->setAlias(
            JobLauncherInterface::class,
            \array_keys(\array_filter($launchers))[0] ?? 'yokai_batch.job_launcher.simple'
        );
    }

    private function installed(string $package): bool
    {
        return InstalledVersions::isInstalled('yokai/batch-src')
            || InstalledVersions::isInstalled('yokai/batch-' . $package);
    }

    private function getLoader(ContainerBuilder $container): ConfigLoader\LoaderInterface
    {
        $locator = new FileLocator(__DIR__ . '/../Resources/services');
        $resolver = new ConfigLoader\LoaderResolver(
            [
                new DependencyInjectionLoader\XmlFileLoader($container, $locator),
                new DependencyInjectionLoader\DirectoryLoader($container, $locator),
            ]
        );

        return new ConfigLoader\DelegatingLoader($resolver);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureStorage(ContainerBuilder $container, array $config): void
    {
        if (isset($config['service'])) {
            $defaultStorage = $config['service'];
        } elseif (isset($config['dbal'])) {
            $container
                ->register('yokai_batch.storage.dbal', DoctrineDBALJobExecutionStorage::class)
                ->setArguments(
                    [
                        new Reference('doctrine'),
                        [
                            'connection' => $config['dbal']['connection'],
                            'table' => $config['dbal']['table'],
                        ],
                    ]
                )
            ;

            $defaultStorage = 'yokai_batch.storage.dbal';
        } else {
            $container
                ->register('yokai_batch.storage.filesystem', FilesystemJobExecutionStorage::class)
                ->setArguments([new Reference($config['filesystem']['serializer']), $config['filesystem']['dir']])
            ;

            $defaultStorage = 'yokai_batch.storage.filesystem';
        }

        try {
            $defaultStorageDefinition = $container->getDefinition($defaultStorage);
        } catch (ServiceNotFoundException $exception) {
            throw new LogicException(
                sprintf('Configured default job execution storage service "%s" does not exists.', $defaultStorage),
                0,
                $exception
            );
        }

        $defaultStorageClass = $defaultStorageDefinition->getClass();
        if ($defaultStorageClass === null) {
            throw new LogicException(
                \sprintf('Job execution storage service "%s", has no class.', $defaultStorage)
            );
        }

        $interfaces = [
            JobExecutionStorageInterface::class => true,
            ListableJobExecutionStorageInterface::class => false,
            QueryableJobExecutionStorageInterface::class => false,
        ];
        foreach ($interfaces as $interface => $required) {
            if (!is_a($defaultStorageClass, $interface, true)) {
                if ($required) {
                    throw new LogicException(
                        \sprintf(
                            'Job execution storage service "%s", is of class "%s", and must implements interface "%s".',
                            $defaultStorage,
                            $defaultStorageClass,
                            $interface
                        )
                    );
                }
                continue;
            }
            $container
                ->setAlias($interface, $defaultStorage)
                ->setPublic(true)
            ;
        }
    }
}
