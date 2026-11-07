<?php
/**
 * File containing the expStaticImportTypeMarkdown class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * --markdown: a folder of Markdown files, with the same rules as the help center: front matter (title, order,
 * keywords, summary) between --- lines, a folder's README.md or index.md as its own page, links between .md files.
 * The Markdown is rendered with the help center's renderer (expHelpMarkdownRenderer) when the kernel has it, so a page
 * looks the same in the content tree as in the help center.
 *
 * Rules ([Type_markdown]): Suffixes[] (md, markdown), IndexFiles[] (README.md, index.md), ClassRules[],
 * DefaultClass, AttributeMap[], IncludePaths[], ExcludePaths[], HiddenPaths[].
 */
class expStaticImportTypeMarkdown extends expStaticImportHtmlType
{
    public function identifier()
    {
        return 'markdown';
    }

    public function discover( $source )
    {
        if ( !$this->rules->getList( 'Suffixes' ) )
            $this->rules = new expStaticImportRules( $this->rules->type(), array_merge( $this->rules->all(), array( 'Suffixes' => array( 'md', 'markdown' ) ) ) );
        return parent::discover( $source );
    }

    public function parse( $source, $path )
    {
        $file = rtrim( $source, '/' ) . '/' . $path;
        $text = @file_get_contents( $file );
        if ( $text === false )
            throw new expStaticImportPageException( "cannot read $path" );
        list( $front, $markdown ) = self::splitFrontMatter( $text );
        $meta = self::frontMatter( $front );
        $title = isset( $meta['title'] ) ? (string)$meta['title'] : '';
        if ( $title === '' && preg_match( '/^#\s+(.+)$/m', $markdown, $m ) )
        {
            $title = trim( $m[1] );
            if ( $this->rules->enabled( 'RemoveTitleFromBody', true ) )
                $markdown = preg_replace( '/^#\s+' . preg_quote( $m[1], '/' ) . '\s*$/m', '', $markdown, 1 );
        }
        if ( $title === '' )
            $title = ucfirst( str_replace( array( '-', '_' ), ' ', pathinfo( $path, PATHINFO_FILENAME ) ) );

        $html = self::render( $markdown, $path );
        $doc = expStaticImportDom::load( '<html><body><div id="exp-md">' . $html . '</div></body></html>' );
        $body = $doc->getElementById( 'exp-md' );
        $cleaner = new expStaticImportHtmlCleaner( $this->rules );
        $bodyHtml = $body ? $cleaner->clean( $doc, $body ) : $html;

        $metaOut = array(
            'labels' => isset( $meta['tags'] ) ? (array)$meta['tags'] : array(),
            'keywords' => isset( $meta['keywords'] ) ? array_map( 'trim', is_array( $meta['keywords'] ) ? $meta['keywords'] : explode( ',', (string)$meta['keywords'] ) ) : array(),
            'description' => isset( $meta['summary'] ) ? (string)$meta['summary'] : ( isset( $meta['description'] ) ? (string)$meta['description'] : '' ),
            'modified' => (int)@filemtime( $file ),
            'author' => isset( $meta['author'] ) ? (string)$meta['author'] : '',
        );
        return array(
            'path' => $path,
            'title' => $title,
            'body_html' => $bodyHtml,
            'original_html' => $text,
            'meta' => $metaOut,
            'hidden' => self::isHiddenPath( $path, $this->rules ),
            'aliases' => array(),
            'priority' => isset( $meta['order'] ) ? (int)$meta['order'] : 0,
        );
    }

    public function links( array $page )
    {
        $links = array();
        if ( !preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $page['body_html'], $m ) )
            return array();
        foreach ( $m[1] as $href )
        {
            $target = expStaticImportDom::resolve( $page['path'], $href );
            if ( $target !== null && preg_match( '/\.(md|markdown)$/i', $target ) )
                $links[$href] = $target;
        }
        return $links;
    }

    public function parentPath( $path )
    {
        $indexFiles = $this->rules->getList( 'IndexFiles' ) ?: array( 'README.md', 'index.md' );
        $this->rules = new expStaticImportRules( $this->rules->type(), array_merge( $this->rules->all(), array( 'IndexFiles' => $indexFiles ) ) );
        return parent::parentPath( $path );
    }

    /**
     * @param string $text
     * @return array front matter, rest
     */
    public static function splitFrontMatter( $text )
    {
        if ( class_exists( 'expHelpMarkdownRenderer' ) )
            return expHelpMarkdownRenderer::splitFrontMatter( $text );
        if ( preg_match( '/^\x{FEFF}?---[ \t]*\n(.*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/su', $text, $m ) )
            return array( $m[1], (string)substr( $text, strlen( $m[0] ) ) );
        return array( '', $text );
    }

    /**
     * @param string $front the lines between the --- lines
     * @return array key => value (a [a, b] list becomes an array)
     */
    public static function frontMatter( $front )
    {
        if ( $front === '' )
            return array();
        if ( class_exists( 'expHelpFrontMatter' ) )
            return (array)expHelpFrontMatter::parse( explode( "\n", $front ) );
        $data = array();
        foreach ( explode( "\n", $front ) as $line )
            if ( preg_match( '/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/', $line, $m ) )
            {
                $value = trim( $m[2], " \t\"'" );
                if ( preg_match( '/^\[(.*)\]$/', $value, $list ) )
                    $value = array_map( function ( $v ) { return trim( $v, " \t\"'" ); }, explode( ',', $list[1] ) );
                $data[strtolower( $m[1] )] = $value;
            }
        return $data;
    }

    /**
     * The soft line breaks of Markdown paragraphs ("one of the\nmost") as spaces: the rich text parser drops a newline
     * between two words instead of reading it as a space, so "themost" would be stored. Code in <pre> keeps its lines.
     *
     * @param string $html
     * @return string HTML
     */
    public static function softBreaksToSpaces( $html )
    {
        $parts = preg_split( '#(<pre\b.*?</pre>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $parts as $i => $part )
            if ( $i % 2 === 0 )
                $parts[$i] = preg_replace( '/[ \t]*\r?\n[ \t]*/', ' ', $part );
        return implode( '', $parts );
    }

    /**
     * @param string $markdown
     * @param string $path
     * @return string HTML
     */
    public static function render( $markdown, $path )
    {
        if ( class_exists( 'expHelpMarkdownRenderer' ) )
        {
            $renderer = new expHelpMarkdownRenderer();
            $rendered = $renderer->render( $markdown, $path );
            // the help center's renderer answers array( 'html' => ..., 'toc' => ..., ... ); older kernels a string
            if ( is_array( $rendered ) )
                $rendered = isset( $rendered['html'] ) ? $rendered['html'] : '';
            return self::softBreaksToSpaces( (string)$rendered );
        }
        // the smallest fallback: paragraphs and headings, everything escaped
        $html = '';
        foreach ( preg_split( '/\n{2,}/', trim( $markdown ) ) as $block )
        {
            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $block, $m ) )
                $html .= '<h' . strlen( $m[1] ) . '>' . htmlspecialchars( $m[2] ) . '</h' . strlen( $m[1] ) . '>';
            else
                $html .= '<p>' . nl2br( htmlspecialchars( $block ) ) . '</p>';
        }
        return $html;
    }
}
