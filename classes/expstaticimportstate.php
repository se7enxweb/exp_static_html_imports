<?php
/**
 * File containing the expStaticImportState class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The memory of an import type in this installation, a JSON file in its work folder
 * (var/<site>/static-import/<type>/state.json):
 *
 *   pages   source path => object_id, node_id, source_hash (of what the source said), written_hash (of what the import
 *           wrote), status (created, updated, unchanged, flagged, failed, duplicate, removed), duplicate_of
 *   assets  source path => object_id, node_id, hash
 *   run     the run in progress: id, phase, position, started, options; cleared when a run finishes
 *
 * Written after every batch (atomically), so an interrupted run resumes where it stopped.
 */
class expStaticImportState
{
    /** @var string */
    protected $file;

    /** @var array */
    public $data = array( 'pages' => array(), 'assets' => array(), 'run' => null, 'runs' => array() );

    /**
     * @param string $file
     */
    public function __construct( $file )
    {
        $this->file = $file;
        if ( is_file( $file ) )
        {
            $data = json_decode( (string)file_get_contents( $file ), true );
            if ( is_array( $data ) )
                $this->data = array_merge( $this->data, $data );
        }
    }

    public function save()
    {
        $dir = dirname( $this->file );
        if ( !is_dir( $dir ) )
            mkdir( $dir, 0775, true );
        $tmp = $this->file . '.' . getmypid() . '.tmp';
        file_put_contents( $tmp, json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        rename( $tmp, $this->file );
    }

    /**
     * @param string $path
     * @return array|null
     */
    public function page( $path )
    {
        return isset( $this->data['pages'][$path] ) ? $this->data['pages'][$path] : null;
    }

    /**
     * @param string $path
     * @param array $values merged into the page's entry
     */
    public function setPage( $path, array $values )
    {
        $this->data['pages'][$path] = array_merge( isset( $this->data['pages'][$path] ) ? $this->data['pages'][$path] : array(), $values );
    }

    /**
     * @param string $path
     * @return array|null
     */
    public function asset( $path )
    {
        return isset( $this->data['assets'][$path] ) ? $this->data['assets'][$path] : null;
    }

    public function setAsset( $path, array $values )
    {
        $this->data['assets'][$path] = array_merge( isset( $this->data['assets'][$path] ) ? $this->data['assets'][$path] : array(), $values );
    }

    /**
     * @param string $path
     * @return int the node of a page or asset already imported, 0 when none
     */
    public function nodeOf( $path )
    {
        if ( isset( $this->data['pages'][$path]['duplicate_of'] ) && $this->data['pages'][$path]['duplicate_of'] !== '' )
            $path = $this->data['pages'][$path]['duplicate_of'];
        if ( !empty( $this->data['pages'][$path]['node_id'] ) )
            return (int)$this->data['pages'][$path]['node_id'];
        if ( !empty( $this->data['assets'][$path]['node_id'] ) )
            return (int)$this->data['assets'][$path]['node_id'];
        return 0;
    }

    /**
     * @param array $summary a finished run's summary, kept in the history (the last 50)
     */
    public function addRun( array $summary )
    {
        array_unshift( $this->data['runs'], $summary );
        $this->data['runs'] = array_slice( $this->data['runs'], 0, 50 );
    }
}
