<?php
/**
 * File containing the expStaticImportLinkResolver class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Turns one link of an imported page (an href or a src, as the static source wrote it) into what it points at in the
 * imported tree. It knows nothing of the database: the engine hands it lookups, so it is tested without one.
 *
 * Every internal reference becomes a node (written as eznode://<node id>[#anchor], resolved to the node's current
 * address on every render, so it survives moves and renames), or a media object (an image is embedded by its object,
 * a link to a file is a node link to the file's node). What cannot be resolved is reported with its form and the
 * reason, never dropped: the link then keeps its static address.
 *
 * The forms it reads:
 *   relative (x.html, ../x.html) and root-relative (/x/y.html) paths of the source, with or without the suffix
 *   (a folder: <folder>.html, <folder>/<index file>), URL-encoded (%2b, %20) and in any case;
 *   anchors (#name) and query strings, which are dropped from the path (the anchor is kept for the node link);
 *   a page id anywhere in the query (Confluence: viewpage.action?pageId=123, viewpageNNNN.html?pageId=123);
 *   full URLs of the site itself (hosts): the path of the source, or the original address of a mirrored page;
 *   original addresses of a mirrored site (addresses: "doc.ez.no/Exponential/Technical-manual/4.7" => the file
 *   HTTrack wrote for it, e.g. Exponential/Technical-manual/4.html), so the old site's own addresses resolve too;
 *   an address that only forwards to another (a login page with os_destination=<address>): that address.
 * Statuses: node, media, anchor (a link inside the page), external, mailto, ignored (javascript:, a template
 * placeholder such as {$node.url_alias}, an empty href), unresolved.
 */
class expStaticImportLinkResolver
{
    const NODE = 'node';
    const MEDIA = 'media';
    const ANCHOR = 'anchor';
    const EXTERNAL = 'external';
    const MAILTO = 'mailto';
    const IGNORED = 'ignored';
    const UNRESOLVED = 'unresolved';

    /** @var callable (string path) => int node id, 0 when the path is no imported page */
    protected $page;

    /** @var callable (string page id) => int node id, 0 when unknown */
    protected $pageId;

    /** @var callable (string path) => array( 'object_id' => int, 'node_id' => int ) or null: an imported media file */
    protected $asset;

    /** @var array lower-case host => true: the site's own hosts */
    protected $hosts = array();

    /** @var array original address (host/path[?query], no scheme) => source path; and a lower-case copy */
    protected $addresses = array();
    protected $addressesLower = array();

    /** @var array lower-case host => true: hosts whose pages are internal only when they are imported (ez.no) */
    protected $softHosts = array();

    /** @var array source path => its original address (the reverse of addresses) */
    protected $origins = array();

    /** @var array the index files of a folder */
    protected $indexFiles = array( 'index.html' );

    /** @var array query parameters whose value is the address a link forwards to */
    protected $forwardParameters = array( 'os_destination' );

    /** @var callable|null (string path) => string|null: the known path that matches the path in another case */
    protected $caseless;

    /**
     * @param array $options
     *   page               callable path => node id
     *   page_id            callable page id => node id
     *   asset              callable path => array( object_id, node_id ) or null
     *   hosts              the site's own hosts (LinkHosts[])
     *   soft_hosts         hosts whose pages are internal when imported, else external (LinkHostsIfImported[])
     *   addresses          original address => source path (a mirror's own addresses)
     *   index_files        IndexFiles[]
     *   forward_parameters query parameters that carry the address a link forwards to
     *   caseless           callable path => the known path in its own case, or null
     */
    public function __construct( array $options )
    {
        $none = function () { return 0; };
        $this->page = isset( $options['page'] ) ? $options['page'] : $none;
        $this->pageId = isset( $options['page_id'] ) ? $options['page_id'] : $none;
        $this->asset = isset( $options['asset'] ) ? $options['asset'] : function () { return null; };
        $this->caseless = isset( $options['caseless'] ) ? $options['caseless'] : null;
        foreach ( isset( $options['hosts'] ) ? (array)$options['hosts'] : array() as $host )
            if ( trim( (string)$host ) !== '' )
                $this->hosts[strtolower( trim( (string)$host ) )] = true;
        foreach ( isset( $options['soft_hosts'] ) ? (array)$options['soft_hosts'] : array() as $host )
            if ( trim( (string)$host ) !== '' )
                $this->softHosts[strtolower( trim( (string)$host ) )] = true;
        foreach ( isset( $options['addresses'] ) ? (array)$options['addresses'] : array() as $address => $path )
        {
            $address = self::normalizeAddress( (string)$address );
            if ( !isset( $this->addresses[$address] ) )
                $this->addresses[$address] = (string)$path;
            if ( !isset( $this->addressesLower[strtolower( $address )] ) )
                $this->addressesLower[strtolower( $address )] = (string)$path;
            if ( !isset( $this->origins[(string)$path] ) )
                $this->origins[(string)$path] = $address;
        }
        if ( !empty( $options['index_files'] ) )
            $this->indexFiles = array_values( (array)$options['index_files'] );
        if ( isset( $options['forward_parameters'] ) )
            $this->forwardParameters = array_values( (array)$options['forward_parameters'] );
    }

    /**
     * @param string $fromPath the source path of the page the link is on
     * @param string $href the href or src as the source wrote it (HTML entities allowed)
     * @param bool $media true for a src (an image): only media resolve
     * @param int $depth how many forwarding addresses were followed to get here
     * @return array status, form, href, target (the source path it stands for), node, object, anchor, reason
     */
    public function resolve( $fromPath, $href, $media = false, $depth = 0 )
    {
        $raw = (string)$href;
        $href = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
        $out = array( 'status' => self::UNRESOLVED, 'form' => self::form( $href ), 'href' => $raw, 'target' => '', 'node' => 0, 'object' => 0,
                      'anchor' => '', 'reason' => '' );
        $hashAt = strpos( $href, '#' );
        if ( $hashAt !== false )
            $out['anchor'] = (string)substr( $href, $hashAt + 1 );

        if ( $href === '' || preg_match( '/^javascript:/i', $href ) || preg_match( '/[{}$]|\|ezurl/', $href ) )
            return array( 'status' => self::IGNORED, 'reason' => $href === '' ? 'empty' : 'a script or a template placeholder, not an address' ) + $out;
        if ( $href[0] === '#' )
            return array( 'status' => self::ANCHOR ) + $out;
        if ( preg_match( '/^mailto:/i', $href ) )
            return array( 'status' => self::MAILTO ) + $out;
        if ( preg_match( '#^ez(node|object)://#i', $href ) )
            return array( 'status' => self::IGNORED, 'reason' => 'already a node or object link' ) + $out;

        $candidates = array();
        $pageIds = array();
        $host = '';
        if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//([^/?\#:]+)(?::\d+)?([^\#]*)#i', $href, $u ) )
        {
            $host = strtolower( $u[1] );
            if ( strpos( $host, 'www.' ) === 0 && !isset( $this->hosts[$host] ) && !isset( $this->softHosts[$host] ) )
                $host = substr( $host, 4 );
            if ( !isset( $this->hosts[$host] ) && !isset( $this->softHosts[$host] ) )
                return array( 'status' => self::EXTERNAL ) + $out;
            $soft = !isset( $this->hosts[$host] );
            $local = $u[2] === '' ? '/' : $u[2];
            $this->addAddressCandidates( $candidates, $host, $local );
            $this->addPathCandidates( $candidates, '', $local );
        }
        else if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) )
            return array( 'status' => self::EXTERNAL ) + $out;
        else
        {
            $local = $hashAt !== false ? substr( $href, 0, $hashAt ) : $href;
            if ( $local === '' )
                return array( 'status' => self::ANCHOR ) + $out;
            $this->addPathCandidates( $candidates, $fromPath, $local );
            // a file the mirror renamed after copying it (display/USER.html, copied from display/USER/Documentation):
            // its relative links are relative to its original address, tried after its own place
            if ( $local[0] !== '/' && isset( $this->origins[(string)$fromPath] ) )
            {
                $origin = self::stripQuery( (string)$this->origins[(string)$fromPath] );
                $originPath = strpos( $origin, '/' ) !== false ? substr( $origin, strpos( $origin, '/' ) + 1 ) : '';
                if ( $originPath !== '' && dirname( $originPath ) !== dirname( (string)$fromPath ) )
                    $this->addPathCandidates( $candidates, substr( $originPath, -1 ) === '/' ? $originPath . 'index.html' : $originPath, $local );
            }
            // a root-relative address is also the original site's own address
            if ( $local[0] === '/' )
                foreach ( array_keys( $this->hosts ) as $h )
                    $this->addAddressCandidates( $candidates, $h, $local );
        }
        // a page id anywhere in the query (Confluence) wins: it names the current page, whatever copy was linked
        $query = isset( $local ) && strpos( $local, '?' ) !== false ? substr( $local, strpos( $local, '?' ) + 1 ) : '';
        if ( preg_match( '/(?:^|[?&])pageId=(\d+)/', $query, $p ) )
            $pageIds[] = $p[1];
        // an address that forwards (a login page with os_destination=...): the address it forwards to
        $forward = $this->forwardOf( $query );
        // a file of the mirror whose original address forwards (HTTrack's login47b7.html for login.action?...)
        foreach ( $candidates as $candidate )
        {
            if ( $forward !== null )
                break;
            $origin = isset( $this->origins[self::stripQuery( $candidate )] ) ? $this->origins[self::stripQuery( $candidate )] : '';
            if ( $origin !== '' && strpos( $origin, '?' ) !== false )
                $forward = $this->forwardOf( substr( $origin, strpos( $origin, '?' ) + 1 ) );
        }

        if ( !$media )
            foreach ( $pageIds as $id )
                if ( ( $node = (int)call_user_func( $this->pageId, $id ) ) > 0 )
                    return array( 'status' => self::NODE, 'node' => $node, 'target' => 'page:' . $id ) + $out;

        foreach ( $candidates as $candidate )
        {
            $path = self::stripQuery( $candidate );
            if ( $path === '' && $candidate !== '' )
                continue;
            $asset = call_user_func( $this->asset, $path );
            if ( $asset && ( !empty( $asset['object_id'] ) || !empty( $asset['node_id'] ) ) )
                return array( 'status' => self::MEDIA, 'node' => (int)( isset( $asset['node_id'] ) ? $asset['node_id'] : 0 ),
                              'object' => (int)( isset( $asset['object_id'] ) ? $asset['object_id'] : 0 ), 'target' => $path ) + $out;
            if ( $media )
                continue;
            $node = (int)call_user_func( $this->page, $path );
            if ( !$node && $this->caseless && ( $known = call_user_func( $this->caseless, $path ) ) !== null && $known !== $path )
                $node = (int)call_user_func( $this->page, $known );
            if ( $node > 0 )
                return array( 'status' => self::NODE, 'node' => $node, 'target' => $path ) + $out;
        }

        if ( $forward !== null && !$media && $depth < 3 )
        {
            $next = $this->resolve( $fromPath, $forward, false, $depth + 1 );
            if ( $next['status'] === self::NODE )
                return array( 'status' => self::NODE, 'node' => $next['node'], 'target' => $next['target'], 'anchor' => $next['anchor'] ) + $out;
            return array( 'status' => self::UNRESOLVED, 'target' => $next['target'] ?: $forward, 'reason' => 'forwards to an address that is not imported: ' . $forward ) + $out;
        }

        // a page of a host that is only partly imported (ez.no) that is not in the import stays a link to that host
        if ( !empty( $soft ) )
            return array( 'status' => self::EXTERNAL, 'reason' => 'not in this import: stays a link to ' . $host ) + $out;
        $first = $candidates ? self::stripQuery( reset( $candidates ) ) : '';
        $reason = $first === '' || $first === null ? 'outside the source' : ( $media ? 'the media file is not imported' : 'no imported page or media at this path' );
        if ( $pageIds )
            $reason = 'page id ' . implode( ',', $pageIds ) . ' is not imported';
        return array( 'status' => self::UNRESOLVED, 'target' => (string)$first, 'reason' => $reason ) + $out;
    }

    /**
     * The path candidates of a (relative or root-relative) path of the source: as written, URL-decoded, with "+"
     * and " " swapped, and for an address without a suffix its page and index file.
     *
     * @param array $candidates
     * @param string $fromPath
     * @param string $local the path, with its query, without the anchor
     */
    protected function addPathCandidates( array &$candidates, $fromPath, $local )
    {
        $query = strpos( $local, '?' ) !== false ? substr( $local, strpos( $local, '?' ) ) : '';
        $path = strpos( $local, '?' ) !== false ? substr( $local, 0, strpos( $local, '?' ) ) : $local;
        $variants = array( $path );
        $decoded = rawurldecode( $path );
        $variants[] = $decoded;
        $variants[] = str_replace( '+', ' ', $decoded );
        $variants[] = str_replace( ' ', '+', $decoded );
        $variants[] = rawurldecode( $decoded );
        foreach ( array_unique( $variants ) as $variant )
        {
            $resolved = self::join( $fromPath, $variant );
            if ( $resolved === null )
                continue;
            foreach ( $this->pathForms( $resolved ) as $form )
                $candidates[] = $form . $query;
        }
        $candidates = array_values( array_unique( $candidates ) );
    }

    /**
     * The source paths of an original address of the mirrored site (addresses), with and without its query.
     *
     * @param array $candidates
     * @param string $host
     * @param string $local the path with its query, without the anchor
     */
    protected function addAddressCandidates( array &$candidates, $host, $local )
    {
        if ( !$this->addresses )
            return;
        $keys = array( $host . $local );
        $plain = strpos( $local, '?' ) !== false ? substr( $local, 0, strpos( $local, '?' ) ) : $local;
        $keys[] = $host . $plain;
        $keys[] = $host . rawurldecode( $plain );
        $keys[] = $host . rtrim( $plain, '/' );
        foreach ( array_unique( $keys ) as $key )
        {
            $key = self::normalizeAddress( $key );
            if ( isset( $this->addresses[$key] ) )
                $candidates[] = $this->addresses[$key];
            else if ( isset( $this->addressesLower[strtolower( $key )] ) )
                $candidates[] = $this->addressesLower[strtolower( $key )];
        }
        $candidates = array_values( array_unique( $candidates ) );
    }

    /**
     * @param string $path a source path
     * @return array the path, and for one without a page suffix (a folder or an address) its .html page and index
     */
    protected function pathForms( $path )
    {
        $forms = array( $path );
        $trimmed = rtrim( $path, '/' );
        if ( !preg_match( '/\.[a-z0-9]{1,5}$/i', $trimmed ) || preg_match( '/\.(x|\d+)$/i', $trimmed ) )
        {
            if ( $trimmed !== '' )
                $forms[] = $trimmed . '.html';
            foreach ( $this->indexFiles as $index )
                $forms[] = ( $trimmed === '' ? '' : $trimmed . '/' ) . $index;
            $forms[] = $trimmed . '/';
        }
        return array_values( array_unique( $forms ) );
    }

    /**
     * @param string $query
     * @return string|null the address a forwarding parameter carries
     */
    protected function forwardOf( $query )
    {
        if ( $query === '' || !$this->forwardParameters )
            return null;
        parse_str( html_entity_decode( $query, ENT_QUOTES, 'UTF-8' ), $values );
        foreach ( $this->forwardParameters as $name )
            if ( isset( $values[$name] ) && is_string( $values[$name] ) && $values[$name] !== '' )
                return $values[$name];
        return null;
    }

    /**
     * @param string $fromPath
     * @param string $href a path without its anchor (a query is kept)
     * @return string|null the source path (".." above the top stays at the top), null for an empty href
     */
    public static function join( $fromPath, $href )
    {
        if ( $href === '' )
            return null;
        $query = strpos( $href, '?' ) !== false ? substr( $href, strpos( $href, '?' ) ) : '';
        $path = strpos( $href, '?' ) !== false ? substr( $href, 0, strpos( $href, '?' ) ) : $href;
        $base = $path !== '' && $path[0] === '/' ? '' : dirname( (string)$fromPath );
        $parts = array();
        $trailing = substr( $path, -1 ) === '/';
        foreach ( explode( '/', ( $base === '.' || $base === '' ? '' : $base . '/' ) . ltrim( $path, '/' ) ) as $segment )
        {
            if ( $segment === '' || $segment === '.' )
                continue;
            // ".." above the top stays at the top, as a browser resolves it (RFC 3986 5.2.4): HTTrack writes
            // ../../download/... on pages one folder deep
            if ( $segment === '..' )
            {
                array_pop( $parts );
                continue;
            }
            $parts[] = $segment;
        }
        $joined = implode( '/', $parts );
        return ( $joined !== '' && $trailing ? $joined . '/' : $joined ) . $query;
    }

    /**
     * @param string $path
     * @return string the path without its query
     */
    public static function stripQuery( $path )
    {
        $at = strpos( (string)$path, '?' );
        return $at === false ? (string)$path : substr( (string)$path, 0, $at );
    }

    /**
     * @param string $address host/path[?query], with or without a scheme
     * @return string without the scheme, "www." and the anchor; HTML entities decoded
     */
    public static function normalizeAddress( $address )
    {
        $address = html_entity_decode( trim( (string)$address ), ENT_QUOTES, 'UTF-8' );
        $address = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $address );
        $address = preg_replace( '/#.*$/s', '', $address );
        if ( stripos( $address, 'www.' ) === 0 )
            $address = substr( $address, 4 );
        return $address;
    }

    /**
     * The form of a link, for the inventory and the reports.
     *
     * @param string $href
     * @return string
     */
    public static function form( $href )
    {
        $v = trim( html_entity_decode( (string)$href, ENT_QUOTES, 'UTF-8' ) );
        if ( $v === '' )
            return 'empty';
        if ( $v[0] === '#' )
            return 'pure anchor';
        if ( preg_match( '/^mailto:/i', $v ) )
            return 'mailto';
        if ( preg_match( '/^javascript:/i', $v ) )
            return 'javascript';
        if ( preg_match( '#^ez(node|object)://#i', $v ) )
            return 'eznode/ezobject';
        if ( preg_match( '/[{}$]|\|ezurl/', $v ) )
            return 'template placeholder';
        if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//(?:www\.)?([^/?\#:]+)#i', $v, $h ) )
            return 'absolute ' . strtolower( $h[1] );
        if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $v ) )
            return 'other scheme';
        $path = strtolower( preg_split( '/[?#]/', $v, 2 )[0] );
        $prefix = $v[0] === '/' ? 'root-relative ' : ( strpos( $v, '../' ) === 0 ? 'relative ../ ' : 'relative ' );
        $tail = ( strpos( $v, '#' ) !== false ? ' +anchor' : '' ) . ( strpos( $v, '?' ) !== false ? ' +query' : '' );
        if ( strpos( $v, 'pageId=' ) !== false )
            return $prefix . 'by pageId' . $tail;
        if ( preg_match( '#(^|/)download/(attachments|thumbnails)/#', $path ) )
            return 'download/attachments';
        if ( preg_match( '#(^|/)s/[^/]+/.*/_/#', $path ) )
            return 'Confluence static resource';
        if ( preg_match( '#(^|/)login\w*\.html$|login\.action#', $path ) )
            return 'login page';
        if ( preg_match( '#(^|/)display/#', $path ) )
            return $prefix . 'display/SPACE/Title' . ( preg_match( '/%2b|\+/i', $v ) ? ' (+ encoded)' : '' ) . $tail;
        if ( preg_match( '#(^|/)pages/#', $path ) )
            return $prefix . 'pages/*' . $tail;
        if ( preg_match( '/\.(png|jpe?g|gif|svg|webp|ico|bmp)$/', $path ) )
            return $prefix . 'image' . $tail;
        if ( preg_match( '/\.(pdf|zip|tgz|gz|txt|xml|odt|docx?|pptx?|xlsx?|sql|php|ini|tpl|css|js)$/', $path ) )
            return $prefix . 'file' . $tail;
        if ( preg_match( '/\.html?$/', $path ) )
            return $prefix . '.html page' . $tail;
        return $prefix . 'path without suffix' . $tail;
    }
}
