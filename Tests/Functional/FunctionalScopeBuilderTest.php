<?php

declare(strict_types=1);

namespace Rozbehsharahi\Tester3\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Rozbehsharahi\Tester3\Builder\FunctionalScopeBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

class FunctionalScopeBuilderTest extends TestCase
{
    private FunctionalScopeBuilder $scopeBuilder;

    public function setUp(): void
    {
        $this->scopeBuilder = (new FunctionalScopeBuilder())->withVendorPath(__DIR__.'/../../vendor');
    }

    public function testCanCreateScopeAndAccessibleHomepage(): void
    {
        $scope = $this->scopeBuilder->build();

        self::assertSame(FunctionalScopeBuilder::DEFAULT_INSTANCE_NAME, $scope->getInstanceName());
        self::assertDirectoryExists($scope->getInstancePath());
        self::assertFileExists($scope->getDatabasePath());
        self::assertFileExists($scope->getInstancePath().'/config/sites/test-app/config.yaml');
        self::assertCount(1, $scope->getRecords('pages'));
        self::assertSame('root page', $scope->getRecord('pages', 1)['title']);

        $response = $scope->doServerRequest(new ServerRequest('/'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCanCreateIndependentScopes(): void
    {
        $scope = $this->scopeBuilder->build();
        $scope->createRecord('pages', ['title' => 'Second test page']);
        $pages = $scope->getRecords('pages');
        self::assertCount(2, $pages);

        $newScope = $this->scopeBuilder->build();
        $pages = $newScope->getRecords('pages');
        self::assertCount(1, $pages);
    }

    public function testCanCreateScopeWithoutHomepage(): void
    {
        $scope = $this->scopeBuilder->withAutoCreateHomepage(false)->build();
        self::assertCount(0, $scope->getRecords('pages'));
    }

    public function testCanCreateScopeWithoutSite(): void
    {
        $scope = $this->scopeBuilder->withInstanceName('no-site-test-app')->withAutoCreateSite(false)->build();
        self::assertCount(1, $scope->getRecords('pages'));
        self::assertFileDoesNotExist($scope->getInstancePath("/config/sites/{$scope->getInstanceName()}/config.yaml"));
    }

    public function testCanDefineSiteRootPageId(): void
    {
        $scope = $this->scopeBuilder->withSiteRootPageId(2)->build();
        $siteYaml = file_get_contents($scope->getInstancePath('/config/sites/test-app/config.yaml'));
        self::assertStringContainsString('rootPageId: 2', $siteYaml);
    }

    public function testCanConfigureSiteBase(): void
    {
        $scope = $this->scopeBuilder->withSiteBase('/test-app')->build();

        self::assertSame(404, $scope->doServerRequest(new ServerRequest('/'))->getStatusCode());
        self::assertSame(200, $scope->doServerRequest(new ServerRequest('/test-app'))->getStatusCode());
    }
}
