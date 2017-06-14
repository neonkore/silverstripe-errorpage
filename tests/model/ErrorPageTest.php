<?php

namespace SilverStripe\ErrorPage\Tests;

use SilverStripe\Versioned\Versioned;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;

/**
 * @package cms
 * @subpackage tests
 */
class ErrorPageTest extends FunctionalTest
{
    protected static $fixture_file = 'ErrorPageTest.yml';

    /**
     * Location of temporary cached files
     *
     * @var string
     */
    protected $tmpAssetsPath = '';

    public function setUp()
    {
        parent::setUp();
        // Set temporary asset backend store
        TestAssetStore::activate('ErrorPageTest');
        Config::modify()->set(ErrorPage::class, 'enable_static_file', true);
        $this->logInWithPermission('ADMIN');
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function test404ErrorPage()
    {
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '404');
        // ensure that the errorpage exists as a physical file
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        $response = $this->get('nonexistent-page');

        /* We have body text from the error page */
        $this->assertNotNull($response->getBody(), 'We have body text from the error page');

        /* Status code of the HTTPResponse for error page is "404" */
        $this->assertEquals($response->getStatusCode(), '404', 'Status code of the HTTPResponse for error page is "404"');

        /* Status message of the HTTPResponse for error page is "Not Found" */
        $this->assertEquals($response->getStatusDescription(), 'Not Found', 'Status message of the HTTResponse for error page is "Not found"');
    }

    public function testBehaviourOfShowInMenuAndShowInSearchFlags()
    {
        $page = $this->objFromFixture(ErrorPage::class, '404');

        /* Don't show the error page in the menus */
        $this->assertEquals($page->ShowInMenus, 0, 'Don\'t show the error page in the menus');

        /* Don't show the error page in the search */
        $this->assertEquals($page->ShowInSearch, 0, 'Don\'t show the error page in search');
    }

    public function testBehaviourOf403()
    {
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '403');
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        try {
            $controller = singleton(ContentController::class);
            $controller->httpError(403);
            $this->fail('Expected exception to be thrown');
        } catch (HTTPResponse_Exception $e) {
            $response = $e->getResponse();
            $this->assertEquals($response->getStatusCode(), '403');
            $this->assertNotNull($response->getBody(), 'We have body text from the error page');
        }
    }

    public function testSecurityError()
    {
        // Generate 404 page
        /** @var ErrorPage $page */
        $page = $this->objFromFixture(ErrorPage::class, '404');
        $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);

        // Test invalid action
        $response = $this->get('Security/nosuchaction');
        $this->assertEquals($response->getStatusCode(), '404');
        $this->assertNotNull($response->getBody());
        $this->assertContains('text/html', $response->getHeader('Content-Type'));
    }

    public function testStaticCaching()
    {
        // Test new error code does not have static content
        $error = ErrorPage::get_content_for_errorcode('401');
        $this->assertEmpty($error);
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-401.html';
        $this->assertFileNotExists($expectedErrorPagePath, 'Error page is not automatically cached');

        // Write new 401 page
        $page = new ErrorPage();
        $page->Title = '401 Error';
        $page->ErrorCode = 401;
        $page->Title = 'Unauthorised';
        $page->write();
        $page->publishRecursive();

        // Static cache should now exist
        $this->assertNotEmpty(ErrorPage::get_content_for_errorcode('401'));
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-401.html';
        $this->assertFileExists($expectedErrorPagePath, 'Error page is cached');
    }

    /**
     * Test fallback to file generation API with enable_static_file disabled
     */
    public function testGeneratedFile()
    {
        Config::modify()->set(ErrorPage::class, 'enable_static_file', false);
        $this->logInWithPermission('ADMIN');

        $page = new ErrorPage();
        $page->ErrorCode = 405;
        $page->Title = 'Method Not Allowed';
        $page->write();
        $page->publishRecursive();

        // Dynamic content is available
        $response = ErrorPage::response_for('405');
        $this->assertNotEmpty($response);
        $this->assertNotEmpty($response->getBody());
        $this->assertEquals(405, (int)$response->getStatusCode());

        // Static content is not available
        $this->assertEmpty(ErrorPage::get_content_for_errorcode('405'));
        $expectedErrorPagePath = TestAssetStore::base_path() . '/error-405.html';
        $this->assertFileNotExists($expectedErrorPagePath, 'Error page is not cached in static location');
    }

    public function testGetByLink()
    {
        $notFound = $this->objFromFixture(ErrorPage::class, '404');

        SiteTree::config()->nested_urls = false;
        $this->assertEquals($notFound->ID, SiteTree::get_by_link($notFound->Link(), false)->ID);

        Config::inst()->update(SiteTree::class, 'nested_urls', true);
        $this->assertEquals($notFound->ID, SiteTree::get_by_link($notFound->Link(), false)->ID);
    }

    public function testIsCurrent()
    {
        $aboutPage = $this->objFromFixture('Page', 'about');
        $errorPage = $this->objFromFixture(ErrorPage::class, '404');

        Director::set_current_page($aboutPage);
        $this->assertFalse($errorPage->isCurrent(), 'Assert isCurrent works on error pages.');

        Director::set_current_page($errorPage);
        $this->assertTrue($errorPage->isCurrent(), 'Assert isCurrent works on error pages.');
    }
}
