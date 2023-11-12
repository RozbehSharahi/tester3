<?php

declare(strict_types=1);

namespace Rozbehsharahi\Tester3\Scope;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rozbehsharahi\Tester3\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Frontend\Http\Application;

class FunctionalScope
{
    public function __construct(
        protected readonly string $instanceName,
        protected readonly string $instancePath,
        protected readonly ContainerInterface $container
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class)
    {
        return $this->container->get($class);
    }

    public function set(string $serviceName, mixed $service): self
    {
        $this->container->set($serviceName, $service);

        return $this;
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function getInstancePath(string $path = ''): string
    {
        return $this->instancePath.$path;
    }

    public function getDatabasePath(): string
    {
        return $this->instancePath.'/database.sqlite';
    }

    public function getApplication(): Application
    {
        return $this->get(Application::class);
    }

    public function getConnectionPool(): ConnectionPool
    {
        return $this->get(ConnectionPool::class);
    }

    public function doServerRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->getApplication()->handle($request);
    }

    public function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->doServerRequest(new ServerRequest($path, $method));
    }

    /**
     * @param array<string, int|string|boolean> $data
     */
    public function createRecord(string $table, array $data): self
    {
        $query = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $query
            ->insert($table)
            ->values($data)
            ->executeStatement()
        ;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecord(string $table, int $uid): array
    {
        $record = $this->getRecords($table, ['uid' => $uid])[0] ?? null;

        if (!$record) {
            throw new \RuntimeException("Could not find record in table: {$table} with id {$uid}");
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $by
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecords(string $table, array $by = []): array
    {
        try {
            $byString = json_encode($by, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not json encode by statement on call of '.__METHOD__, 0, $e);
        }

        $query = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $query->from($table)->select('*');

        foreach ($by as $property => $value) {
            $query->where($query->expr()->eq($property, $value));
        }

        try {
            return $query->executeQuery()->fetchAllAssociative();
        } catch (\Throwable) {
            throw new \RuntimeException("Could not fetch from {$table}: by {$byString} in test scope.");
        }
    }

    public function createTable(Table $table): self
    {
        try {
            $this->getSchemaManager()->createTable($table);
        } catch (Exception $e) {
            throw new RuntimeException('Could not create table in tests: '.$e->getMessage());
        }

        return $this;
    }

    public function updateTable(TableDiff $tableDiff): self
    {
        try {
            $this->getSchemaManager()->alterTable($tableDiff);
        } catch (Exception $e) {
            throw new RuntimeException('Could not alter table in tests: '.$e->getMessage());
        }

        return $this;
    }

    protected function getSchemaManager(): AbstractSchemaManager
    {
        try {
            return $this
                ->getConnectionPool()
                ->getConnectionByName('Default')
                ->createSchemaManager()
            ;
        } catch (Exception) {
            throw new RuntimeException('Could not create schema-manager in tests');
        }
    }
}
