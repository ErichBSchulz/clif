<?php

/**
 * Contact List Interchange Format (CLIF) engine clase
 *
 * todo
 * - type switch
 * - validation checker
 * - cache hack
 * - impmentent API
 *  $params['return'] = 'id';
 *  return civicrm_api3('Contact', 'get', $params);
 */

class CRM_Clif_Engine {

  /**
   * Constructor
   *
   * @params array
   * - clif
   */
  public function __construct(array $params) {
    $defaults = array(
      'clif' => false
    );
    $p = $params + $defaults;
    // $this->cacheEngine = new AgcCache();
    if (!$p['clif']) {
      throw new Exception("missing clif parameter");
    }
    $this->clif = $p['clif'];
  }

  /**
   * Utility for formating contact list
   *
   * @params array of $contact_ids
   * - clif
   * @todo add in a permissive mode that simply filters out bad IDs
   */
  public static function contactIdsToClif(array $contact_ids) {
    $raw = array();
    foreach ($contact_ids as $id) {
      if (!is_numeric($id)) {
        throw new Exception("bad contact_id");
      }
      $contact_id = (int)($id);
      $raw[$contact_id] = 1;
      if (static::$safe) {
        if ($contact_id < 1) {
          throw new Exception("contact ID too low");
        }
        if (end($contact_ids) > 99999999999) {
          // fixme max_id should be system constant
          throw new Exception("contact ID too hi");
        }

      }
    }
    // apparently this method is slower when combined with type casting
    // $raw = array_fill_keys($contact_ids, 1);
    $clif = array(
      'type' => 'raw',
      'params' => $raw
    );
    return $clif;
  }

  /**
   * AgcBook object (wrapper for directory holding cache files)
   */
  private $cache;
  const BOOKNAME = 'subsets';

  /**
   * Immutable root CLIF set on construction
   */
  private $root;

  /**
   * flag for turning on extra checks (at cost of performance)
   */
  private static $safe = 1; // propose 0=high performance, 1=regular, 2=debug

  /**
   * List of contact lists by key
   */
  private $rawLists = [];

  /**
   * List of contact lists by key
   */
  private $contacts = [];

  /**
   * Array of trace reports
   */
  private $trace = [];

  /**
   * Array of profiling times
   */
  private $segments = [];

  /**
   * Integer millisecond start time
   */
  private $start = 0;

  /**
   * Add a time-stamped record note to the trace record:
   * @param string $msg
   */
  private function trace($msg) {
    if (!$this->start) {
      $this->start = microtime(true);
    }
    $elapsed = $this->elapsed();
    $this->trace[] = $elapsed . ': ' . $msg;
  }

  /**
   * Elapsed time in milliseconds
   * @todo merge into trace()
   */
  private function elapsed() {
    $time = microtime(true);
    return round(($time - $this->start) * 1000);
  }

  private function start($task) {
    $this->segments[$task]['start'] = microtime(true);
  }

  private function stop($task) {
    $run_time = round((microtime(true) - $this->segments[$task]['start']) * 100)* 10;
    $total = (Agc::hasValue($this->segments[$task], 'total') ?: 0) + $run_time;
    $this->segments[$task]['total'] = $total;
    $this->trace("${run_time}ms (total now ${total}ms) on $task");
  }

  private function getFilters() {
    return $this->root;
  }

  /**
   * Reset the cache
   *
   * If passed a falsy array then clears entire cache, otherwise just the
   * chapters listed. If passed `true` then clears current filter only.
   *
   * @params $chapters boolean|array (optional)
   * @returns number of sets flushed | null if doing complete flush
   * @tested via quick.clearcache
   */
  private function clearCache($chapters = false) {
    if ($chapters === true) {
      $chapters = $this->getCacheChapters();
    }
    if ($chapters) {
      $count = 0;
      foreach ($chapters AS $chapter) {
        $result = $this->cache->deleteChapter($chapter);
        $this->trace($chapter . ($result ? '' : ' not') . ' cleared');
        if ($result) {
          $count++;
        }
      }
      return $count;
    }
    else {
      $this->cache->destroy();
    }
  }

  /**
   * Testing function to list contents of cache repository
   * @returns array
   * @tested via quick.clearcache
   */
  private function listCache() {
    return $this->cache->listChapters();
  }

  /**
   * Generates this list if not already and counts the contacts.
   * @return integer
   */
  private function count() {
    return count($this->getContacts());
  }

  /**
   * Generates this list if not already and provides a list of contact IDs
   * @return array of integers
   * @todo define behaviour with empty list
   */
  public function contactIds() {
    return array_keys($this->getContacts());
  }

  /**
   * Generates this list if not already and returns the contacts.
   * @return array of contact ids
   * @see http://php.net/manual/en/function.array-slice.php
   */
  public function get($params = []) {
    return 'hello world';

    $defaults = [
      'offset' => 0,
      'length' => false,
      'format' => 'array',
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    if (!$p['length']) {
      throw new Exception('must specify length');
    }
    // decode from "ID index format" and slice out the chunk we want
    $contacts = array_keys(array_slice(
      $this->getContacts(), $p['offset'], $p['length'], true));
    switch ($p['format']) {
    case 'array':
      return $contacts;
    case 'string_list': // suitable for using in `IN ()` clauses
      return count($contacts) ? implode(',', $contacts) : '-1';
    default:
      throw new Exception('bad format');
    };
  }

  /**
   * Provide a human readable name (to support debugging and tuning)
   * @param filter mixed
   * @return string
   */
  private function describe($clif) {
    return is_array($clif['params'])
      ? count($clif['params']) . ' records'
      : $clif['params'];
  }

  /**
   * Fetch all raw lists and place in the $rawLists property
   *
   * @param &$clifs - collection of filters in [id, filter] format
   * @param $params = []
   *
   * Adds the following to each $clif row:
   * - key
   * - description
   *
   * Caches the list of contacts in $this->rawLists[$key]
   */
  private function loadRawLists(&$clifs, $params = []) {
    $defaults = [
      'dry_run' => false
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    //    echo 'filters $$' .gettype($clifs) . '$$' . json_encode($clifs) . '$$';
    if (!is_array($clifs)) {
      throw new Exception ('filters must be an array');
    }
    foreach ($clifs AS &$clif) {
      $key = $this->filterToKey($clif['type'], $clif['params']);
      if (!(isset($clif['type']) && isset($clif['params']))) {
        echo 'bad filter: [' . json_encode($clif) . ']' .
            "\n" .  AgcDev::getBacktrace();
        throw new Exception ('bad filter');
      }
      $clif['description'] = self::describe($clif);
      $this->trace("starting $clif[id] - $clif[description]");
      $clif['key'] = $key;
      if (!isset($this->rawLists[$key])) {
        if (in_array($clif['type'], ['union', 'intersection', 'not'])) {
          $this->loadRawLists($clif['params'], $p);
          if (!$p['dry_run']) {
            switch ($clif['type']) {
            case 'union':
              $list = $this->union($clif['params']);
              break;
            case 'intersection':
              $list = $this->intersect($clif['params']);
              break;
            case 'not':
              $list = $this->not($clif['params']);
              break;
            }
          }
        }
        else {
          $this->start('get');
          // get the list (either from cache or generating raw
          $list = $this->getList($clif['type'], $clif['params'], $p);
          $this->stop('get');
        }
        if (!$p['dry_run']) {
          $this->trace(count($list) . " contacts loaded");
          $this->rawLists[$key] = $list;
        }
      }
      else {
        $this->trace('already loaded');
      }
    }
  }

  /**
   * Finds the intersection between sets (Boolean AND operation)
   * @return array in "index format" (key being contact_id)
   */
  private function intersect($clifs) {
    $this->trace('starting intersect');
    $index = []; // used sort lists from smallest to largest
    $contacts = false;
    // generate a size index
    foreach ($clifs AS $clif) {
      // fixme should extract out the "nots" in this loop to be subtracted after
      $index[$clif['key']] = count($this->rawLists[$clif['key']]);
    }
    // sort the index so the smallest lists are first
    asort($index);
    // loop over lists, starting with smallest
    foreach ($index AS $key => $count) {
      if ($contacts===false) {
        $this->trace("base of $count contacts");
        $contacts = $this->rawLists[$key];
      }
      else {
        $this->trace("merging $count contacts");
        $this->start('merge');
        $contacts = array_intersect_key($contacts, $this->rawLists[$key]);
        $this->stop('merge');
        if ($contacts === []) {
          $this->trace('empty, bailing');
          return $contacts;
        }
      }
    }
    $this->trace('done intersect finished with ' . count($contacts));
    return $contacts;
  }

  /**
   * Finds the union between sets (Boolean OR operation)
   * @return array in "index format" (currently key being contact_id)
   */
  private function union($clifs) {
    $this->trace('starting union');
    $contacts = false;
    // loop over lists, starting with smallest
    foreach ($clifs AS $clif) {
      if ($contacts===false) {
        $contacts = $this->rawLists[$clif['key']];
      }
      else {
        $this->start('merge');
        $contacts = $contacts + $this->rawLists[$clif['key']];
        $this->stop('merge');
      }
      $this->trace("now " . count($contacts) . " contacts");
    }
    $this->trace('done union');
    return $contacts;
  }

  /**
   * Reliably negate a set
   * @return array in "index format" (currently key being contact_id)
   */
  private function not($clifs) {
    $this->trace('starting negation');
    $this->start('negation');
    if (count($clifs) != 1) {
      throw new Exception ('can only negate a single clause');
    }
    $contacts = $this->getList('all', 'all');
    $this->trace("from " . count($contacts) . " contacts");
    $clif = $clifs[0];
    $contacts = array_diff_key($contacts, $this->rawLists[$clif['key']]);
    $this->trace("now " . count($contacts) . " contacts");
    $this->trace('done negation');
    $this->stop('negation');
    return $contacts;
  }

  /**
   * Generates this list if not already
   * @return array in "index format" (currently key being contact_id)
   */
  private function getContacts() {
    $log = ['type' => 'get', 'started' => microtime(true)];
    $this->trace('starting get');
    // list already generated, so return that and get out of here:
    if ($this->contacts) {
      return $this->contacts;
    }
    $this->loadRawLists($this->root);
    $this->contacts = $this->rawLists[$this->root[0]['key']];
    AgcDev::log($log);
    return $this->contacts;
  }

  /**
   * Get a list of chapters for this filter
   * @return array
   */
  private function getCacheChapters() {
    $log = ['type' => 'getting chapters', 'started' => microtime(true)];
    $this->trace('starting get chapters');
    $this->start('get chapters');
    $this->loadRawLists($this->root, ['dry_run' => true]);
    $this->stop('get chapters');
    return array_keys($this->chapters);
  }

  /**
   * Validates a filter
   * @return false (on valid) and string on error
   */
  private function isInvalid() {
    $log = ['type' => 'validating', 'started' => microtime(true)];
    $this->trace('starting get chapters');
    $this->start('get chapters');
    try {
      $this->loadRawLists($this->root, ['dry_run' => true]);
      // we got here so all good:
      $error = false;
    } catch (Exception $e) {
      // uh-oh
      $error = $e->getMessage();
      $this->trace('got: ' . $error .
        ' at ' . $e->getFile() . ' #' . $e->getLine());
    }
    $this->stop('get chapters');
    return $error;
  }

  /**
   * Fetch a list from cache or from database
   * Attempts a cache read first with `attemptFilterCacheRead())`.
   * If no cache hit calls `buildSqlMeta()` then `sqlToIdIndex()` and `cacheWrite()`
   * @param $clif_type
   * @param $clif
   * @returns a list in "ID index format"
   */
  private function getList($clif_type, $clif, $params = []) {
    $defaults = [
      'dry_run' => false
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    if ($clif_type == 'raw') {
      $this->trace('get raw ' . count($clif) . ' records');
      return $clif; // not cachable raw id list
    }
    // make a unique key for this filter
    $key = self::filterToKey($clif_type, $clif);
    // hash the key to filename safe string:
    $chapter = self::filterChapter($clif_type, $key);
    $this->chapters[$chapter] = true;
    // @todo - decide pattern for marking some filters as non-cachalbe
    $no_cache = false;
    $this->trace("key: $key");
    $this->trace("chapter: $chapter");
    // attempt cache load
    $list = ($no_cache ? false : $this->attemptFilterCacheRead($chapter, $key));
    if ($list) {
      $this->trace('cache hit: ' . count($list) . ' recs');
    }
    else {
      // get meta ([sql, contact_id])
      $meta = $this->buildSqlMeta($clif_type, $clif);
      $this->trace("sql:\n" .$meta['sql']);
      if ($p['dry_run']) {
        return [];
      }
      $this->start('running sql');
      $list = AgcDB::sqlToIdIndex($meta['sql'], ['field' => $meta['contact_id']]);
      $this->stop('running sql');
      if (!$no_cache) {
        $this->cacheWrite($chapter, $key, $list);
      }
    }
    return $list;
  }

  /**
   * Translate a filter into SQL
   *
   * This is the main switch board #futurerole
   *
   * @param $clif_type string uid|poly|primary|task etc
   * @param $clif filter parameters
   * @returns array
   * - sql
   * - contact_id field name
   * - [future] description
   * - [future] cacheable (defaul true)
   * @todo - extend to return cachable status and a description
   */
  private function buildSqlMeta($clif_type, $clif) {
    switch ($clif_type) {
    case 'uid': // deprecated - see `acl`
      return [
        'sql' => AgcPerm::contactSql(['uid' => $clif]),
          'contact_id' => 'contact_id'
          ];
    case 'all': // this is occasionally required for negation '
      return [
        'sql' => "SELECT id FROM civicrm_contact",
        'contact_id' => 'id'
        ];
    case 'empty_set':
      return [
        'sql' => "SELECT -1 AS entity_id",
        'contact_id' => 'entity_id'];
    default:
      throw new Exception('bad filter type: ' . $clif_type);
    }
  }

  /**
   * #futurerole
   *
   * @param $chapter - cache "chapter" ID
   * @param $key - hashed absolute identifier of list
   * @param $list
   * @returns a list if successful, otherwise returns null
   */
  private function attemptFilterCacheRead($chapter, $key) {
    $chapter_age = $this->cache->age($chapter);
    // set cache for 10 minutes
    if ($chapter_age && $chapter_age < 600) {
      $chapter_contents = $this->fileRead($chapter);
      if (isset($chapter_contents[$key])) {
        return $chapter_contents[$key];
      }
      else {
        watchdog('agcquick',
          "we have key collision!! what's the chances of that!!  %chapter %key",
          ['%chapter' => $chapter, '%key' => $key], WATCHDOG_NOTICE);
      }
    }
  }

  /**
   * Update the cache with a list of contacts.
   *
   * #futurerole
   *
   * @param $chapter - cache "chapter" ID
   * @param $key - hashed absolute identifier of list
   * @param $list
   */
  private function cacheWrite($chapter, $key, $list) {
    $this->fileWrite($chapter, [$key => $list]);
  }

  /**
   * Core function #futurerole
   * Make a filter definition into a string
   */
  private static function filterToKey($clif_type, $clif) {
    if ($clif_type == 'perm') {
      // if given a user, the key is really the users permissions:
      $clif = AgcPerm::permissionString($clif);
    }
    // fixme - for security reasons maybe should always be json encoded?
    return $clif_type . ':' . (is_string($clif) ? $clif : json_encode($clif));
  }

  /**
   * Create a file name safe hash based on clif_type and key
   * #futurerole
   * @param $clif_type
   * @param $key
   * @returns string
   */
  private static function filterChapter($clif_type, $key) {
    return substr($clif_type, 0, 2) . self::hashKey($key);
  }

  /**
   * Hash a key into filename friendly string
   * #futurerole
   * @param $key string
   * @param $key string
   */
  private static function hashtKey($key) {
    return sha1($key);
  }

  /**
   * Read a data blob from the "cache"
   * #futurerole
   * @param $chapter string
   * @returns an array [$key => [array]]
   */
  private function fileRead($chapter) {
    $this->start('reading file');
    $text = $this->cache->getRaw($chapter);
    $this->stop('reading file');
    $this->start('decoding');
//    $decoded = json_decode($text, true);
    $decoded = unserialize($text);
    $this->stop('decoding');
    return $decoded;
  }

  /**
   * Use the cache to write a data blob
   * #futurerole
   * @param $chapter string
   * @param $contacts array
   */
  private function fileWrite($chapter, $contacts) {
    $this->start('encoding');
//    $data = json_encode($contacts);
    $data = serialize($contacts);
    $this->stop('encoding');
    $this->start('writing file');
    $this->cache->putRaw($chapter, $data);
    $this->stop('writing file');
  }

}
