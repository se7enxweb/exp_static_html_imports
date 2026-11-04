<?php
/**
 * File containing the expStaticImportDates class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The real dates of an imported page: when it was first published (created) and last changed (modified), found by a
 * configurable chain of sources, so an object carries the page's own history and not the day of the import.
 *
 * DateRules[] (the type's rules, in order; the first rule of a field that gives a date wins):
 *   <field>;<source>[;<arguments>[;<label>[;<paths>]]]
 *   field      created or modified
 *   source     attribute  the page's own values: AttributeMap[] identifiers, comma separated (e.g. written_at), read
 *                         from the mapped page while importing and from the object's attributes afterwards
 *              git        the history of the source repository: first (the commit that added the file) or last (the
 *                         last commit that changed it); with nobulk, commits that touched more than
 *                         DateGitBulkCommitFiles files (default 1000: site-wide rewrites of a template or a brand, not
 *                         changes of a page) are left out. One index of the whole history, built once
 *              meta       <meta> date tags of the file, by name, property, itemprop or http-equiv, comma separated
 *              mtime      the file's modification time, never when it is the time of the clone or later
 *                         (DateMtimeCloneWindow, seconds, default 86400)
 *              children   the earliest created and the latest modified of the object's children (a section or a
 *                         container that has no file of its own)
 *              relation   the dates of the object an object relation attribute names (identifiers, comma separated),
 *                         such as the current page a history container belongs to (history_of)
 *   label      the name the report counts the source under (default: the source)
 *   paths      shell patterns, comma separated: the rule applies only to these source paths (default: every path)
 * When no rule gives a date, the time of the run is used, counted as "today". A created date later than the modified
 * date takes the modified date (and its source); a page with only one of the two dates has it as both.
 *
 * DateGitPathAliases[]=<regular expression>;<replacement>: earlier names of a path in the history (a folder renamed,
 * or a word of a file name, in the source repository; looked up without case); the history of every name counts, so
 * the first commit is the one that added the page under its first name, not the rename.
 * DateTimezone: the time zone of meta dates that carry none (default BylineTimezone, else UTC).
 */
class expStaticImportDates
{
    const TODAY = 'today';

    /** the chain when DateRules[] is not set: git, the meta tags, then the file's time */
    const DEFAULT_RULES = array(
        'created;git;first',
        'created;meta;dcterms.created,dcterms.issued,article:published_time,datePublished,date',
        'created;mtime',
        'modified;git;last,nobulk',
        'modified;meta;dcterms.modified,article:modified_time,dateModified,last-modified,date',
        'modified;mtime',
    );

    /** @var array list of array( 'field', 'source', 'args' => list, 'label', 'paths' => list ) */
    protected $chain;

    /** @var string */
    protected $sourceDir;

    /** @var string */
    protected $historyDir;

    /** @var array|null path => array( 'first' => time, 'last' => time, 'last_nobulk' => time|null ) */
    protected $gitIndex = null;

    /** @var int */
    protected $bulkFiles;

    /** @var array list of array( regex, replacement ) */
    protected $aliases = array();

    /** @var int|null */
    protected $cloneTime = null;

    /** @var int */
    protected $mtimeWindow;

    /** @var string */
    protected $timezone;

    /** @var int the time used when nothing gives a date */
    protected $now;

    /**
     * @param expStaticImportRules $rules
     * @param string $sourceDir the checkout of the source (files for meta and mtime)
     * @param string $historyDir the repository with the full history (git), '' for none
     * @param int|null $now
     */
    public function __construct( expStaticImportRules $rules, $sourceDir = '', $historyDir = '', $now = null )
    {
        $lines = $rules->getList( 'DateRules' );
        $this->chain = self::parseRules( $lines ? $lines : self::DEFAULT_RULES );
        $this->sourceDir = rtrim( (string)$sourceDir, '/' );
        $this->historyDir = (string)$historyDir;
        $this->bulkFiles = max( 1, (int)$rules->get( 'DateGitBulkCommitFiles', 1000 ) );
        foreach ( $rules->getList( 'DateGitPathAliases' ) as $line )
        {
            $parts = explode( ';', (string)$line, 2 );
            if ( count( $parts ) === 2 && @preg_match( $parts[0], '' ) !== false )
                $this->aliases[] = $parts;
        }
        $this->mtimeWindow = (int)$rules->get( 'DateMtimeCloneWindow', 86400 );
        $this->timezone = (string)$rules->get( 'DateTimezone', (string)$rules->get( 'BylineTimezone', 'UTC' ) );
        $this->now = $now === null ? time() : (int)$now;
    }

    /**
     * @param array $lines DateRules[] lines
     * @return array the chain; lines with an unknown field or source are left out
     */
    public static function parseRules( array $lines )
    {
        $chain = array();
        $list = function ( $value ) {
            return $value !== '' ? array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) ) : array();
        };
        foreach ( $lines as $line )
        {
            $parts = array_map( 'trim', explode( ';', (string)$line ) );
            $field = strtolower( $parts[0] );
            $source = isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
            if ( !in_array( $field, array( 'created', 'modified' ), true )
                 || !in_array( $source, array( 'attribute', 'git', 'meta', 'mtime', 'children', 'relation' ), true ) )
                continue;
            $chain[] = array( 'field' => $field, 'source' => $source, 'args' => $list( isset( $parts[2] ) ? $parts[2] : '' ),
                              'label' => isset( $parts[3] ) && $parts[3] !== '' ? $parts[3] : $source,
                              'paths' => $list( isset( $parts[4] ) ? $parts[4] : '' ) );
        }
        return $chain;
    }

    /**
     * @return array the chain in use
     */
    public function chain()
    {
        return $this->chain;
    }

    /**
     * @param string $source attribute, git, meta, mtime or children
     * @return bool whether a rule of the chain reads that source (children: a second pass over containers is needed)
     */
    public function uses( $source )
    {
        foreach ( $this->chain as $rule )
            if ( $rule['source'] === $source )
                return true;
        return false;
    }

    /**
     * The dates of one page.
     *
     * @param string $path the source path (a "#fragment", such as a comment's, reads its page's file)
     * @param callable|null $attribute receives an attribute identifier, returns a time or null
     * @param array|null $children array( 'created' => earliest, 'modified' => latest ) of the children, or null
     * @param callable|null $related receives an attribute identifier, returns array( created, modified ) of the
     *                      object it relates to, or null
     * @return array( 'created' => time, 'modified' => time, 'created_source' => label, 'modified_source' => label )
     */
    public function resolve( $path, $attribute = null, $children = null, $related = null )
    {
        $file = $this->fileOf( $path );
        $page = (string)$path;
        if ( ( $hash = strpos( $page, '#' ) ) !== false )
            $page = substr( $page, 0, $hash );
        $html = null;
        $found = array( 'created' => null, 'modified' => null );
        $labels = array( 'created' => self::TODAY, 'modified' => self::TODAY );
        foreach ( $this->chain as $rule )
        {
            $field = $rule['field'];
            if ( $found[$field] !== null )
                continue;
            if ( $rule['paths'] && !self::matchesAny( $page, $rule['paths'] ) )
                continue;
            $time = null;
            switch ( $rule['source'] )
            {
                case 'attribute':
                    if ( $attribute )
                        foreach ( $rule['args'] as $identifier )
                            if ( ( $time = self::validTime( call_user_func( $attribute, $identifier ) ) ) !== null )
                                break;
                    break;
                case 'git':
                    if ( $file !== '' )
                        $time = $this->gitTime( $this->relative( $file ), in_array( 'first', $rule['args'], true ) ? 'first' : 'last',
                                                in_array( 'nobulk', $rule['args'], true ) );
                    break;
                case 'meta':
                    if ( $file !== '' && $html === null )
                        $html = is_file( $file ) ? (string)@file_get_contents( $file, false, null, 0, 131072 ) : '';
                    if ( $html )
                    {
                        $dates = self::metaDates( $html, $rule['args'], $this->timezone );
                        $time = $dates ? reset( $dates ) : null;
                    }
                    break;
                case 'mtime':
                    if ( $file !== '' && is_file( $file ) )
                    {
                        $mtime = (int)@filemtime( $file );
                        $clone = $this->cloneTime();
                        if ( $mtime > 0 && ( $clone === null || $mtime < $clone - $this->mtimeWindow ) )
                            $time = $mtime;
                    }
                    break;
                case 'children':
                    if ( is_array( $children ) && !empty( $children[$field] ) )
                        $time = (int)$children[$field];
                    break;
                case 'relation':
                    if ( $related )
                        foreach ( $rule['args'] as $identifier )
                        {
                            $dates = call_user_func( $related, $identifier );
                            if ( is_array( $dates ) && ( $time = self::validTime( $field === 'created' ? $dates[0] : $dates[1] ) ) !== null )
                                break;
                        }
                    break;
            }
            $time = self::validTime( $time );
            if ( $time !== null )
            {
                $found[$field] = $time;
                $labels[$field] = $rule['label'];
            }
        }
        if ( $found['created'] !== null && $found['modified'] !== null && $found['created'] > $found['modified'] )
        {
            $found['created'] = $found['modified'];
            $labels['created'] = $labels['modified'];
        }
        // one date and not the other: the page was never changed after it was made, or it was made when it changed
        if ( $found['modified'] === null && $found['created'] !== null )
        {
            $found['modified'] = $found['created'];
            $labels['modified'] = $labels['created'];
        }
        if ( $found['created'] === null && $found['modified'] !== null )
        {
            $found['created'] = $found['modified'];
            $labels['created'] = $labels['modified'];
        }
        return array( 'created' => $found['created'] !== null ? $found['created'] : $this->now,
                      'modified' => $found['modified'] !== null ? $found['modified'] : $this->now,
                      'created_source' => $labels['created'], 'modified_source' => $labels['modified'] );
    }

    /**
     * @param string $key the path in the repository
     * @param string $which first or last
     * @param bool $noBulk leave out the commits that touched more than DateGitBulkCommitFiles files (last only)
     * @return int|null the time, over the path and its earlier names (DateGitPathAliases[])
     */
    protected function gitTime( $key, $which, $noBulk )
    {
        $index = $this->gitIndex();
        $entries = isset( $index[$key] ) ? array( $index[$key] ) : array();
        // an earlier name is looked up without case (a rename may have changed "eZ-publish" and "eZ-Publish" alike)
        foreach ( $this->aliases as $alias )
        {
            $old = @preg_replace( $alias[0], $alias[1], $key );
            if ( is_string( $old ) && $old !== '' && $old !== $key )
            {
                $lower = $this->lowerGitIndex();
                if ( isset( $lower[strtolower( $old )] ) )
                    $entries[] = $lower[strtolower( $old )];
            }
        }
        $time = null;
        foreach ( $entries as $entry )
        {
            $value = $which === 'first' ? $entry['first'] : ( $noBulk ? $entry['last_nobulk'] : $entry['last'] );
            if ( $value === null )
                continue;
            if ( $time === null || ( $which === 'first' ? $value < $time : $value > $time ) )
                $time = $value;
        }
        return $time;
    }

    /**
     * @param mixed $value
     * @return int|null a time after 1990 and not in the future, else null
     */
    public static function validTime( $value )
    {
        if ( $value === null || $value === '' || $value === false )
            return null;
        $time = is_numeric( $value ) ? (int)$value : strtotime( (string)$value );
        return $time && $time > 631152000 && $time <= time() + 86400 ? (int)$time : null;
    }

    /**
     * @param string $path
     * @param array $patterns shell patterns ("**" crosses folders, "*" does not)
     * @return bool
     */
    public static function matchesAny( $path, array $patterns )
    {
        foreach ( $patterns as $pattern )
        {
            if ( strpos( $pattern, '**' ) !== false )
            {
                $regex = '#^' . str_replace( array( '\*\*', '\*', '\?' ), array( '.*', '[^/]*', '[^/]' ), preg_quote( $pattern, '#' ) ) . '$#';
                if ( preg_match( $regex, $path ) )
                    return true;
            }
            else if ( fnmatch( $pattern, $path, FNM_PATHNAME ) )
                return true;
        }
        return false;
    }

    /**
     * @param string $path
     * @return string the file of a source path, '' for none (a group, a virtual page)
     */
    protected function fileOf( $path )
    {
        $path = (string)$path;
        if ( ( $hash = strpos( $path, '#' ) ) !== false )
            $path = substr( $path, 0, $hash );
        if ( $path === '' || substr( $path, -1 ) === '/' || $this->sourceDir === '' )
            return '';
        return $this->sourceDir . '/' . ltrim( $path, '/' );
    }

    /**
     * @param string $file
     * @return string the path in the repository
     */
    protected function relative( $file )
    {
        return ltrim( substr( $file, strlen( $this->sourceDir ) ), '/' );
    }

    /**
     * @return int|null the time the source was cloned (its .git/config, else the folder)
     */
    protected function cloneTime()
    {
        if ( $this->cloneTime === null && $this->sourceDir !== '' )
        {
            foreach ( array( $this->sourceDir . '/.git/config', $this->sourceDir . '/.git', $this->sourceDir ) as $candidate )
                if ( file_exists( $candidate ) )
                {
                    $this->cloneTime = (int)@filemtime( $candidate );
                    break;
                }
        }
        return $this->cloneTime;
    }

    /**
     * @param array $index see parseGitLog() (tests, or an index built elsewhere)
     */
    public function setGitIndex( array $index )
    {
        $this->gitIndex = $index;
        $this->lowerGitIndex = null;
    }

    /** @var array|null the git index by lower-case path (paths that differ only in case merged) */
    protected $lowerGitIndex = null;

    /**
     * @return array the git index by lower-case path, for the earlier names of DateGitPathAliases[]
     */
    protected function lowerGitIndex()
    {
        if ( $this->lowerGitIndex === null )
        {
            $this->lowerGitIndex = array();
            foreach ( $this->gitIndex() as $path => $entry )
            {
                $key = strtolower( $path );
                if ( !isset( $this->lowerGitIndex[$key] ) )
                {
                    $this->lowerGitIndex[$key] = $entry;
                    continue;
                }
                $merged = &$this->lowerGitIndex[$key];
                $merged['first'] = min( $merged['first'], $entry['first'] );
                $merged['last'] = max( $merged['last'], $entry['last'] );
                if ( $entry['last_nobulk'] !== null && ( $merged['last_nobulk'] === null || $entry['last_nobulk'] > $merged['last_nobulk'] ) )
                    $merged['last_nobulk'] = $entry['last_nobulk'];
                unset( $merged );
            }
        }
        return $this->lowerGitIndex;
    }

    /**
     * The history of every file of the source repository, from one `git log` over the whole history (names only: a
     * blobless clone fetches nothing for it).
     *
     * @return array see parseGitLog()
     */
    public function gitIndex()
    {
        if ( $this->gitIndex !== null )
            return $this->gitIndex;
        $this->gitIndex = array();
        if ( $this->historyDir === '' || !is_dir( $this->historyDir ) )
            return $this->gitIndex;
        $command = 'git -c core.quotepath=off -C ' . escapeshellarg( $this->historyDir )
                 . ' log --no-renames --name-only --format=' . escapeshellarg( '@%at' ) . ' HEAD 2>/dev/null';
        $handle = popen( $command, 'r' );
        if ( !$handle )
            return $this->gitIndex;
        $lines = function () use ( $handle ) {
            while ( ( $line = fgets( $handle ) ) !== false )
                yield $line;
        };
        $this->gitIndex = self::parseGitLog( $lines(), $this->bulkFiles );
        pclose( $handle );
        return $this->gitIndex;
    }

    /**
     * Reads the output of `git log --name-only --format=@%at`: each "@<time>" line starts a commit, the lines after it
     * are the files it touched.
     *
     * @param iterable $lines
     * @param int $bulkFiles a commit that touched more files than this is a bulk commit
     * @return array path => array( 'first' => the oldest commit's time, 'last' => the newest commit's time,
     *               'last_nobulk' => the newest time of a commit that was no bulk commit, or null )
     */
    public static function parseGitLog( $lines, $bulkFiles = 1000 )
    {
        $index = array();
        $time = null;
        $files = array();
        $flush = function () use ( &$index, &$time, &$files, $bulkFiles )
        {
            if ( $time === null )
                return;
            $bulk = count( $files ) > $bulkFiles;
            foreach ( $files as $file )
            {
                if ( !isset( $index[$file] ) )
                    $index[$file] = array( 'first' => $time, 'last' => $time, 'last_nobulk' => null );
                // the order of the log is by commit, a rebased or merged history may not be by date: keep the extremes
                if ( $time < $index[$file]['first'] )
                    $index[$file]['first'] = $time;
                if ( $time > $index[$file]['last'] )
                    $index[$file]['last'] = $time;
                if ( !$bulk && ( $index[$file]['last_nobulk'] === null || $time > $index[$file]['last_nobulk'] ) )
                    $index[$file]['last_nobulk'] = $time;
            }
            $files = array();
        };
        foreach ( $lines as $line )
        {
            $line = rtrim( (string)$line, "\r\n" );
            if ( $line === '' )
                continue;
            if ( $line[0] === '@' && ctype_digit( substr( $line, 1 ) ) )
            {
                $flush();
                $time = (int)substr( $line, 1 );
                continue;
            }
            if ( $time !== null )
                $files[] = $line;
        }
        $flush();
        return $index;
    }

    /**
     * The date tags of an HTML document.
     *
     * @param string $html
     * @param array $names the names looked for (name, property, itemprop or http-equiv; case does not matter)
     * @param string $timezone the time zone of a date that carries none
     * @return array name => time, in the order of $names, only those found with a date
     */
    public static function metaDates( $html, array $names, $timezone = 'UTC' )
    {
        $found = array();
        if ( !$names || !preg_match_all( '/<meta\b[^>]*>/i', (string)$html, $tags ) )
            return $found;
        $wanted = array_map( 'strtolower', $names );
        try
        {
            $zone = new DateTimeZone( $timezone !== '' ? $timezone : 'UTC' );
        }
        catch ( Exception $e )
        {
            $zone = new DateTimeZone( 'UTC' );
        }
        foreach ( $tags[0] as $tag )
        {
            preg_match_all( '/([a-z:-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $attrs, PREG_SET_ORDER );
            $values = array();
            foreach ( $attrs as $attr )
                $values[strtolower( $attr[1] )] = html_entity_decode( isset( $attr[4] ) && $attr[4] !== '' ? $attr[4] : $attr[3], ENT_QUOTES, 'UTF-8' );
            if ( !isset( $values['content'] ) )
                continue;
            foreach ( array( 'name', 'property', 'itemprop', 'http-equiv' ) as $key )
            {
                if ( !isset( $values[$key] ) )
                    continue;
                $name = strtolower( trim( $values[$key] ) );
                if ( !in_array( $name, $wanted, true ) || isset( $found[$name] ) )
                    continue;
                $content = trim( $values['content'] );
                try
                {
                    // a number is a Unix time in seconds, or in milliseconds (Confluence's confluence-request-time)
                    if ( ctype_digit( $content ) )
                        $time = self::validTime( strlen( $content ) >= 13 ? (int)floor( (int)$content / 1000 ) : (int)$content );
                    else
                    {
                        $date = new DateTime( $content, $zone );
                        $time = self::validTime( $date->getTimestamp() );
                    }
                }
                catch ( Exception $e )
                {
                    $time = null;
                }
                if ( $time !== null )
                    $found[$name] = $time;
            }
        }
        $ordered = array();
        foreach ( $wanted as $name )
            if ( isset( $found[$name] ) )
                $ordered[$name] = $found[$name];
        return $ordered;
    }
}
