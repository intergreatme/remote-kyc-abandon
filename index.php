<?php
    /**
	* Remote KYC Abandon - A utility to signal when users have abandoned the Remote-KYC process.
	*
	* @author    James Lawson
	* @copyright 2019 IGM www.intergreatme.com
	* @note      This program is distributed in the hope that it will be useful - WITHOUT
	* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
	* FITNESS FOR A PARTICULAR PURPOSE.
    */
    date_default_timezone_set('Africa/Johannesburg');
    header('Content-type: text');

    
    if(empty($_GET)) {
        Usage('No HTTP GET parameters');
    }

    if(!isset($_GET['action'])) {
        Usage('No action was associated with this request');
    }

    /* CONFIGURATION ZONE 
    ** Please alter these variables as required
    */
    // Add the URI to hit when a premature abandonment is detected.
    // Note: currenlty only HTTP GET is supported
    $abandon_dispatch_uri = 'http://localhost/igm/poc/abandon/test/';
    // The period of time in minutes that needs to have passed before the item is considered to be an abandonment
    $abandon_timeout = 120;
    /* Conn::connect(abandon_dispatch_uri, abandon_timeout, connection_type = 'NONE')
    ** You can specify which kind of backing database you want to connect to by adjusting the last argument
    ** built-in options are:
    ** - NONE   will default to redis, then sqlite, or fail
    ** - REDIS  will try redis, or fail | Conn::connect($abandon_dispatch_uri, $abandon_timeout, 'REDIS');
    ** - SQLITE will try sqlite, or fail | Conn::connect($abandon_dispatch_uri, $abandon_timeout, 'SQLITE');
    */
    Conn::connect($abandon_dispatch_uri, $abandon_timeout);
    /* CONFIGURATION ZONE END */

    // Set up some initial variables
    $tx_id = isset($_GET['txId']) ? $_GET['txId'] : null;
    $origin_tx_id = isset($_GET['originTxId']) ? $_GET['originTxId'] : null;
    // item is really the key that we are going to be working with. Used the query string so it is easy to append to
    // the abandon_dispatch_uri without additional processing
    $item = '?txId='.$tx_id.'&originTxId='.$origin_tx_id;

    // handle the appropriate action
    switch($_GET['action']) {
        case 'add':
            if($tx_id != null &&  $origin_tx_id != null) {
                Conn::add($item);
            } else {
                Usage();
            }
        break;
        case 'remove':
            if($tx_id != null &&  $origin_tx_id != null) {
                Conn::remove($item);
            } else {
                Usage('Could not get the TxId or OriginTxId from the request');
            }
        break;
        case 'abandon':
            Conn::abandon();
        break;
        case 'list':
            Conn::list();
        break;
        case 'config':
            Conn::config();
        break;
        default:
        Usage('Unrecognised action: '.$_GET['action']);
        break;
    }

    class Conn {
        public static $conn;
        public static $conn_type;

        public static $abandon_dispatch_uri;
        public static $abandon_timeout;

        public static function connect($abandon_dispatch_uri, $abandon_timeout, $conn = 'NONE') {
            Conn::$abandon_dispatch_uri = $abandon_dispatch_uri;
            Conn::$abandon_timeout = $abandon_timeout;
            Conn::$conn_type = $conn;
            if(Conn::$conn_type == 'NONE' || Conn::$conn_type == 'REDIS') {
                error_log('in REDIS mode', 0);
                try {
                    Conn::$conn_type = 'REDIS';
                    Conn::$conn = new Redis();
                    Conn::$conn->connect('127.0.0.1');
                } catch(Exception $ex) {
                    Conn::$conn_type = 'NONE';
                    error_log('REDIS: '.$ex->getMessage(), 0);
                }
            } elseif(Conn::$conn_type == 'NONE' || Conn::$conn_type == 'SQLITE') {
                error_log('in SQLITE mode', 0);
                try {
                    Conn::$conn_type = 'SQLITE';
                    $sqlite_path = __DIR__.'/abandon.sqlite';
                    Conn::$conn = new PDO('sqlite:'.$sqlite_path);
                    Conn::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    Conn::$conn->exec('CREATE TABLE IF NOT EXISTS abandon_tx (
                        key TEXT,
                        value INT
                    )');
                } catch(Exception $ex) {
                    Conn::$conn_type = 'NONE';
                    error_log('SQLITE: '.$ex->getMessage(), 0);
                }
            } elseif(Conn::$conn_type == 'NONE' || Conn::$conn_type = 'OTHER') {
                // TODO: Add your own SQL implementation logic here, i.e.: connecting to a MySQL or other DB instance
            } else  {
                error_log('Fatal error on line '.__LINE__.': Unable to connect to any kind of DB', 0);
                exit;
            }
        }
        
        public static function add($item) {
            switch(Conn::$conn_type) {
                case 'REDIS':
                    Conn::$conn->set($item, time() + (Conn::$abandon_timeout * 60));
                    echo 'OK';
                break;
                case 'SQLITE':
                    try {
                        $stmt = Conn::$conn->prepare('INSERT OR REPLACE INTO abandon_tx (key, value) VALUES (?, ?)');
                        $stmt->execute(array($item, time() + (Conn::$abandon_timeout * 60)));
                        echo 'OK';
                    } catch (Exception $ex) {
                        echo 'FAILED';
                        error_log('Fatal error on line '.__LINE__.': issue with SQLITE provider. Exception reads '.$ex->getMessage(), 0);
                    }
                break;
                default:
                    // add other connection type information here
                break; 
            }
        }

        public static function remove($item, $notify = false) {
            if(Conn::$conn == NULL) {
                Conn::connect();
            }
            switch(Conn::$conn_type) {
                case 'REDIS':
                    if(Conn::$conn->exists($item)) {
                        if($notify == true) {
                            try {
                                file_get_contents(Conn::$abandon_dispatch_uri.$item);
                            } catch(Exception $ex) {
                                // TODO: try to use cURL as a fallback method
                                error_log('Unable to use file_get_contents to dispatch the notification', 0);
                            }
                            echo 'Transaction: '.$item.' has been abandoned, and a notification dispatched'.PHP_EOL;
                            Conn::$conn->unlink($item);
                        } else {
                            Conn::$conn->unlink($item);
                            echo 'OK';
                        }
                    } else {
                        error_log('NOTICE: Key does not exist for removal ['.$item.']', 0);
                    }
                break;
                case 'SQLITE':
                    try {
                        if($notify == true) {
                            try {
                                file_get_contents(Conn::$abandon_dispatch_uri.$item);
                            } catch(Exception $ex) {
                                error_log('Unable to use file_get_contents to dispatch the notification', 0);
                            }
                            try {    
                                $stmt = Conn::$conn->prepare('DELETE FROM abandon_tx WHERE key = ?');
                                $stmt->execute(array($item));
                                echo 'Transaction: '.$item.' has been abandoned, and a notification dispatched'.PHP_EOL;
                            } catch(Exception $ex) {
                                echo 'FAILED';
                                error_log('Fatal error on line '.__LINE__.': '.$ex->getMessage(), 0);
                            }
                        } else {
                            try {
                                $stmt = Conn::$conn->prepare('DELETE FROM abandon_tx WHERE key = ?');
                                $stmt->execute(array($item));
                                echo 'OK';
                            } catch(Exception $ex) {
                                echo 'FAILED';
                                error_log('Fatal error on line '.__LINE__.': '.$ex->getMessage(), 0);
                            }
                        }
                        
                    } catch(Exception $ex) {
                        echo 'FAILED';
                        error_log('Fatal error on line '.__LINE__.': issue with SQLITE provider. Exception reads '.$ex->getMessage(), 0);
                    }
                break;
                default:
                    // add other connection type information here
                break; 
            }
        }

        public static function abandon() {
            $now = time();
            $count = array('abandoned' => 0, 'not_abandoned' => 0);
            echo 'System time is currently: '.strftime('%Y-%m-%d %T', $now).PHP_EOL;
            switch(Conn::$conn_type) {
                case 'REDIS':
                    foreach(Conn::$conn->getKeys('*') as $k) {
                        $v = Conn::$conn->get($k);
                        if($v > $now) {
                            echo 'Transaction: '.$k.' expires at :'.strftime('%y-%m-%d %T', $v).PHP_EOL;
                            $count['not_abandoned']++;
                        } else {
                            Conn::remove($k, true);
                            $count['abandoned']++;
                        }
                    }
                    echo 'Abandoned: '.$count['abandoned'].'/'.$count['not_abandoned'];
                break;
                case 'SQLITE':
                    foreach(DB::$db_conn->query('SELECT * FROM abandon_tx', PDO::FETCH_ASSOC) as $row) {
                        if($row['value'] > $now) {
                            echo 'Transaction: '.$row['key'].' expires at :'.strftime('%y-%m-%d %T', $row['value']).PHP_EOL;
                            $count['not_abandoned']++;
                        } else {
                            Conn::remove($row['key'], true);
                            $count['abandoned']++;
                        }
                    }
                    echo 'Abandoned: '.$count['abandoned'].'/'.$count['not_abandoned'];
                break;
                default:
                     // add other connection type information here
                break;
            }
        }

        public static function list() {
            $count = 0;
            switch(Conn::$conn_type) {
                case 'REDIS':
                    foreach(Conn::$conn->getKeys('*') as $k) {
                        echo $k.': '.Conn::$conn->get($k).PHP_EOL;
                        $count++;
                    }
                    if($count == 0) {
                        echo 'There are no items in the abandon list';
                    }
                break;
                case 'SQLITE':
                    try {
                        foreach(Conn::$conn->query('SELECT * FROM abandon_tx') as $row) {
                            echo $row['key'].': '.$row['value'].PHP_EOL;
                            $count++;
                        }
                        if($count == 0) {
                            echo 'There are no items in the abandon list';
                        }
                    } catch(Exception $ex) {
                        error_log('Fatal error on line '.__LINE__.': '.$ex->getMessage(), 0);
                    }
                break;
                default:
                    // add other connection type information here
                break;
            }
        }

        public static function config() {
            echo 'Connection type: '.Conn::$conn_type.PHP_EOL;
            echo 'Abandon Dispatch URI: '.Conn::$abandon_dispatch_uri.PHP_EOL;
            echo 'Abandon timeout: '.Conn::$abandon_timeout.' minutes'.PHP_EOL.PHP_EOL;
            switch(Conn::$conn_type) {
                case 'REDIS':
                    echo 'Redis server information:'.PHP_EOL;
                    foreach(Conn::$conn->info() as $k => $v) {
                        echo $k.': '.$v.PHP_EOL;
                    }
                break;
                case 'SQLITE':
                break;
                default:
                    // add other connection type information here
                break;
            }
        }
    }

    function Usage($err = null) {
        if($err != null) {
            if(is_object($err)) {
                echo $err->getMessage().PHP_EOL.PHP_EOL;
                error_log('Fatal error: '.$err.getMessage(), 0);
            } else {
                echo $err.PHP_EOL.PHP_EOL;
                error_log('Fatal error: '.$err, 0);
            }
        } else {
            error_log('A fatal error was encountered, but no error message was generated', 0);
        }
        echo 'IGM Abandon: A utility for detecting premature abandonment'.PHP_EOL;
        echo 'HTTP GET Parameters:'.PHP_EOL;
        echo '  action          - list of actions that can be done. Current actions are: add, remove, abandon, list, config'.PHP_EOL;
        echo '  txId            - the transaction ID associated with the request'.PHP_EOL;
        echo '  originTxId      - the origin transaction ID associated with the request'.PHP_EOL;
        echo 'Examples:'.PHP_EOL;
        echo 'Add: http://localhost/abandon/?action=add&txId={GUID}&originTxId={GUID}'.PHP_EOL;
        echo 'Remove: http://localhost/abandon/?action=remove&txId={GUID}&originTxId={GUID}'.PHP_EOL;
        echo 'Abandon: http://localhost/abandon/?action=abandon'.PHP_EOL;
        echo 'List all items: http://locahost/abandon/?action=list'.PHP_EOL.PHP_EOL;
        echo 'Additional information:'.PHP_EOL;
        echo 'You can either use the PHP function file_get_contents(http_addr_to_abandon) or cURL to call into this service'.PHP_EOL;
        echo 'You should call the add service each time you receive a STATUS or FEEDBACK API call from IGM'.PHP_EOL;
        echo 'You should call the remove service when you receive a COMPLETION API call from IGM'.PHP_EOL;
        echo 'Set up a cron job to periodically run the abandon service. The timing of the cron job is independent from when abandoned transactions are detected'.PHP_EOL;
        echo 'Make sure you update the abandon_dispatch_uri and abandon_timeout inside of this script'.PHP_EOL;
        exit;
    }
?>