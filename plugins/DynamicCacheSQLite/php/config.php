<?php

class DynamicCacheSQLite extends MTPlugin {

    var $registry = array(
        'name' => 'DynamicCacheSQLite',
        'id'   => 'DynamicCacheSQLite',
        'key'  => 'dynamiccachesqlite',
        'author_name' => 'Alfasado Inc.',
        'author_link' => 'http://alfasado.net/',
        'version' => '0.1',
        'config_settings' => array( // mt-config.cgi
            'DynamicCacheSQLite' => array( 'default' => 'DynamicMTML.sqlite' ),
            'DynamicCacheLifeTime' => array( 'default' => 3600 ),
            'DynamicCacheFileInfo' => array( 'default' => 1 ),
            'DynamicCacheTableName' => array( 'default' => 'session' ),
        ),
        'callbacks' => array(
            'init_app' => 'init_app',
            'take_down' => 'take_down',
            'pre_resolve_url' => 'pre_resolve_url',
            'post_resolve_url' => 'post_resolve_url',
        ),
    );

/*
key           TEXT(255 primary key with index)
value         MEDIUMTEXT(16777215)
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
            if ( $data = $this->get( 'fileinfo_' . $file ) ) {
                $app->stash( 'fileinfo', $data );
            }
        }
    }

    function post_resolve_url ( $mt, $ctx, $args ) {
        $app = $ctx->stash( 'bootstrapper' );
        if ( $app->config( 'DynamicCacheFileInfo' ) ) {
            $data = $app->stash( 'fileinfo' );
            if ( $data ) {
                $file = $app->stash( 'file' );
                $file = md5( $file );
                $this->put( 'fileinfo_' . $file, $data );
            }
        }
    }

    function take_down ( $mt, $ctx, $args, $content ) {
        sqlite_close( $this->sqlite );
    }
}

?>