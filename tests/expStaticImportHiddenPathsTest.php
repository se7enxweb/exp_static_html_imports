<?php
/**
 * File containing the expStaticImportHiddenPathsTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

foreach ( array( 'expstaticimportrules', 'expstaticimportdom', 'expstaticimportbyline', 'expstaticimporthtmlcleaner',
                 'expstaticimportpageexception', 'expstaticimporttypeinterface', 'expstaticimporthtmltype' ) as $file )
    require_once __DIR__ . '/../classes/' . $file . '.php';

/**
 * Which pages are imported hidden, without a database: HiddenPaths[] hides, VisiblePaths[] keeps a page of a hidden
 * folder visible.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportHiddenPathsTest extends \PHPUnit\Framework\TestCase
{
    public function testHiddenPathsHide()
    {
        $rules = new expStaticImportRules( 'doc-html', array( 'HiddenPaths' => array( 'doc_hidden.html', 'doc_hidden/**' ) ) );
        $this->assertTrue( expStaticImportHtmlType::isHiddenPath( 'doc_hidden/Some-page.html', $rules ) );
        $this->assertTrue( expStaticImportHtmlType::isHiddenPath( 'doc_hidden/a/b.html', $rules ) );
        $this->assertFalse( expStaticImportHtmlType::isHiddenPath( 'Exponential.html', $rules ) );
    }

    public function testVisiblePathsWinOverHiddenPaths()
    {
        $rules = new expStaticImportRules( 'doc-html', array( 'HiddenPaths' => array( 'doc_hidden/**' ),
                                                              'VisiblePaths' => array( 'doc_hidden/Standing-on-the-shoulders-of-giants.html' ) ) );
        $this->assertFalse( expStaticImportHtmlType::isHiddenPath( 'doc_hidden/Standing-on-the-shoulders-of-giants.html', $rules ) );
        $this->assertTrue( expStaticImportHtmlType::isHiddenPath( 'doc_hidden/Other.html', $rules ) );
    }

    public function testVisiblePathsAloneHideNothing()
    {
        $rules = new expStaticImportRules( 'doc-html', array( 'VisiblePaths' => array( '**' ) ) );
        $this->assertFalse( expStaticImportHtmlType::isHiddenPath( 'doc_hidden/Other.html', $rules ) );
    }
}
