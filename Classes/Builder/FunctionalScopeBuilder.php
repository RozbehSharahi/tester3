<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Rozbehsharahi\Tester3\Builder;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Rozbehsharahi\Tester3\Scope\FunctionalScope;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Middleware\VerifyHostHeader;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FunctionalScopeBuilder
{
    public const DEFAULT_INSTANCE_NAME = 'test-app';

    public const DEFAULT_AUTO_CREATE_HOMEPAGE = true;

    public const DEFAULT_AUTO_CREATE_SITE = true;

    public const DEFAULT_CONTEXT = 'Testing';

    public const DEFAULT_SITE_ROOT_PAGE_ID = 1;

    public const DEFAULT_SITE_BASE = '/';

    public const DEFAULT_LOCAL_CONFIGURATION = [
        'SYS' => [
            'encryptionKey' => 'testing',
            'trustedHostsPattern' => VerifyHostHeader::ENV_TRUSTED_HOSTS_PATTERN_ALLOW_ALL,
        ],
        'DB' => [
            'Connections' => [
                'Default' => [
                    'charset' => 'utf8',
                    'driver' => 'pdo_sqlite',
                    'path' => null,
                ],
            ],
        ],
    ];

    protected string $instanceName = self::DEFAULT_INSTANCE_NAME;

    protected string $vendorPath;

    /**
     * @var array<string, mixed>
     */
    protected array $configuration = self::DEFAULT_LOCAL_CONFIGURATION;

    protected bool $autoCreateHomepage = self::DEFAULT_AUTO_CREATE_HOMEPAGE;

    protected bool $autoCreateSite = self::DEFAULT_AUTO_CREATE_SITE;

    protected int $siteRootPageId = self::DEFAULT_SITE_ROOT_PAGE_ID;

    protected string $siteBase = self::DEFAULT_SITE_BASE;

    protected string $context = self::DEFAULT_CONTEXT;

    public function withInstanceName(string $instanceName): self
    {
        $clone = clone $this;
        $clone->instanceName = $instanceName;

        return $clone;
    }

    public function withVendorPath(string $vendorPath): self
    {
        $clone = clone $this;
        $clone->vendorPath = $vendorPath;

        return $clone;
    }

    public function withSiteRootPageId(int $siteRootPageId): self
    {
        $clone = clone $this;
        $clone->siteRootPageId = $siteRootPageId;

        return $clone;
    }

    public function withSiteBase(string $siteBase): self
    {
        $clone = clone $this;
        $clone->siteBase = $siteBase;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function withAdditionalConfiguration(array $configuration): self
    {
        $clone = clone $this;
        $clone->configuration = array_replace_recursive($this->configuration, $configuration);

        return $clone;
    }

    public function withAutoCreateHomepage(bool $autoCreateHomepage): self
    {
        $clone = clone $this;
        $clone->autoCreateHomepage = $autoCreateHomepage;

        return $clone;
    }

    public function withAutoCreateSite(bool $autoCreateSite): self
    {
        $clone = clone $this;
        $clone->autoCreateSite = $autoCreateSite;

        return $clone;
    }

    public function withContext(string $context): self
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    public function getInstancePath(string $subPath = '/'): string
    {
        $root = dirname(__DIR__, 2);

        return $root.'/var/tests/functional-'.$this->instanceName.$subPath;
    }

    public function getDatabasePath(): string
    {
        return $this->getInstancePath('/database.sqlite');
    }

    public function build(): FunctionalScope
    {
        if (empty($this->vendorPath)) {
            throw new \RuntimeException('You must set the path to your vendor: ->withVendorPath(...)');
        }

        $classLoader = require $this->vendorPath.'/autoload.php';

        // Clean ups
        GeneralUtility::purgeInstances();
        $this->getConnectionPool()->resetConnections();

        @unlink($this->getDatabasePath());
        @unlink($this->getInstancePath('/config/sites/'.$this->instanceName.'/config.yaml'));
        $this->createDirectory($this->getInstancePath());
        $this->createDirectory($this->getInstancePath('/public'));
        $this->createDirectory($this->getInstancePath('/public/typo3conf'));

        $configuration = $this->configuration;
        $configuration['DB']['Connections']['Default']['path'] = $this->getDatabasePath();

        file_put_contents(
            $this->getInstancePath('/public/typo3conf/LocalConfiguration.php'),
            $this->getPhpFile($configuration)
        );

        SystemEnvironmentBuilder::run();

        Environment::initialize(
            new ApplicationContext($this->context),
            false,
            true,
            $this->getInstancePath(),
            $this->getInstancePath('/public'),
            $this->getInstancePath('/var'),
            $this->getInstancePath('/config'),
            $this->getInstancePath('/index.php'),
            'UNIX'
        );

        $container = Bootstrap::init($classLoader, false);
        ob_end_clean();

        $this->createDatabaseStructure();

        if ($this->autoCreateHomepage) {
            $this->createHomepage();
        }

        if ($this->autoCreateSite) {
            $this->createSite();
        }

        $this->clearSiteFinderCache();

        if (!$container instanceof ContainerInterface) {
            throw new \RuntimeException('Expected to have symfony container interface, but didnt');
        }

        return new FunctionalScope($this->instanceName, $this->getInstancePath(), $container);
    }

    protected function createHomepage(): self
    {
        $query = $this->getQueryBuilder('pages');

        $query
            ->insert('pages')
            ->values(['uid' => 1, 'pid' => 0, 'title' => 'root page', 'slug' => '/'])
            ->executeStatement()
        ;

        $query = $this->getQueryBuilder('sys_template');
        $query
            ->insert('sys_template')
            ->values([
                'pid' => 1,
                'root' => 1,
                'config' => '
                    page = PAGE
                    page.10 = TEXT
                    page.10.value = Hello World
                ',
            ])
            ->executeStatement()
        ;

        return $this;
    }

    protected function createSite(): self
    {
        $configuration = [
            'rootPageId' => $this->siteRootPageId,
            'base' => $this->siteBase,
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'default',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'navigationTitle' => '',
                    'hreflang' => '',
                    'direction' => '',
                    'flag' => 'us',
                ],
                [
                    'title' => 'Austrian',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de',
                    'typo3Language' => 'de',
                    'locale' => 'de_AT.UTF-8',
                    'iso-639-1' => 'de',
                    'navigationTitle' => '',
                    'hreflang' => '',
                    'direction' => '',
                    'flag' => 'de',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ];

        $this->createDirectory($this->getInstancePath('/config'));
        $this->createDirectory($this->getInstancePath('/config/sites'));
        $this->createDirectory($this->getInstancePath('/config/sites/'.$this->instanceName));
        file_put_contents(
            $this->getInstancePath('/config/sites/'.$this->instanceName.'/config.yaml'),
            Yaml::dump($configuration, 99, 2)
        );

        return $this;
    }

    protected function createDatabaseStructure(): self
    {
        $this->getConnection()->close();

        $schemaManager = $this->getSchemaManager();

        foreach ($schemaManager->listTableNames() as $tableName) {
            $this->getConnection()->truncate($tableName);
        }

        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $sqlCode = $sqlReader->getTablesDefinitionString(true);

        $createTableStatements = $sqlReader->getCreateTableStatementArray($sqlCode);

        $schemaMigrationService->install($createTableStatements);

        $insertStatements = $sqlReader->getInsertStatementArray($sqlCode);
        $schemaMigrationService->importStaticData($insertStatements);

        return $this;
    }

    protected function createDirectory(string $path): self
    {
        if (is_dir($path)) {
            return $this;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $path));
        }

        return $this;
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    protected function getConnection(): Connection
    {
        return $this->getConnectionPool()->getConnectionByName('Default');
    }

    protected function getQueryBuilder(string $table): QueryBuilder
    {
        return $this->getConnectionPool()->getQueryBuilderForTable($table);
    }

    protected function getSchemaManager(): AbstractSchemaManager
    {
        return $this->getConnection()->createSchemaManager();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    protected function getPhpFile(array $configuration): string
    {
        return '<?php return '.var_export($configuration, true).';';
    }

    /** @noinspection PhpExpressionResultUnusedInspection */
    protected function clearSiteFinderCache(): self
    {
        /** @var SiteFinder $siteFinder */
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        // Typo3 BUG: does not empty mappingRootPageIdToIdentifier on useCache = false
        $reflection = new \ReflectionClass($siteFinder);
        $property = $reflection->getProperty('mappingRootPageIdToIdentifier');
        $property->setAccessible(true);
        $property->setValue($siteFinder, []);

        $siteFinder->getAllSites(false);

        /** @var CacheManager $cacheManager */
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->getCache('rootline')->flush();

        return $this;
    }
}
