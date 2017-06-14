<?php

namespace SilverStripe\ErrorPage\Tests;

use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Assets\File;
use SilverStripe\Control\Session;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;

class ErrorPageFileExtensionTest extends SapphireTest
{

    protected static $fixture_file = 'ErrorPageTest.yml';

    protected $versionedMode = null;

    public function setUp()
    {
        parent::setUp();
        $this->versionedMode = Versioned::get_reading_mode();
        Versioned::set_stage(Versioned::DRAFT);
        TestAssetStore::activate('ErrorPageFileExtensionTest');
        $file = new File();
        $file->setFromString('dummy', 'dummy.txt');
        $file->write();
    }

    public function tearDown()
    {
        Versioned::set_reading_mode($this->versionedMode);
        TestAssetStore::reset();
        parent::tearDown(); // TODO: Change the autogenerated stub
    }

    public function testErrorPage()
    {
        // Get and publish records
        $notFoundPage = $this->objFromFixture('SilverStripe\\ErrorPage\\ErrorPage', '404');
        $notFoundPage->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $notFoundLink = $notFoundPage->Link();

        $disallowedPage = $this->objFromFixture('SilverStripe\\ErrorPage\\ErrorPage', '403');
        $disallowedPage->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $disallowedLink = $disallowedPage->Link();

        // Get stage version of file
        $file = File::get()->first();
        $fileLink = $file->Link();
        Security::setCurrentUser(null);

        // Generate shortcode for a file which doesn't exist
        $shortcode = File::handle_shortcode(array('id' => 9999), null, new ShortcodeParser(), 'file_link');
        $this->assertEquals($notFoundLink, $shortcode);
        $shortcode = File::handle_shortcode(array('id' => 9999), 'click here', new ShortcodeParser(), 'file_link');
        $this->assertEquals(sprintf('<a href="%s">%s</a>', $notFoundLink, 'click here'), $shortcode);

        // Test that user cannot view draft file
        $shortcode = File::handle_shortcode(array('id' => $file->ID), null, new ShortcodeParser(), 'file_link');
        $this->assertEquals($disallowedLink, $shortcode);
        $shortcode = File::handle_shortcode(array('id' => $file->ID), 'click here', new ShortcodeParser(), 'file_link');
        $this->assertEquals(sprintf('<a href="%s">%s</a>', $disallowedLink, 'click here'), $shortcode);

        // Authenticated users don't get the same error
        $this->logInWithPermission('ADMIN');
        $shortcode = File::handle_shortcode(array('id' => $file->ID), null, new ShortcodeParser(), 'file_link');
        $this->assertEquals($fileLink, $shortcode);
    }
}
