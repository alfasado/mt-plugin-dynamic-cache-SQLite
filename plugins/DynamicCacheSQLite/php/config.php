<?php

class DynamicCacheSQLite extends MTPlugin {

    var $registry = array(
        'name' => 'DynamicCacheSQLite',
        'id'   => 'DynamicCacheSQLite',
        'key'  => 'dynamiccachesqlite',
        'author_name' => 'Alfasado Inc.',
        'author_link' => 'http://alfasado.net/',
        'version' => '0.8',
        'config_settings' => array(
            'DynamicCacheSQLite' => array( 'default' => 'DynamicMTML.sqlite' ),
            'DynamicCacheLifeTime' => array( 'default' => 3600 ),
            'DynamicCacheFileInfo' => array( 'default' => 1 ),
            'DynamicCacheConditional' => array( 'default' => 0 ),
            'DynamicCacheContent' => array( 'default' => 0 ),
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

TODO::MultiDevice, with Parameter
*/

    var $app;
    var $sqlite;
    var $lifetime;

    function init_app () {
        if ( $db = $this->app->config( 'DynamicCacheSQLite' ) ) {
            $create;
            if (! file_exists( $db ) ) {
                $create = 1;
            }
            if ( $conn = sqlite_open( $db, 0666, $error ) ) {
                if ( $create ) {
                    $table = $this->app->config( 'DynamicCacheTableName' );
                    $sql = "CREATE table ${table} (key TEXT(255) PRIMARY KEY,";
                    $sql .= " value MEDIUMBLOB, type TEXT(25), starttime INTEGER, object_class TEXT(25))";
                    $result_flag = sqlite_query( $conn, $sql, SQLITE_BOTH, $error );
                    if ( $error ) {
                        return;
                    }
                }
                $this->sqlite = $conn;
                $this->lifetime = $this->app->config( 'DynamicCacheLifeTime' );
                $this->app->stash( '__cache_sqlite', $this );
                /*
                $this->clear( 'blog_1' );
                $this->clear( 'fileinfo_a92e923d40abf7b3a6d6e1fed33f899a' );
                $this->clear( 'content_9ba37e0a8d5b7dc333c8ae0822e9d22e' );
                sqlite_query( $this->sqlite, 'COMMIT' );
                exit();
                */
            }
        }
    }

    function pre_run ( $mt, $ctx, $args ) {
        if (! $this->sqlite ) {
            return;
        }
        $filemtime;
        $file = $args[ 'file' ];
        if ( file_exists( $file ) ) {
            $filemtime = filemtime( $file );
            if ( $this->app->config( 'DynamicCacheConditional' ) ) {
                $this->app->do_conditional( $filemtime );
            }
        }
        if ( $this->app->config( 'DynamicCacheContent' ) ) {
            $url = $args[ 'url' ];
            $url = md5( $url );
            $lifetime = $this->app->config( 'DynamicCacheContentLifeTime' );
            $key = 'content_' . $url;
            $rows = $this->get( $key, $lifetime, 1 );
            if ( $rows ) {
                $starttime = $rows[ 'starttime' ];
                if ( ( $filemtime ) && ( $filemtime > $starttime ) ) {
                    $this->clear( $key );
                } else {
                    $content = $rows[ 'value' ];
                    $extension = $args[ 'extension' ];
                    $contenttype = $this->app->get_mime_type( $extension );
                    $this->app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                    echo $content;
                    exit();
                }
            }
            $this->app->stash( '__cache_content', 'content_' . $url );
        }
    }

    function get ( $key, $expires = NULL, $wantarray = NULL ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        $table = $this->app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        $sql_key = md5( $sql );
        if ( $rows = $this->app->stash( '__sqlite_cache_' . $sql_key ) ) {
            $value = $rows[ 'value' ];
            $type = $rows[ 'type' ];
            if ( $type == 'SER' ) {
                $object_class = $rows[ 'object_class' ];
                if ( $object_class ) {
                    require_once( 'class.' . $object_class . '.php' );
                }
                $value = unserialize( $value );
            }
            if ( $wantarray ) {
                $rows[ 'value' ] = $value;
                return $rows;
            } else {
                return $value;
            }
        } else {
            $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
            if (! $result ) {
                return NULL;
            }
            if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
                $this->app->stash( '__sqlite_cache_' . $sql_key, $rows );
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
                    $value = unserialize( $value );
                }
                if ( $wantarray ) {
                    $rows[ 'value' ] = $value;
                    return $rows;
                } else {
                    return $value;
                }
            } else {
                return NULL;
            }
        }
    }

    function put ( $key, $value ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        if (! $this->app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'BEGIN' );
        }
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
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        $sql_key = md5( $sql );
        $rows;
        if ( $rows = $this->app->stash( '__sqlite_cache_' . $sql_key ) ) {
        } else {
            if ( $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error ) ) {
                $rows = sqlite_fetch_array( $result, SQLITE_ASSOC );
                $this->app->stash( '__sqlite_cache_' . $sql_key, $rows );
            }
        }
        if ( $rows ) {
            $sql = sprintf( "UPDATE ${table} SET value = '%s', starttime = '%s', type = '%s', object_class = '%s' WHERE key = '%s'",
                                                    sqlite_escape_string( $value ), time(), $type, $object_class, $key );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        } else {
            $sql = sprintf( "INSERT INTO ${table} (key, value, starttime, type, object_class) VALUES ('%s', '%s', '%s', '%s', '%s')",
                                                    $key, sqlite_escape_string( $value ), time(), $type, $object_class );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        }
        if (! $result_flag ) {
            sqlite_query( $this->sqlite, 'ROLLBACK' );
            return NULL;
        } else {
            $this->app->stash( '__sqlite_update', 1 );
        }
        return $result_flag;
    }

    function clear ( $key ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        if (! $this->app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'BEGIN' );
        }
        $table = $this->app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        $sql_md5 = md5( $sql );
        $result;
        if ( $result = $this->app->stash( '__sqlite_query_cache_' . $sql_md5 ) ) {
        } else {
            $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
        }
        if (! $result ) {
            $this->app->stash( '__sqlite_query_cache_' . $sql_md5, NULL );
            return NULL;
        }
        $this->app->stash( '__sqlite_query_cache_' . $sql_md5, NULL );
        $result_flag = NULL;
        if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
            $sql = 'DELETE FROM ' . $table . ' WHERE key="' . $key . '"';
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
            if (! $result_flag ) {
                sqlite_query( $this->sqlite, 'ROLLBACK' );
            } else {
                $this->app->stash( '__sqlite_update', 1 );
            }
        }
        return $result_flag;
    }

    function pre_resolve_url ( $mt, $ctx, $args ) {
        if (! $this->sqlite ) {
            return;
        }
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
        if (! $this->sqlite ) {
            return;
        }
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
        if (! $this->sqlite ) {
            return;
        }
        $app = $ctx->stash( 'bootstrapper' );
        if ( $key = $app->stash( '__cache_content' ) ) {
            $this->put( $key, $content );
        }
        if ( $this->app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'COMMIT' );
        }
        sqlite_close( $this->sqlite );
    }
}

?>