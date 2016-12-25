<?php

/**
 * Contact List Interchange Format engine clase
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
   * AgcBook object (wrapper for directory holding cache files)
   */
  private $book;
  const BOOKNAME = 'subsets';

  /**
   * Set on construction, immutable list of filters
   */
  private $filter;

  /**
   * List of contact lists by key
   */
  private $rawLists = [];

  public $contacts = [];
  // debug:
  public $trace = [];
  public $segments = []; // profiling data
  public $start = 0;

  /**
   * Add a time-stamped record note to the trace record:
   * @param string $msg
   */
  public function trace($msg) {
    if (!$this->start) {
      $this->start = microtime(true);
    }
    $elapsed = $this->elapsed();
    $this->trace[] = $elapsed . ': ' . $msg;
  }

  /**
   * Elapsed time in milliseconds
   */
  public function elapsed() {
    $time = microtime(true);
    return round(($time - $this->start) * 1000);
  }

  public function getFilters() {
    return $this->filter[0]['filter'];
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

  /**
   * Constructor
   *
   * @params array
   * - clif
   */
  function __construct(array $params) {
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
   * Reset the cache
   *
   * If passed a falsy array then clears entire cache, otherwise just the
   * chapters listed. If passed `true` then clears current filter only.
   *
   * @params $chapters boolean|array (optional)
   * @returns number of sets flushed | null if doing complete flush
   * @tested via quick.clearcache
   */
  function clearCache($chapters = false) {
    if ($chapters === true) {
      $chapters = $this->getCacheChapters();
    }
    if ($chapters) {
      $count = 0;
      foreach ($chapters AS $chapter) {
        $result = $this->book->deleteChapter($chapter);
        $this->trace($chapter . ($result ? '' : ' not') . ' cleared');
        if ($result) {
          $count++;
        }
      }
      return $count;
    }
    else {
      $this->book->destroy();
    }
  }

  /**
   * Testing function to list contents of cache repository
   * @returns array
   * @tested via quick.clearcache
   */
  function listCache() {
    return $this->book->listChapters();
  }

  /**
   * Generates this list if not already and counts the contacts.
   * @return integer
   */
  public function count() {
    return count($this->getContacts());
  }

  /**
   * Generates this list if not already and provides a list of contact IDs
   * @return string eg "5,6,324" ("-1" if empty)
   */
  public function contactIds() {
    //        echo "\nfilter: " . json_encode($this->filter) . "";
    return AgcDb::makeQuickUnsafeIntList(array_keys($this->getContacts()));
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
  private function describe($filter) {
    return is_array($filter['filter'])
      ? count($filter['filter']) . ' records'
      : $filter['filter'];
  }

  /**
   * Fetch all raw lists and place in the $rawLists property
   *
   * @param &$filters - collection of filters in [id, filter] format
   * @param $params = []
   *
   * Adds the following to each $filter row:
   * - key
   * - description
   *
   * Caches the list of contacts in $this->rawLists[$key]
   */
  private function loadRawLists(&$filters, $params = []) {
    $defaults = [
      'dry_run' => false
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    //    echo 'filters $$' .gettype($filters) . '$$' . json_encode($filters) . '$$';
    if (!is_array($filters)) {
      throw new Exception ('filters must be an array');
    }
    foreach ($filters AS &$filter) {
      $key = $this->filterToKey($filter['id'], $filter['filter']);
      if (!(isset($filter['id']) && isset($filter['filter']))) {
        echo 'bad filter: [' . json_encode($filter) . ']' .
            "\n" .  AgcDev::getBacktrace();
        throw new Exception ('bad filter');
      }
      $filter['description'] = self::describe($filter);
      $this->trace("starting $filter[id] - $filter[description]");
      $filter['key'] = $key;
      if (!isset($this->rawLists[$key])) {
        if (in_array($filter['id'], ['union', 'intersection', 'not'])) {
          $this->loadRawLists($filter['filter'], $p);
          if (!$p['dry_run']) {
            switch ($filter['id']) {
            case 'union':
              $list = $this->union($filter['filter']);
              break;
            case 'intersection':
              $list = $this->intersect($filter['filter']);
              break;
            case 'not':
              $list = $this->not($filter['filter']);
              break;
            }
          }
        }
        else {
          $this->start('get');
          // get the list (either from cache or generating raw
          $list = $this->getList($filter['id'], $filter['filter'], $p);
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
  private function intersect($filters) {
    $this->trace('starting intersect');
    $index = []; // used sort lists from smallest to largest
    $contacts = false;
    // generate a size index
    foreach ($filters AS $filter) {
      // fixme should extract out the "nots" in this loop to be subtracted after
      $index[$filter['key']] = count($this->rawLists[$filter['key']]);
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
  private function union($filters) {
    $this->trace('starting union');
    $contacts = false;
    // loop over lists, starting with smallest
    foreach ($filters AS $filter) {
      if ($contacts===false) {
        $contacts = $this->rawLists[$filter['key']];
      }
      else {
        $this->start('merge');
        $contacts = $contacts + $this->rawLists[$filter['key']];
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
  private function not($filters) {
    $this->trace('starting negation');
    $this->start('negation');
    if (count($filters) != 1) {
      throw new Exception ('can only negate a single clause');
    }
    $contacts = $this->getList('all', 'all');
    $this->trace("from " . count($contacts) . " contacts");
    $filter = $filters[0];
    $contacts = array_diff_key($contacts, $this->rawLists[$filter['key']]);
    $this->trace("now " . count($contacts) . " contacts");
    $this->trace('done negation');
    $this->stop('negation');
    return $contacts;
  }

  /**
   * Add a list using a boolean operator
   * @param $operator string union|intersection
   * @param $filters array
   * @return filters in QIF format
   */
  public static function addBooleanFilters($operator, $filters) {
    switch ((int)count($filters)) {
    case 0:
      throw new Exception ('bad filters');
    case 1:
      return reset($filters); // return the single element
    default: // more than one:
      return ['id' => $operator,  'filter' => $filters];
    }
  }

  /**
   * Generates this list if not already
   * @return array in "index format" (currently key being contact_id)
   */
  public function getContacts() {
    $log = ['type' => 'get', 'started' => microtime(true)];
    $this->trace('starting get');
    // list already generated, so return that and get out of here:
    if ($this->contacts) {
      return $this->contacts;
    }
    $this->loadRawLists($this->filter);
    $this->contacts = $this->rawLists[$this->filter[0]['key']];
    AgcDev::log($log);
    return $this->contacts;
  }

  /**
   * Get a list of chapters for this filter
   * @return array
   */
  public function getCacheChapters() {
    $log = ['type' => 'getting chapters', 'started' => microtime(true)];
    $this->trace('starting get chapters');
    $this->start('get chapters');
    $this->loadRawLists($this->filter, ['dry_run' => true]);
    $this->stop('get chapters');
    return array_keys($this->chapters);
  }

  /**
   * Validates a filter
   * @return false (on valid) and string on error
   */
  public function isInvalid() {
    $log = ['type' => 'validating', 'started' => microtime(true)];
    $this->trace('starting get chapters');
    $this->start('get chapters');
    try {
      $this->loadRawLists($this->filter, ['dry_run' => true]);
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
   * @param $filter_id
   * @param $filter
   * @returns a list in "ID index format"
   */
  public function getList($filter_id, $filter, $params = []) {
    $defaults = [
      'dry_run' => false
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    if ($filter_id == 'raw') {
      $this->trace('get raw ' . count($filter) . ' records');
      return $filter; // not cachable raw id list
    }
    // make a unique key for this filter
    $key = self::filterToKey($filter_id, $filter);
    // hash the key to filename safe string:
    $chapter = self::filterChapter($filter_id, $key);
    $this->chapters[$chapter] = true;
    // echo "\n\nfilter_id: $filter_id\nfilter: " . json_encode($filter);
    // do not cache phone bank allocations:
    $no_cache = ($filter_id == 'primary' && substr($filter, 0, 3) == '!pa')
      OR $p['dry_run'];
    $this->trace("key: $key");
    $this->trace("chapter: $chapter");
    // attempt cache load
    $list = ($no_cache ? false : $this->attemptFilterCacheRead($chapter, $key));
    if ($list) {
      $this->trace('cache hit: ' . count($list) . ' recs');
    }
    else {
      // get meta ([sql, contact_id])
      $meta = $this->buildSqlMeta($filter_id, $filter);
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
   * @param $filter_id string uid|poly|primary|task etc
   * @param $filter filter parameters
   * @returns array
   * - sql
   * - contact_id field name
   * - [future] description
   * - [future] cacheable (defaul true)
   * @todo - extend to return cachable status and a description
   */
  public function buildSqlMeta($filter_id, $filter) {
    switch ($filter_id) {
    case 'uid': // deprecated - see `acl`
      return [
        'sql' => AgcPerm::contactSql(['uid' => $filter]),
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
      throw new Exception('bad filter_id: ' . $filter_id);
    }
  }

  /**
   * #futurerole
   *
   * @param $chapter - book "chapter" ID
   * @param $key - hashed absolute identifier of list
   * @param $list
   * @returns a list if successful, otherwise returns null
   */
  public function attemptFilterCacheRead($chapter, $key) {
    $chapter_age = $this->book->age($chapter);
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
   * @param $chapter - book "chapter" ID
   * @param $key - hashed absolute identifier of list
   * @param $list
   */
  public function cacheWrite($chapter, $key, $list) {
    $this->fileWrite($chapter, [$key => $list]);
  }

  /**
   * Core function #futurerole
   * Make a filter definition into a string
   */
  private static function filterToKey($filter_id, $filter) {
    if ($filter_id == 'perm') {
      // if given a user, the key is really the users permissions:
      $filter = AgcPerm::permissionString($filter);
    }
    // fixme - for security reasons maybe should always be json encoded?
    return $filter_id . ':' . (is_string($filter) ? $filter : json_encode($filter));
  }

  /**
   * Create a file name safe hash based on filter_id and key
   * #futurerole
   * @param $filter_id
   * @param $key
   * @returns string
   */
  public static function filterChapter($filter_id, $key) {
    return substr($filter_id, 0, 2) . self::hashKey($key);
  }

  /**
   * Hash a key into filename friendly string
   * #futurerole
   * @param $key string
   * @param $key string
   */
  public static function hashKey($key) {
    return sha1($key);
  }

  /**
   * Read a data blob from the "book"
   * #futurerole
   * @param $chapter string
   * @returns an array [$key => [array]]
   */
  public function fileRead($chapter) {
    $this->start('reading file');
    $text = $this->book->getRaw($chapter);
    $this->stop('reading file');
    $this->start('decoding');
//    $decoded = json_decode($text, true);
    $decoded = unserialize($text);
    $this->stop('decoding');
    return $decoded;
  }

  /**
   * Use the book to write a data blob
   * #futurerole
   * @param $chapter string
   * @param $contacts array
   */
  public function fileWrite($chapter, $contacts) {
    $this->start('encoding');
//    $data = json_encode($contacts);
    $data = serialize($contacts);
    $this->stop('encoding');
    $this->start('writing file');
    $this->book->putRaw($chapter, $data);
    $this->stop('writing file');
  }

}
