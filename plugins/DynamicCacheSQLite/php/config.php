<?php

class DynamicCacheSQLite extends MTPlugin {

    var $registry = array(
        'name' => 'DynamicCacheSQLite',
        'id'   => 'DynamicCacheSQLite',
        'key'  => 'dynamiccachesqlite',
        'author_name' => 'Alfasado Inc.',
        'author_link' => 'http://alfasado.net/',
        'version' => '1.01',
        'config_settings' => array(
            'DynamicCacheSQLite' => array( 'default' => '/path/to/DynamicMTML.sqlite' ),
            'DynamicCacheLifeTime' => array( 'default' => 14400 ),
            'DynamicCacheFileInfo' => array( 'default' => 1 ),
            'DynamicCacheBlog' => array( 'default' => 1 ),
            'DynamicCacheArchiveObjects' => array( 'default' => '' ),
            'DynamicCacheArchiveObjectLifeTime' => array( 'default' => 7200 ),
            'DynamicCacheConditional' => array( 'default' => 1 ),
            'DynamicCacheContent' => array( 'default' => 1 ),
            'DynamicCacheContentLifeTime' => array( 'default' => 3600 ),
            'DynamicCacheTableName' => array( 'default' => 'session' ),
            'DynamicCacheDebugMode' => array( 'default' => 0 ),
            'DynamicCacheIfNonMatchFI' => array( 'default' => 'index.html' ),
            'DynamicCacheVacuumPeriod' => array( 'default' => 0 ),
            'DynamicCacheVacuumCheckFile' => array( 'default' => '/path/to/CheckFile' ),
        ),
        'callbacks' => array(
            'init_app' => 'init_app',
            'pre_run' => 'pre_run',
            'init_db' => 'init_db',
            'take_down' => 'take_down',
            'pre_resolve_url' => 'pre_resolve_url',
            'post_resolve_url' => 'post_resolve_url',
            'build_page' => 'build_page',
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
            $app->stash( '__sqlite_do', 1 );
            $app->run_callbacks( 'pre_sqlite_init' );
            $do = $app->stash( '__sqlite_do' );
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
                    $sql .= " value MEDIUMBLOB, template MEDIUMBLOB, type TEXT(25),";
                    $sql .= " is_file INTEGER, file_ts INTEGER, starttime INTEGER,";
                    $sql .= " object_class TEXT(25))";
                    $result_flag = sqlite_query( $conn, $sql, SQLITE_BOTH, $error );
                    if ( $error ) {
                        return;
                    }
                } else {
                    if ( $period = $app->config( 'DynamicCacheVacuumPeriod' ) ) {
                        $check = $app->config( 'DynamicCacheVacuumCheckFile' );
                        if (! file_exists( $check ) ) {
                            touch( $check );
                        }
                        if ( file_exists( $check ) ) {
                            $checkmtime = filemtime( $check );
                            if ( ( $checkmtime + $period ) > time() ) {
                                touch( $check );
                                $result_flag = sqlite_query( $conn, 'VACUUM', SQLITE_BOTH, $error );
                                if ( $error ) {
                                    return;
                                }
                                sqlite_close( $conn );
                                $conn = sqlite_open( $db, 0666, $error );
                            }
                        }
                    }
                }
                $this->sqlite = $conn;
                if ( $this->debug ) {
                    $sql = "SELECT * FROM '${table}' LIMIT 0, 50;";
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
                sqlite_query( $conn, 'COMMIT' );
                sqlite_close( $conn );
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
            $app->stash( '__file_exists', 1 );
            $filemtime = filemtime( $file );
            $app->stash( '__file_filemtime', $filemtime );
            if ( $app->config( 'DynamicCacheConditional' ) ) {
                $this->do_conditional( $filemtime );
            }
        }
        if ( $app->config( 'DynamicCacheContent' ) ) {
            $url = $args[ 'url' ];
            $url = md5( $url );
            $lifetime = $app->config( 'DynamicCacheContentLifeTime' );
            $key = 'content_' . $url;
            $rows = $this->get( $key, array( 'wantarray' => 1, 'expires' => $lifetime ) );
            if ( $rows ) {
                $starttime = $rows[ 'starttime' ];
                if ( ( $filemtime ) && ( $filemtime > $starttime ) ) {
                    $this->clear( $key );
                } else {
                    if ( $filemtime ) {
                        if ( $app->config( 'DynamicCacheConditional' ) ) {
                            $this->do_conditional( $filemtime );
                        }
                    }
                    $content = $rows[ 'value' ];
                    $extension = $args[ 'extension' ];
                    $contenttype = $app->get_mime_type( $extension );
                    $app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                    echo $content;
                    sqlite_close( $this->sqlite );
                    exit();
                }
            }
            $app->stash( '__cache_content', 'content_' . $url );
        }
    }

    function init_db ( $mt, &$ctx, $args ) {
        $app = $this->app;
        if ( $app->config( 'DynamicCacheBlog' ) ) {
            if ( $blog_id = $args[ 'blog_id' ] ) {
                $blog_key = 'blog_' . $blog_id;
                if (! $blog = $this->get( $blog_key ) ) {
                    $blog = $mt->db()->fetch_blog( $blog_id );
                    $this->put( $blog_key, $blog );
                } else {
                    $ctx->stash( 'blog', $blog );
                }
            }
        }
    }

    function get ( $key, $args = NULL, $wantarray = NULL ) {
        if ( is_array( $args ) && isset( $args[ 'expires' ] ) ) {
            $expires = $args[ 'expires' ];
        }
        if ( is_array( $args ) && isset( $args[ 'wantarray' ] ) ) {
            $wantarray = $args[ 'wantarray' ];
        }
        if (! is_array( $args ) ) {
            $expires = $args;
        }
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
            $template = $rows[ 'template' ];
            $type = $rows[ 'type' ];
            if ( $type == 'SER' ) {
                $object_class = $rows[ 'object_class' ];
                if ( $object_class ) {
                    require_once( 'class.' . $object_class . '.php' );
                }
                $value = unserialize( $value );
                if ( $template ) {
                    require_once( 'class.mt_template.php' );
                    $template = unserialize( $template );
                }
            }
            if ( $wantarray ) {
                $rows[ 'value' ] = $value;
                $rows[ 'template' ] = $template;
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
                $template = $rows[ 'template' ];
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
                    if ( $template ) {
                        require_once( 'class.mt_template.php' );
                        $template = unserialize( $template );
                    }
                }
                if ( $this->debug ) {
                    echo "success";
                }
                if ( $wantarray ) {
                    $rows[ 'value' ] = $value;
                    $rows[ 'template' ] = $template;
                    return $rows;
                } else {
                    return $value;
                }
            } else {
                return NULL;
            }
        }
    }

    function put ( $key, $value, $template = NULL ) {
        if (! $this->sqlite ) {
            return NULL;
        }
        $app = $this->app;
        $pos = strpos( $key, 'fileinfo_' );
        if ( $pos !== FALSE ) {
            $is_file = $app->stash( '__file_exists' );
            $file_ts = $app->stash( '__file_filemtime' );
        } else {
            $is_file = 0;
        }
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
            if ( $template ) {
                $template = serialize( $template );
            }
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
            $sql = sprintf( "UPDATE ${table} SET value = '%s', template = %s, is_file = ${is_file}, file_ts = ${file_ts}, starttime = '%s', type = '%s', object_class = '%s' WHERE key = '%s'",
            sqlite_escape_string( $value ), sqlite_escape_string( $template ), time(), $type, $object_class, $key );
            $result_flag = sqlite_exec( $this->sqlite, $sql, $error );
        } else {
            $sql = sprintf( "INSERT INTO ${table} (key, value, template, is_file, file_ts, starttime, type, object_class) VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')",
            $key, sqlite_escape_string( $value ), sqlite_escape_string( $template ), $is_file, $file_ts, time(), $type, $object_class );
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
            if ( $fileinfo = $this->get( $key, array( 'wantarray' => 1 ) ) ) {
                $is_file = $fileinfo[ 'is_file' ];
                if ( $is_file && (! $app->stash( '__file_exists' ) ) ) {
                    $this->clear( $key );
                    return;
                }
                $file_ts = $fileinfo[ 'file_ts' ];
                $filemtime = $app->stash( '__file_filemtime' );
                if ( $file_ts && $filemtime ) {
                    if ( $file_ts != $filemtime ) {
                        $this->clear( $key );
                        return;
                    }
                }
                $data = $fileinfo[ 'value' ];
                $template = $fileinfo[ 'template' ];
                if ( $template ) {
                    $ctx->stash( 'template', $template );
                }
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
        $data = $app->stash( 'fileinfo' );
        if ( $app->config( 'DynamicCacheFileInfo' ) ) {
            if (! $app->stash( '__cached_fileinfo' ) ) {
                $key = $app->stash( '__cached_fileinfo_key' );
                if ( $data ) {
                    $template;
                    if ( $template_id = $data->template_id ) {
                        require_once( 'class.mt_template.php' );
                        $template = new Template;
                        $template->Load( "template_id = $template_id" );
                    }
                    $this->put( $key, $data, $template );
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
        if ( $data ) {
            if ( $archive_obj = $app->config( 'DynamicCacheArchiveObjects' ) ) {
                $obj_lifetime = $app->config( 'DynamicCacheArchiveObjectLifeTime' );
                $archive_objs = explode( ',', $archive_obj );
                $app->stash( '__cache_archive_objs', $archive_objs );
                if ( preg_grep( "/^entry$/", $archive_objs ) || preg_grep( "/^page$/", $archive_objs ) ) {
                    if ( $entry_id = $data->entry_id ) {
                        if ( $entry = $this->get( 'entry_' . $entry_id, $obj_lifetime ) ) {
                            $ctx->stash( 'entry', $entry );
                        } else {
                            $app->stash( '__cache_entry', 1 );
                        }
                    }
                }
                if ( preg_grep( "/^category$/", $archive_objs ) ) {
                    if ( $category_id = $data->category_id ) {
                        if ( $category = $this->get( 'category_' . $category_id, $obj_lifetime ) ) {
                            $ctx->stash( 'category', $category );
                        } else {
                            $app->stash( '__cache_category', 1 );
                        }
                    }
                }
                if (! $app->user() ) {
                    if ( preg_grep( "/^author$/", $archive_objs ) ) {
                        if ( $author_id = $data->author_id ) {
                            if ( $author = $this->get( 'author_' . $author_id, $obj_lifetime ) ) {
                                $ctx->stash( 'author', $author );
                            } else {
                                $app->stash( '__cache_author', 1 );
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

    function build_page ( $mt, $ctx, $args, &$content ) {
        if ( $this->debug ) {
            $content = '';
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
        if ( $app->stash( '__cache_entry' ) ) {
            if ( $entry = $ctx->stash( 'entry' ) ) {
                $this->put( 'entry_' . $entry->id, $entry );
            }
        }
        if ( $app->stash( '__cache_category' ) ) {
            if ( $entry = $ctx->stash( 'category' ) ) {
                $this->put( 'category_' . $category->id, $category );
            }
        }
        if ( $app->stash( '__cache_author' ) ) {
            if (! $app->user() ) {
                if ( $author = $ctx->stash( 'author' ) ) {
                    $this->put( 'author_' . $author->id, $author );
                }
            }
        }
        if ( $app->stash( '__sqlite_update' ) ) {
            sqlite_query( $this->sqlite, 'COMMIT' );
        }
        sqlite_close( $this->sqlite );
    }

    function do_conditional ( $ts ) {
        $app = $this->app;
        if ( $app->request_method == 'POST' ) {
            return;
        }
        if ( $mode = $app->mode() ) {
            if ( $mode == 'logout' ) {
                return;
            }
        }
        $if_modified  = isset( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] )
                        ? strtotime( stripslashes( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) ) : FALSE;
        $if_nonematch = isset( $_SERVER[ 'HTTP_IF_NONE_MATCH' ] )
                        ? stripslashes( $_SERVER[ 'HTTP_IF_NONE_MATCH' ] ) : FALSE;
        $conditional;
        $last_modified = gmdate( "D, d M Y H:i:s", $ts ) . ' GMT';
        $etag = '"' . md5( $last_modified ) . '"';
        if ( $if_nonematch && ( $if_nonematch == $etag ) ) {
        } else {
            return;
        }
        if ( $if_modified && ( $if_modified >= $ts ) ) {
        } else {
            return;
        }
        header( "Last-Modified: $last_modified" );
        header( "ETag: $etag" );
        header( $app->protocol . ' 304 Not Modified' );
        sqlite_close( $this->sqlite );
        exit();
    }

}

?>