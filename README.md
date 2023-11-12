# Tester 3

Tester3 is an approach for a more simple integration of functional tests for TYPO3 pages.

**This extension is currently work in progress.**

The idea is to be able to create an encapsulated test-app scope on every test, which can than be filled with data and
tested against.

Every scope has its own sqlite-database, site-config and typo3-settings and leads ideally to full determinism.

## Usage

```php

class FunctionalScopeBuilderTest extends TestCase
{
    private FunctionalScopeBuilder $scopeBuilder;

    public function setUp(): void
    {
        $this->scopeBuilder = (new FunctionalScopeBuilder())
            ->withVendorPath(__DIR__ . '/../../vendor');
    }

    public function testCanCreateScopeAndAccessibleHomepage(): void
    {
        $scope = $this->scopeBuilder->build();

        self::assertSame('root page', $scope->getRecord('pages', 1)['title']);

        $response = $scope->doServerRequest(new ServerRequest('/'));
        self::assertSame(200, $response->getStatusCode());
    }
}
```

## Todos

- [ ] Write docker env for contribution
- [ ] Test on TYPO3 11
- [ ] Introduce CI-testing
- [x] Introduce code-sniffer
- [ ] More tests
- [ ] setup test database only once before all tests and then copy to every scope (performance)