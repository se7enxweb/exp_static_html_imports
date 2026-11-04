<?php
/**
 * File containing the expStaticImportTypeSitemapCrawl class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * --sitemap-crawl: a live site, read page by page from its sitemap.xml (sitemap indexes are followed). The source is
 * the site's base URL (https://example.org/). Each page is fetched once into a mirror folder in the import's work
 * folder, then read like --html-generic, with the same selectors and rules ([Type_sitemap-crawl]).
 *
 * Polite by default: robots.txt is respected (Disallow lines for * and for the user agent), one request a second
 * (RequestInterval, seconds, may be a fraction), the user agent says who is asking (UserAgent), and a page already in
 * the mirror is not fetched again unless Refetch=enabled.
 *
 * Paths: a URL's path below the base, a folder URL (ending in /) stored as <folder>/index.html, so the folder
 * structure of the URLs becomes the page tree as it does for a folder of files.
 */
class expStaticImportTypeSitemapCrawl extends expStaticImportHtmlType
{
    /** @var string where fetched pages are kept */
    protected $mirror = '';

    /** @var float when the last request was sent */
    protected $lastRequest = 0.0;

    /** @var array|null Disallow prefixes */
    protected $disallow = null;

    public function identifier()
    {
        return 'sitemap-crawl';
    }

    /**
     * @param string $dir the import's work folder; the mirror goes below it
     */
    public function setWorkDir( $dir )
    {
        $this->mirror = rtrim( $dir, '/' ) . '/mirror';
    }

    public function discover( $source )
    {
        $base = rtrim( $source, '/' ) . '/';
        $sitemap = (string)$this->rules->get( 'SitemapPath', 'sitemap.xml' );
        $urls = $this->sitemapUrls( $base . ltrim( $sitemap, '/' ), 0 );
        $paths = array();
        foreach ( $urls as $url )
        {
            if ( strpos( $url, $base ) !== 0 )
                continue;
            $path = $this->pathOf( substr( $url, strlen( $base ) ) );
            if ( $path === null || !$this->allowed( '/' . ltrim( (string)parse_url( $url, PHP_URL_PATH ), '/' ), $base ) )
                continue;
            if ( $this->rules->getList( 'IncludePaths' ) && !self::matchesAny( $path, $this->rules->getList( 'IncludePaths' ) ) )
                continue;
            if ( self::matchesAny( $path, $this->rules->getList( 'ExcludePaths' ) ) )
                continue;
            $paths[$path] = true;
        }
        $paths = array_keys( $paths );
        usort( $paths, function ( $a, $b ) {
            $depth = substr_count( $a, '/' ) - substr_count( $b, '/' );
            return $depth !== 0 ? $depth : strcmp( $a, $b );
        } );
        return $paths;
    }

    public function parse( $source, $path )
    {
        if ( $this->mirror === '' )
            throw new expStaticImportPageException( 'no work folder for the crawl mirror' );
        $file = $this->mirror . '/' . $path;
        if ( !is_file( $file ) || $this->rules->enabled( 'Refetch' ) )
        {
            $relative = preg_replace( '#(^|/)index\.html$#', '$1', $path );
            $html = $this->fetch( rtrim( $source, '/' ) . '/' . $relative );
            if ( $html === null )
                throw new expStaticImportPageException( "cannot fetch $relative" );
            if ( !is_dir( dirname( $file ) ) )
                mkdir( dirname( $file ), 0775, true );
            file_put_contents( $file, $html );
        }
        return parent::parse( $this->mirror, $path );
    }

    /**
     * @param string $relative the URL below the base
     * @return string|null
     */
    protected function pathOf( $relative )
    {
        $relative = preg_replace( '/[?#].*$/', '', $relative );
        if ( $relative === '' || substr( $relative, -1 ) === '/' )
            return $relative . 'index.html';
        if ( !preg_match( '/\.[a-z0-9]{2,5}$/i', $relative ) )
            return $relative . '/index.html';
        return preg_match( '/\.html?$/i', $relative ) ? $relative : null;
    }

    /**
     * @param string $url
     * @param int $depth
     * @return array the page URLs
     */
    protected function sitemapUrls( $url, $depth )
    {
        if ( $depth > 3 )
            return array();
        $xml = $this->fetch( $url );
        if ( $xml === null )
            return array();
        $previous = libxml_use_internal_errors( true );
        $doc = simplexml_load_string( $xml );
        libxml_use_internal_errors( $previous );
        if ( !$doc )
            return array();
        $urls = array();
        if ( $doc->getName() === 'sitemapindex' )
        {
            foreach ( $doc->sitemap as $entry )
                $urls = array_merge( $urls, $this->sitemapUrls( trim( (string)$entry->loc ), $depth + 1 ) );
            return $urls;
        }
        foreach ( $doc->url as $entry )
            $urls[] = trim( (string)$entry->loc );
        return $urls;
    }

    /**
     * @param string $path the URL path
     * @param string $base
     * @return bool what robots.txt allows
     */
    protected function allowed( $path, $base )
    {
        if ( $this->disallow === null )
        {
            $this->disallow = array();
            $robots = $this->fetch( $base . 'robots.txt' );
            $agent = strtolower( $this->userAgent() );
            $applies = false;
            foreach ( preg_split( '/\r?\n/', (string)$robots ) as $line )
            {
                $line = trim( preg_replace( '/#.*$/', '', $line ) );
                if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) )
                {
                    $name = strtolower( trim( $m[1] ) );
                    $applies = $name === '*' || strpos( $agent, $name ) !== false;
                }
                else if ( $applies && preg_match( '/^disallow:\s*(.*)$/i', $line, $m ) && trim( $m[1] ) !== '' )
                    $this->disallow[] = trim( $m[1] );
            }
        }
        foreach ( $this->disallow as $prefix )
            if ( strpos( $path, $prefix ) === 0 )
                return false;
        return true;
    }

    /**
     * @return string
     */
    protected function userAgent()
    {
        return (string)$this->rules->get( 'UserAgent', 'Exponential static import (exp_static_html_imports; +https://doc.exponential.earth)' );
    }

    /**
     * @param string $url
     * @return string|null the body of a 200 answer
     */
    protected function fetch( $url )
    {
        $interval = (float)$this->rules->get( 'RequestInterval', 1 );
        $wait = $this->lastRequest + $interval - microtime( true );
        if ( $wait > 0 )
            usleep( (int)( $wait * 1000000 ) );
        $this->lastRequest = microtime( true );
        $context = stream_context_create( array( 'http' => array(
            'method' => 'GET',
            'header' => 'User-Agent: ' . $this->userAgent() . "\r\nAccept: text/html,application/xml;q=0.9,*/*;q=0.5\r\n",
            'timeout' => (float)$this->rules->get( 'Timeout', 30 ),
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ) ) );
        $body = @file_get_contents( $url, false, $context );
        $status = 0;
        if ( isset( $http_response_header ) )
            foreach ( $http_response_header as $header )
                if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) )
                    $status = (int)$m[1];
        return ( $body === false || $status !== 200 ) ? null : $body;
    }
}
