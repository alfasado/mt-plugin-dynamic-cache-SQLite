<?php

class DynamicCacheSQLite extends MTPlugin {

    var $registry = array(
        'name' => 'DynamicCacheSQLite',
        'id'   => 'DynamicCacheSQLite',
        'key'  => 'dynamiccachesqlite',
        'author_name' => 'Alfasado Inc.',
        'author_link' => 'http://alfasado.net/',
        'version' => '1.0',
        'config_settings' => array(
            'DynamicCacheSQLite' => array( 'default' => 'DynamicMTML.sqlite' ),
            'DynamicCacheLifeTime' => array( 'default' => 10800 ),
            'DynamicCacheFileInfo' => array( 'default' => 1 ),
            'DynamicCacheConditional' => array( 'default' => 1 ),
            'DynamicCacheContent' => array( 'default' => 1 ),
            'DynamicCacheContentLifeTime' => array( 'default' => 180 ),
            'DynamicCacheTableName' => array( 'default' => 'session' ),
            'DynamicCacheDebugMode' => array( 'default' => 0 ),
            'DynamicCacheIfNonMatchFI' => array( 'default' => 'index.html' ),
        ),
        'callbacks' => array(
            'init_app' => 'init_app',
            'pre_run' => 'pre_run',
            'take_down' => 'take_down',
            'pre_resolve_url' => 'pre_resolve_url',
            'post_resolve_url' => 'post_resolve_url',
        ),
    );

    var $app;
    var $sqlite;
    var $lifetime;
    var $debug;

    function init_app () {
        $app = $this->app;
        if ( $app->config( 'DynamicCacheDebugMode' ) ) {
            $this->debug = 1;
        }
        if ( $db = $app->config( 'DynamicCacheSQLite' ) ) {
            $app->stash( '__sqlite_key', $key );
            $app->stash( '__sqlite_expires', $expires );
            $app->stash( '__sqlite_do', 1 );
            $app->run_callbacks( 'pre_sqlite_init' );
            $do = $app->stash( '__sqlite_do' );
            $app->stash( '__sqlite_key', NULL );
            $app->stash( '__sqlite_expires', NULL );
            $app->stash( '__sqlite_do', NULL );
            if (! $do ) {
                return NULL;
            }
            $create;
            if (! file_exists( $db ) ) {
                $create = 1;
            }
            if ( $conn = sqlite_open( $db, 0666, $error ) ) {
                if ( $create ) {
                    $table = $app->config( 'DynamicCacheTableName' );
                    $sql = "CREATE table ${table} (key TEXT(255) PRIMARY KEY,";
                    $sql .= " value MEDIUMBLOB, type TEXT(25), starttime INTEGER, object_class TEXT(25))";
                    $result_flag = sqlite_query( $conn, $sql, SQLITE_BOTH, $error );
                    if ( $error ) {
                        return;
                    }
                }
                $this->sqlite = $conn;
                if ( $this->debug ) {
                    $sql = "SELECT * FROM 'session' LIMIT 0, 1000;";
                    $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
                    while ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
                        echo( $rows[ 'key' ] . "\t|\t" . $rows[ 'object_class' ] ) . "\t|\t";
                        echo $rows[ 'type' ] .  "\t|\t" . $rows[ 'starttime' ] . '<br />';
                    }
                    echo  '------------------------<br />';
                }
                $this->lifetime = $app->config( 'DynamicCacheLifeTime' );
                $app->stash( '__cache_sqlite', $this );
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
        $app = $this->app;
        $filemtime;
        $file = $args[ 'file' ];
        if ( file_exists( $file ) ) {
            $filemtime = filemtime( $file );
            if ( $app->config( 'DynamicCacheConditional' ) ) {
                $app->do_conditional( $filemtime );
            }
        }
        if ( $app->config( 'DynamicCacheContent' ) ) {
            $url = $args[ 'url' ];
            $url = md5( $url );
            $lifetime = $app->config( 'DynamicCacheContentLifeTime' );
            $key = 'content_' . $url;
            $rows = $this->get( $key, $lifetime, 1 );
            if ( $rows ) {
                $starttime = $rows[ 'starttime' ];
                if ( ( $filemtime ) && ( $filemtime > $starttime ) ) {
                    $this->clear( $key );
                } else {
                    $content = $rows[ 'value' ];
                    $extension = $args[ 'extension' ];
                    $contenttype = $app->get_mime_type( $extension );
                    $app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                    echo $content;
                    exit();
                }
            }
            $app->stash( '__cache_content', 'content_' . $url );
        }
    }

    function get ( $key, $expires = NULL, $wantarray = NULL ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        if ( $this->debug ) {
            echo  '<br />------------------------<br />';
            echo "get : ${key} : ";
        }
        $app = $this->app;
        $app->stash( '__sqlite_key', $key );
        $app->stash( '__sqlite_expires', $expires );
        $app->stash( '__sqlite_do', 1 );
        $app->run_callbacks( 'pre_sqlite_get' );
        $do = $app->stash( '__sqlite_do' );
        $key = $app->stash( '__sqlite_key' );
        $expires = $app->stash( '__sqlite_expires' );
        $app->stash( '__sqlite_key', NULL );
        $app->stash( '__sqlite_expires', NULL );
        $app->stash( '__sqlite_do', NULL );
        if (! $do ) {
            return NULL;
        }
        $table = $app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        if ( $this->debug ) {
            echo  "<br />${sql}<br />";
        } 
        $sql_key = md5( $sql );
        if ( $rows = $app->stash( '__sqlite_cache_' . $sql_key ) ) {
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
                $app->stash( '__sqlite_cache_' . $sql_key, $rows );
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
                if ( $this->debug ) {
                    echo "success";
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
        $app = $this->app;
        $app->stash( '__sqlite_key', $key );
        $app->stash( '__sqlite_expires', $expires );
        $app->stash( '__sqlite_do', 1 );
        $app->run_callbacks( 'pre_sqlite_put' );
        $do = $app->stash( '__sqlite_do' );
        $key = $app->stash( '__sqlite_key' );
        $expires = $app->stash( '__sqlite_expires' );
        $app->stash( '__sqlite_key', NULL );
        $app->stash( '__sqlite_expires', NULL );
        $app->stash( '__sqlite_do', NULL );
        if (! $do ) {
            return NULL;
        }
        if ( $this->debug ) {
            echo  '<br />------------------------<br />';
            echo "put : ${key} : ";
        }
        if (! $app->stash( '__sqlite_update' ) ) {
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
        $table = $app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        $sql_key = md5( $sql );
        $rows;
        if ( $rows = $app->stash( '__sqlite_cache_' . $sql_key ) ) {
            if ( $app->stash( '__sqlite_delete_' . $sql_key ) ) {
                $rows = NULL;
            }
        } else {
            if ( $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error ) ) {
                $rows = sqlite_fetch_array( $result, SQLITE_ASSOC );
                $app->stash( '__sqlite_cache_' . $sql_key, $rows );
            }
        }
        if ( $rows ) {
            $sql = sprintf( "UPDATE ${table} SET value = '%s', starttime = '%s', type = '%s', object_class = '%s' WHERE key = '%s'",
                                                    sqlite_escape_string( $value ), time(), $type, $object_class, $key );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        } else {
            $sql = sprintf( "INSERT INTO ${table} (key, value, starttime, type, object_class) VALUES ('%s', '%s', '%s', '%s', '%s')",
                                                    $key, sqlite_escape_string( $value ), time(), $type, $object_class );
            $app->stash( '__sqlite_delete_' . $sql_key, NULL );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        }
        if (! $result_flag ) {
            sqlite_query( $this->sqlite, 'ROLLBACK' );
            return NULL;
        } else {
            if ( $this->debug ) {
                echo 'success';
            }
            $app->stash( '__sqlite_update', 1 );
        }
        return $result_flag;
    }

    function clear ( $key ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        $app = $this->app;
        $args = $app->get_args();
        $app->stash( '__sqlite_key', $key );
        $app->stash( '__sqlite_expires', $expires );
        $app->stash( '__sqlite_do', 1 );
        $app->run_callbacks( 'pre_sqlite_clear' );
        $do = $app->stash( '__sqlite_do' );
        $key = $app->stash( '__sqlite_key' );
        $expires = $app->stash( '__sqlite_expires' );
        $app->stash( '__sqlite_key', NULL );
        $app->stash( '__sqlite_expires', NULL );
        $app->stash( '__sqlite_do', NULL );
        if (! $do ) {
            return NULL;
        }
        if ( $this->debug ) {
            echo  '<br />------------------------<br />';
            echo "clear : ${key} : ";
        }
        if (! $app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'BEGIN' );
        }
        $table = $app->config( 'DynamicCacheTableName' );
        $sql = 'SELECT * FROM ' . $table . ' WHERE key="' . $key . '" LIMIT 1';
        $sql_key = md5( $sql );
        $result;
        if ( $result = $app->stash( '__sqlite_query_cache_' . $sql_key ) ) {
        } else {
            $result = sqlite_query( $this->sqlite, $sql, SQLITE_BOTH, $error );
        }
        $app->stash( '__sqlite_query_cache_' . $sql_key, NULL );
        if (! $result ) {
            $app->stash( '__sqlite_query_cache_' . $sql_key, '' );
            return NULL;
        }
        $app->stash( '__sqlite_query_cache_' . $sql_key, '' );
        $result_flag = NULL;
        if ( $rows = sqlite_fetch_array( $result, SQLITE_ASSOC ) ) {
            $sql = 'DELETE FROM ' . $table . ' WHERE key="' . $key . '"';
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
            if (! $result_flag ) {
                sqlite_query( $this->sqlite, 'ROLLBACK' );
            } else {
                $app->stash( '__sqlite_update', 1 );
                if ( $this->debug ) {
                    echo "success";
                }
            }
            $app->stash( '__sqlite_delete_' . $sql_key, 1 );
        }
        return $result_flag;
    }

    function pre_resolve_url ( $mt, $ctx, $args ) {
        if (! $this->sqlite ) {
            return;
        }
        $app = $this->app;
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
        $app = $this->app;
        if ( $app->config( 'DynamicCacheFileInfo' ) ) {
            if (! $app->stash( '__cached_fileinfo' ) ) {
                $data = $app->stash( 'fileinfo' );
                $data = '';
                $key = $app->stash( '__cached_fileinfo_key' );
                if ( $data ) {
                    $this->put( $key, $data );
                } else {
                    if ( $path = $app->config( 'DynamicCacheIfNonMatchFI' ) ) {
                        if ( $blog = $ctx->stash( 'blog' ) ) {
                            $site_path = $blog->site_path;
                            $site_path = preg_replace( '!/!', DIRECTORY_SEPARATOR, $site_path );
                            $site_path = preg_replace( '!/$!', '', $site_path );
                            $file = $site_path . DIRECTORY_SEPARATOR . $path;
                            $new_key = 'fileinfo_' . md5( $file );
                            if ( $data = $this->get( $new_key ) ) {
                                $this->put( $key, $data );
                            }
                        }
                    }
                }
            }
        }
        if ( $this->debug ) {
            echo  '<br />------------------------<br />';
        }
    }

    function take_down ( $mt, $ctx, $args, $content ) {
        if (! $this->sqlite ) {
            return;
        }
        $app = $this->app;
        if ( $key = $app->stash( '__cache_content' ) ) {
            $this->put( $key, $content );
        }
        if ( $app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'COMMIT' );
        }
        sqlite_close( $this->sqlite );
    }
}

?>