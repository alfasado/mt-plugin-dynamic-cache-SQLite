<?php

class DynamicCacheSQLite extends MTPlugin {

    var $registry = array(
        'name' => 'DynamicCacheSQLite',
        'id'   => 'DynamicCacheSQLite',
        'key'  => 'dynamiccachesqlite',
        'author_name' => 'Alfasado Inc.',
        'author_link' => 'http://alfasado.net/',
        'version' => '0.3',
        'config_settings' => array(
            'DynamicCacheSQLite' => array( 'default' => 'DynamicMTML.sqlite' ),
            'DynamicCacheLifeTime' => array( 'default' => 3600 ),
            'DynamicCacheFileInfo' => array( 'default' => 1 ),
            'DynamicCacheConditional' => array( 'default' => 1 ),
            'DynamicCacheContent' => array( 'default' => 1 ),
            'DynamicCacheContentLifeTime' => array( 'default' => 300 ),
            'DynamicCacheTableName' => array( 'default' => 'session' ),
        ),
        'callbacks' => array(
            'init_app' => 'init_app',
            'pre_run' => 'pre_run',
            'take_down' => 'take_down',
            'pre_resolve_url' => 'pre_resolve_url',
            'post_resolve_url' => 'post_resolve_url',
        ),
    );

/*
key           TEXT(255 PRIMARY KEY)
value         MEDIUMBLOB
type          TEXT(25)
starttime     INTEGER
object_class  TEXT(25)
*/

    var $app;
    var $sqlite;
    var $lifetime;

    function init_app () {
        if ( $db = $this->app->config( 'DynamicCacheSQLite' ) ) {
            $this->sqlite = sqlite_open( $db, 0666, $error );
            $this->lifetime = $this->app->config( 'DynamicCacheLifeTime' );
            $this->app->stash( '__cache_sqlite', $this );
        }
    }

    function pre_run ( $mt, $ctx, $args ) {
        if ( $this->app->config( 'DynamicCacheConditional' ) ) {
            $file = $args[ 'file' ];
            if ( file_exists( $file ) ) {
                $filemtime = filemtime( $file );
                $this->app->do_conditional( $filemtime );
            }
        }
        if ( $this->app->config( 'DynamicCacheContent' ) ) {
            $url = $args[ 'url' ];
            $url = md5( $url );
            $lifetime = $this->app->config( 'DynamicCacheContentLifeTime' );
            if ( $content = $this->get( 'content_' . $url, $lifetime ) ) {
                $extension = $args[ 'extension' ];
                $contenttype = $this->app->get_mime_type( $extension );
                $this->app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                echo $content;
                exit();
            }
            $this->app->stash( '__cache_content', 'content_' . $url );
        }
    }

    function get ( $key, $expires = NULL ) {
        $table = $this->app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '"';
        $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
        if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
            $value = $rows[ 'value' ];
            $type = $rows[ 'type' ];
            $starttime = $rows[ 'starttime' ];
            if (! $expires ) {
                $expires = $this->lifetime;
            }
            if ( ( $starttime + $expires ) < time() ) {
                $this->clear( $key );
                return NULL;
            }
            if ( $type == 'SER' ) {
                $object_class = $rows[ 'object_class' ];
                if ( $object_class ) {
                    require_once( 'class.' . $object_class . '.php' );
                }
                return unserialize( $value );
            } else {
                return $value;
            }
        } else {
            return NULL;
        }
    }

    function put ( $key, $value ) {
        $type;
        $object_class;
        if ( is_array( $value ) ) {
            $type = 'SER';
        } elseif ( is_object( $value ) ) {
            $object_class = $value->_table;
            $type = 'SER';
        } else {
            $type = 'RAW';
        }
        if ( $type == 'SER' ) {
            $value = serialize( $value );
        }
        $result_flag = NULL;
        $table = $this->app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '"';
        $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
        if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
            $sql = sprintf( "UPDATE ${table} SET value = '%s', starttime = '%s', type = '%s', object_class = '%s' WHERE key = '%s'",
                                                    sqlite_escape_string( $value ), time(), $type, $object_class, $key );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        } else {
            $sql = sprintf( "INSERT INTO ${table} (key, value, starttime, type, object_class) VALUES ('%s', '%s', '%s', '%s', '%s')",
                                                    $key, sqlite_escape_string( $value ), time(), $type, $object_class );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        }
        return $result_flag;
    }

    function clear ( $key ) {
        $table = $this->app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '"';
        $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
        $result_flag = NULL;
        if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
            $sql = 'DELETE FROM ' . $table . ' WHERE key="' . $key . '"';
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        }
        return $result_flag;
    }

    function pre_resolve_url ( $mt, $ctx, $args ) {
        $app = $ctx->stash( 'bootstrapper' );
        if ( $app->config( 'DynamicCacheFileInfo' ) ) {
            $file = $app->stash( 'file' );
            $file = md5( $file );
            $key = 'fileinfo_' . $file;
            $app->stash( '__cached_fileinfo_key', $key );
            if ( $data = $this->get( $key ) ) {
                $app->stash( 'fileinfo', $data );
                $app->stash( '__cached_fileinfo', 1 );
            }
        }
    }

    function post_resolve_url ( $mt, $ctx, $args ) {
        $app = $ctx->stash( 'bootstrapper' );
        if ( $app->config( 'DynamicCacheFileInfo' ) ) {
            if (! $app->stash( '__cached_fileinfo' ) ) {
                $data = $app->stash( 'fileinfo' );
                if ( $data ) {
                    $key = $app->stash( '__cached_fileinfo_key' );
                    $this->put( $key, $data );
                }
            }
        }
    }

    function take_down ( $mt, $ctx, $args, $content ) {
        $app = $ctx->stash( 'bootstrapper' );
        if ( $key = $app->stash( '__cache_content' ) ) {
            $this->put( $key, $content );
        }
        sqlite_close( $this->sqlite );
    }
}

?>