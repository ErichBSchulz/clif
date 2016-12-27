<?php

/**
 * Contact List Interchange Format (CLIF) engine clase
 *
 * Note the engine use "index format" for contact list, storing the contact ID
 * as the key in the array and a 1 as the value. Initial benchmarking showed
 * this significantly speed up union and intersection operations.
 *
 * eg array(12345 => 1, 13552 => 1) is the internal format for [12345, 13552]
 *
 * todo
 * - type switch
 * - validation checker
 * - split cache into separate injectable glass
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
      'clif' => false,
      'inject' => [],
    );
    $p = $params + $defaults;
    // $this->cacheEngine = new AgcCache();
    if (!$p['clif']) {
      throw new Exception("missing clif parameter");
    }
    $this->root = $p['clif'];
    // set up injectables
    $p['inject'] += array(
      'api3' => "civicrm_api3",
    );
    $this->api3_callable = $p['inject']['api3'];
  }

  private $api3_callable;

  /**
   * Wrapper around the CiviCRM API V3
   * @params array
   * - clif_params
   * @returns array
   * - api_result - result from API
   * - raw - contact list format in index format
   * @todo add in a permissive mode that simply filters out bad IDs
   */
  private function api3(array $params) {
    $defaults = array (
    );
    $p = $params + $defaults;
    $entity = "GroupContact";
    $action = "get"; // filter so only get* allowed?
    // figure out what value to ask the api for
    switch ($entity) {
    case 'EntityTag':
      $returned_field = "entity_id";
      break;
    default:
      $returned_field = "contact_id";
    };
    $enforced_params = array(
      'sequential' => 1,
      'return' => $returned_field
    );
    $clif_params = array(
      'group_id' => array('IN' => array("Qld_All", "L2Vic")),
      'status' => "Added",
    );
    // add in the clif params to the enforced values
    // (first array has precidence)
    $api_params = $enforced_params + $clif_params;
    $result = call_user_func_array(
      $this->api3_callable,
      array($entity, $action, $api_params));
    if (!isset($result['is_error'])) {
      throw new Exception("malformed API3 response");
    }
    if ($result['is_error']) {
      $this->trace('api3 error with: [' . json_encode($api_params) . ']');
      throw new Exception("API3 error");
    }
    else {
      return array(
        'api_result' => $result,
        'raw' => $this->contactIdsToRaw($result['values'])
      );
    }
  }

  /**
   * Utility for formating contact list to raw indexed format
   * @params array of $contact_ids or arrays with contact_id, or id
   * @returns array with contact_id as key
   * @todo add in a permissive mode that simply filters out bad IDs
   */
  public static function contactIdsToRaw(array $contact_ids) {
    $raw = array();
    if (count($contact_ids)) {
      // use first record to understand collection
      $first = $contact_ids[0];
      if (is_array($first)) {
        if (isset($first['contact_id'])) {
          $method = 'contact_id';
        }
        elseif (isset($first['entity_id'])) {
          $method = 'entity_id';
        }
        elseif (isset($first['id'])) {
          $method = 'id';
        }
        else {
          throw new Exception("cannot find contact_id or id in first row");
        }
      }
      else {
        // this may not work, but we'll catch it below if it doesn't
        $method = 'integer';
      }
      // loop over records and collect ids
      foreach ($contact_ids as $row) {
        switch ($method) {
        case 'contact_id':
          $id = $row['contact_id'];
          break;
        case 'entity_id':
          $id = $row['entity_id'];
          break;
        case 'id':
          $id = $row['id'];
          break;
        case 'integer':
          $id = $row;
          break;
        }
        if (!is_numeric($id)) {
          throw new Exception("bad contact_id");
        }
        $contact_id = (int)($id);
        $raw[$contact_id] = 1;
        if (static::$safe) {
          if ($contact_id < 1) {
            throw new Exception("contact ID too low");
          }
          if ($contact_id > 99999999999) {
            // fixme max_id should be system constant
            throw new Exception("contact ID too high");
          }
        }
      }
    }
    // apparently this method is slower when combined with type casting
    // $raw = array_fill_keys($contact_ids, 1);
    return $raw;
  }

  /**
   * Utility for formating contact list
   * @params array of $contact_ids
   * @todo add in a permissive mode that simply filters out bad IDs
   */
  public static function contactIdsToClif(array $contact_ids) {
    $raw = self::contactIdsToRaw($contact_ids);
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

  /**
   * Immutable root CLIF set on construction
   */
  private $root;

  /**
   * flag for turning on extra checks (at cost of performance)
   */
  private static $safe = 1; // propose 0=high performance, 1=regular, 2=debug

  /**
   * List of contact lists by cache_key
   * This is a bit like a temporary in memmory cache of lists
   */
  private $stash = [];

  /**
   * List of contact lists by cache_key
   */
  private $contacts = [];


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
   *
   * Note this function should not handle sorting.
   * @params $params array
   * - offest integer
   * - length integer (required)
   * - format string array|raw|string_list
   * @return string|array depending on 'format' parameter
   * @see http://php.net/manual/en/function.array-slice.php
   */
  public function get($params = []) {
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
    // decode from "index format" and slice out the chunk we want
    $contacts = array_slice(
      $this->getContacts(), $p['offset'], $p['length'], true);
    switch ($p['format']) {
    case 'array':
      return array_keys($contacts);
    case 'string_list': // suitable for using in `IN ()` clauses
      return count($contacts) ? implode(',', array_keys($contacts)) : '-1';
    default:
      throw new Exception('bad format');
    };
  }

  /**
   * Generates this list if not already, or pulls from within the stash
   * @return array in "index format"
   */
  private function getContacts() {
    $this->trace('starting get');
    // list already generated, so return that and get out of here:
    if ($this->contacts) {
      return $this->contacts;
    }
    $this->fillStash($this->root);
    $this->contacts = $this->fromStash($this->root);
    return $this->contacts;
  }

  /**
   * Get the index list from the stash, or give back the raw list.
   *
   * For non-stashable (or cacheable) list the $clif itself contains the
   * values. For stashable values the "stash" acts as the temporary store.
   * This pattern avoids the very long keys from raw lists and also allows for
   * some lists to be held in the class in case they get repeated. This maybe
   * excessive optimiasation and the $stash is maybe not adding much.
   *
   * @return array in "index format"
   */
  private function fromStash($clif) {
    if (isset($clif['list'])) {
      return $clif['list'];
    }
    switch ($clif['type']) {
    case 'raw':
      $this->trace('get raw ' . count($clif) . ' records');
      return $clif['params'];
    case 'empty':
      $this->trace('got empty');
      return [];
    default:
      return $this->stash[$clif['cache_key']];
    }
  }

  /**
   * Provide a human readable name (to support debugging and tuning)
   * @param filter mixed
   * @return string
   */
  private function debugDescribe($clif) {
    if (isset($clif['params'])) {
      return is_array($clif['params'])
        ? count($clif['params']) . ' records'
        : $clif['params'];
    }
    return '';
  }

  /**
   * Coarse syntax validation
   * @param $clif
   * @return null
   */
  private function checkProperlyFormed($clif) {
    $type = $clif['type'];
    // check type
    if (static::$safe && preg_match('/[^a-z0-9_]/', $type)) {
      throw new Exception ('type must be only a-z, 0_9 and underscore');
    }
    // check params
    // 'all' and 'empty' must not have params
    if (in_array($type, ['all', 'empty'])) {
      if (isset($clif['params'])) {
        throw new Exception ('cannot have params array on $type');
      }
    }
    else { // all others must
      if (!isset($clif['params'])) {
        $this->trace('fatal missing property: [' . json_encode($clif) . ']');
        throw new Exception ('missing params array');
      }
      if (!is_array($clif['params'])) {
        throw new Exception ('params must be an array');
      }
    }
  }

  /**
   * Test to see if this list is worth holding in the stash
   * @param $clif
   * @return Boolean
   */
  private function isStashable($clif) {
    // These ones are not worth overhead of stashing (may need tuning)
    $exclude = array('empty', 'union', 'intersection', 'raw');
    return !in_array($clif['type'], $exclude);
  }

  /**
   * Walk query tree, validate, fetch and stash lists
   *
   * For stashable filters adds the following to each $clif row:
   * - cache_key
   *
   * For non-stashable filters the result is added to the $clif['list']
   *
   * For all
   * - description
   *
   * Stashes the list in $this->stash[$cache_key]
   *
   * @param &$clif - single CLIF filter in [type, params] format
   * @param $params = []
   * - dry_run - Boolean if true skips expensive steps to allow validation and
   *             identification of cache keys for flushing
   * @returns null
   * @throws
   */
  private function fillStash(&$clif, $params = []) {
    $defaults = array(
      'dry_run' => false
    );
    // merge params in with defaults
    $p = $params + $defaults;
    // validate clif (throws exception if invalid)
    $this->checkProperlyFormed($clif);
    // extract properties and fill in blank params if needed
    $type = $clif['type'];
    $clif_params = isset($clif['params']) ? $clif['params'] : [];
    $stashable = $this->isStashable($clif);
    if ($stashable) {
      $cache_key = $this->filterToKey($type, $clif_params);
      $clif['cache_key'] = $cache_key;
    }
    $clif['description'] = self::debugDescribe($clif);
    $this->trace("starting $clif[type] - $clif[description]");
    if ($type == 'raw') {
      return; // dont stash raw lists
    }
    elseif ($stashable && isset($this->stash[$cache_key])) {
      $this->trace('already loaded');
    }
    elseif (isset($clif['list'])) {
      $this->trace('already generated (this should not happen!)');
    }
    else {
      // Handle Boolean operator dependancies
      // recurse down the tree to ensure all dependant filters are fetched
      if (in_array($type, ['union', 'intersection', 'not'])) {
        foreach ($clif_params as $filter) {
          $this->fillStash($filter, $p);
        }
      }
      if (!$p['dry_run']) {
        $this->start('get');
        switch ($type) {
        case 'union':
          $list = $this->union($clif_params);
          break;
        case 'intersection':
          $list = $this->intersect($clif_params);
          break;
        case 'not':
          $list = $this->not($clif_params);
          break;
        case 'empty':
          $list = [];
          break;
        case 'raw':
          $list = $clif_params;
          break;
        case 'api3':
          $result = $this->api3(array('clif_params' => $clif_params));
          $list = $result['raw'];
          break;
        default:
          // get the list (either from cache or generating raw
          $list = $this->getList($clif, $p);
        }
        $this->trace(count($list) . " contacts loaded");
        $this->stop('get');
        if ($stashable) {
          $this->stash[$cache_key] = $list;
        }
        else {
          $clif['list'] = $list;
        }
      }
    }
  }

  /**
   * Finds the intersection between sets (Boolean AND operation)
   * @return array in "index format"
   */
  private function intersect($clifs) {
    $this->trace('starting intersect');
    $index = []; // used sort lists from smallest to largest
    $contacts = false;
    // generate a size index
    foreach ($clifs AS $i => $clif) {
      // fixme should extract out the "nots" in this loop to be subtracted after
      $index[$i] = count($this->fromStash($clif));
    }
    // sort the index so the smallest lists are first
    asort($index);
    // loop over lists, starting with smallest
    foreach ($index AS $i => $count) {
      $current = $this->fromStash($clifs[$i]);
      if ($contacts===false) {
        $this->trace("base of $count contacts");
        $contacts = $current;
      }
      else {
        $this->trace("merging $count contacts");
        $this->start('merge');
        $contacts = array_intersect_key($contacts, $current);
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
   * @return array in "index format"
   */
  private function union($clifs) {
    $this->trace('starting union');
    $contacts = false;
    foreach ($clifs AS $clif) {
      if ($contacts===false) { // first time around
        $contacts = $this->fromStash($clif);
      }
      else {
        $this->start('merge');
        $contacts = $contacts + $this->fromStash($clif);
        $this->stop('merge');
      }
      $this->trace("now " . count($contacts) . " contacts");
    }
    $this->trace('done union');
    return $contacts;
  }

  /**
   * Reliably negate a set
   * @return array in "index format"
   */
  private function not($clifs) {
    $this->trace('starting negation');
    $this->start('negation');
    if (count($clifs) != 1) {
      throw new Exception ('can only negate a single clause');
    }
    $contacts = $this->getList(['type' => 'all']);
    $this->trace("from " . count($contacts) . " contacts");
    $clif = $clifs[0];
    $contacts = array_diff_key($contacts, $this->fromStash($clif));
    $this->trace("now " . count($contacts) . " contacts");
    $this->trace('done negation');
    $this->stop('negation');
    return $contacts;
  }


  /**
   * Fetch a list from cache or from database
   * Attempts a cache read first with `attemptFilterCacheRead())`.
   * If no cache hit needs to call api and `cacheWrite()`
   * @param $type
   * @param $clif
   * @returns a list in "index format"
   */
  private function getList($clif, $params = []) {
    $defaults = [
      'dry_run' => false
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    $clif_params = isset($clif['params']) ? $clif['params'] : [];
    $type = $clif['type'];
    // make a unique cache_key for this filter
    $cache_key = self::filterToKey($type, $clif_params);
    // hash the cache_key to filename safe string:
    $chapter = self::filterChapter($type, $cache_key);
    $this->chapters[$chapter] = true;
    // @todo - decide pattern for marking some filters as non-cachalbe
    $no_cache = false;
    $this->trace("cache_key: $cache_key");
    $this->trace("chapter: $chapter");
    // attempt cache load
    $list = ($no_cache ? false : $this->attemptFilterCacheRead($chapter, $cache_key));
    if ($list) {
      $this->trace('cache hit: ' . count($list) . ' recs');
    }
    else {
      // get meta ([sql, contact_id])
      $meta = $this->buildSqlMeta($type, $clif_params);
      $this->trace("sql:\n" .$meta['sql']);
      if ($p['dry_run']) {
        return [];
      }
      $this->start('running sql');
      $list = AgcDB::sqlToIdIndex($meta['sql'], ['field' => $meta['contact_id']]);
      $this->stop('running sql');
      if (!$no_cache) {
        $this->cacheWrite($chapter, $cache_key, $list);
      }
    }
    return $list;
  }

  /**
   * Get a list of chapters for this filter
   * @return array
   */
  private function getCacheChapters() {
    $this->trace('starting get chapters');
    $this->start('get chapters');
    $this->fillStash($this->root, ['dry_run' => true]);
    $this->stop('get chapters');
    return array_keys($this->chapters);
  }

  /**
   * Validates a filter
   * @return false (on valid) and string on error
   */
  private function isInvalid() {
    $this->trace('starting get chapters');
    $this->start('get chapters');
    try {
      $this->fillStash($this->root, ['dry_run' => true]);
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
   * @todo - deprecated pattern - will move to API based list generation
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

  ///////////////////////////////////////////////////////////////////////////
  // Trace and debug functions
  /**
   * Array of trace reports
   */
  public $trace = [];

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
    $total = isset($this->segments[$task]['total'])
      ? $this->segments[$task]['total'] : 0;
    $total += $run_time;
    $this->segments[$task]['total'] = $total;
    $this->trace("${run_time}ms (total now ${total}ms) on $task");
  }

  /////////////////////////////////////////////////////////////////////////////
  // Caching functions

  /**
   * #futurerole
   *
   * @param $chapter - cache "chapter" ID
   * @param $cache_key - hashed absolute identifier of list
   * @param $list
   * @returns a list if successful, otherwise returns null
   */
  private function attemptFilterCacheRead($chapter, $cache_key) {
    $chapter_age = $this->cache->age($chapter);
    // set cache for 10 minutes
    if ($chapter_age && $chapter_age < 600) {
      $chapter_contents = $this->fileRead($chapter);
      if (isset($chapter_contents[$cache_key])) {
        return $chapter_contents[$cache_key];
      }
      else {
        watchdog('agcquick',
          "we have cache_key collision!! what's the chances of that!!  %chapter %cache_key",
          ['%chapter' => $chapter, '%cache_key' => $cache_key], WATCHDOG_NOTICE);
      }
    }
  }

  /**
   * Update the cache with a list of contacts.
   *
   * #futurerole
   *
   * @param $chapter - cache "chapter" ID
   * @param $cache_key - hashed absolute identifier of list
   * @param $list
   */
  private function cacheWrite($chapter, $cache_key, $list) {
    $this->fileWrite($chapter, [$cache_key => $list]);
  }

  /**
   * Core function #futurerole
   * Make a filter definition into a string
   */
  private static function filterToKey($clif_type, $clif) {
    if ($clif_type == 'perm') {
      // if given a user, the cache_key is really the users permissions:
      $clif = AgcPerm::permissionString($clif);
    }
    // fixme - for security reasons maybe should always be json encoded?
    return $clif_type . ':' . (is_string($clif) ? $clif : json_encode($clif));
  }

  /**
   * Create a file name safe hash based on clif_type and cache_key
   * #futurerole
   * @param $clif_type
   * @param $cache_key
   * @returns string
   */
  private static function filterChapter($clif_type, $cache_key) {
    return substr($clif_type, 0, 2) . self::hashKey($cache_key);
  }

  /**
   * Hash a cache_key into filename friendly string
   * #futurerole
   * @param $cache_key string
   * @param $cache_key string
   */
  private static function hashKey($cache_key) {
    return sha1($cache_key);
  }

  /**
   * Read a data blob from the "cache"
   * #futurerole
   * @param $chapter string
   * @returns an array [$cache_key => [array]]
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
