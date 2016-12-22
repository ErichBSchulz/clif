<?php
/**
 *  @copyright Australian Greens 2014-16. All rights reserved.
 *
 * Several classes supporting list managment in one form or another.
 * - AgcList - inherits from AgcEntity, includes methods to repackage AgcReports
 * - AgcButton - supports "list as button" functionality
 * - AgcQuickList - complex caching lists
 *
 * (The distinction between the classes isn't really optimal.)
 *
 */

/**
 * This class:
 * - provides a level of syntactic sugar around AGCReports, and
 * - handles handing lists of contacts between pages.
 *
 * AGC reports remain responsible for implementing permissions.
 * This class presents a friendlier set of parameters to the API
 *
 * @uses for storing instances $_SESSION['agc:list'] (EMS7 only - deprecated in EMS8)
 */
class AgcList extends AgcEntity {

  private $chapters = [];

  /**
   * API function for list
   * #futurerole
   * @param array
   * - mode
   */
  public static function api(array $params) {
    $defaults = [
      ];
    $p = $params + $defaults;
    switch ($p['mode']) {
    case 'calllist':
      // fixme -need to fine tune permissions - who can this user allocate too?
      return AgcCallCentre::publicAllocationService($p);
    case 'savebutton':
      // permissions managed via permittedToManageButtons()
      return AgcButton::save($p);
    case 'event':
      return self::eventApi($p);
    case 'grant':
      return self::grantApi($p);
    case 'tag':
      return self::tagApi($p);
    case 'group':
      return self::groupApi($p);
    case 'clearcache':
      $list = AgcQuickList::fromComboToPermitted($p, 'access election');
      $count = $list->clearCache(true);
      return [
          'is_error' => false,
          'count' => $count,
          'trace' => $list->trace,
        ];
    case 'get':
      return self::getApi($p);
    default:
      return ['is_error' => true, 'error_message' => 'unknown mode'];
    }
  }

  /**
   * Handler for getting lists in different formats
   * @param $params array
   * - fields string standard|latest_date
   * - length integer
   * @returns 2d array
   * @fixme tests!!
   */
  public static function getApi($params = []) {
    $defaults = [
      'fields' => 'standard',
      'length' => 100,
      ];
    $p = $params + $defaults;
    //// prepare the list:
    // get the `list` and `filter` properties and apply permission
    $list = AgcQuickList::fromComboToPermitted($p, 'access election');
    $contact_ids = $list->get([
      'format' => 'string_list',
      'length' => $p['length']]);
    switch ($p['fields']) {
    case 'standard':
      $sql = AgcContact::sql([
        'in' => $contact_ids,
        'fields' => 'standard',
        'phone' => true,
        'enforce_permission' => false, // handled by `fromComboToPermitted()`
        ]);
      break;
    case 'latest':
      $sql = AgcContact::activityFilterSql([
        'in' => $contact_ids,
        'format' => 'latest_date'
        ]);
      break;
    case 'postal_code':
      $sql = AgcContact::addressSql([
        'in' => $contact_ids,
        'format' => 'postal_code'
        ]);
      break;
    case 'allocation':
      $sql = AgcContact::allocationSql([
        'in' => $contact_ids,
        'format' => 'full'
        ]);
      break;
    default:
      return [
        'is_error' => true,
        'error_message' => 'unknown fields'
        ];
    }
    $result = [
      'is_error' => false,
      'count' => $list->count(),
      'trace' => $list->trace,
      'contacts' => AgcDb::sqlTo2dArray($sql),
      ];
    if (user_access('see sql')) {
      $result['sql'] = $sql;
    }
    return $result;
  }

	 /**
   *
   * @param $params array
   * - list/filters - list definition
   * - rid - integer
   * - poly_id - integer
   * - duration - real
   * @returns array
   * @fixme tests!!
   */
  public static function grantApi($params = []) {
    $defaults = [
      'duration' => FALSE,
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    $result = ['trace' => []];
    $result['new_accounts'] = $result['grants'] = $error_count = 0;
    // get list
    $list = AgcQuickList::fromComboToPermitted($p, 'access election');
    $contacts = $list->getContacts();
    $result['trace'][] = $contacts;
    // clean up params
    $rid = (int)$p['rid'];
    $poly_id = (int)$p['poly_id'];
    $duration = $p['duration'] ? (real)$p['duration'] : FALSE;
    // check perms
    if (!AgcPerm::has(AgcPerm::permissionToGrant($rid), $poly_id)) {
      return ['is_error' => true, 'report' => 'permission denied'];
    }
    // loop over contacts
    foreach($contacts AS $contact_id => $one) {
      $contact = new AgcContact($contact_id);
      $user_get_result = AgcUser::createOrLoadUser($contact);
      $result['trace'][] = $user_get_result;
      if ($user_get_result['is_error']) {
        $error_count++;
        $result['trace'][] = $user_get_result['error_message']
          . ' getting user for #' . $contact_id;
      }
      else {
        if ($user_get_result['is_new']) {
          $result['new_accounts'] += 1;
        }
        $uid = $user_get_result['user']['uid'];
        $already_has_perm = AgcPerm::userHasGeoRole([
          'rid' => $rid,
          'poly_id' => $poly_id,
          'uid' => $uid,
        ]);
        if (!$already_has_perm) {
          $result['grants'] += 1;
          AgcPerm::addRole($uid, $rid, ['poly_id' => $poly_id], $duration);
        }
        $result['users'][] = ['contact_id' => $contact_id]
          + $user_get_result['user'] + [
          'new' => ($user_get_result['is_new'] ? 'yes' : 'no'),
          'granted' => ($already_has_perm ? 'no' : 'yes'),
          ];
      }
    }
    $result['is_error'] = !!$error_count;
    if ($error_count) {
      $result['error_message'] = "$error_count errors occurred";
    }
    return $result;
  }

  /**
   * Handler for bulk tag operations on lists of contacts
   *
   * @param $params array
   * - list/filters - list definition
   * - options
   *   - event_id (int)
   *   - participation_status (int)
   * @returns array
   * @fixme tests!!
   */
  public static function eventApi($params = []) {
    $defaults = [
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    $list = AgcQuickList::fromComboToPermitted($p, 'access election');
    $event = new AgcEvent($p['options']['event_id']);
    $contacts = $list->getContacts();
    $result = ['trace' => []];
    $result['trace'][] = $contacts;
    $result['trace'][] = $event->title();
    $result['baseline_attendance'] = $event->participationSummary(['field'=>'id']);
    $error_count = 0;
    foreach($contacts AS $contact_id => $one) {
      $event_params = [
        'contact_id' => $contact_id,
        'status_id' => $p['options']['participation_status'],
        'participant_id' => 'lookup'
        ];
      $event_result = $event->participate($event_params);
      $result['trace'][] = $event_result;
      if ($event_result['is_error']) {
        $error_count++;
        $result['trace'][] = $event_result['error_message']
          . ' from ' . json_encode($event_params);
      }
    }
    $result['final_attendance'] = $event->participationSummary(['field'=>'id']);
    $result['is_error'] = !!$error_count;
    if ($error_count) {
      $result['error_message'] = "$error_count errors occurred";
    }
    return $result;
  }

  /**
   *
   * @param $params array
   * - tag_mode add|remove
   * - tag_id integer
   * @returns array
   * @fixme tests!!
   */
  public static function tagApi($params = []) {
    $defaults = [
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    $list = AgcQuickList::fromComboToPermitted($p, 'access election');
    $number_in_list = $list->count();
    $contacts = $list->contactIds();
    // fixme check valid tag id
    $tag_id = (int)$p['tag_id'];
    $count_pre = AgcContact::countTags($contacts, $tag_id);
    switch ($p['tag_mode']) {
    case 'add':
      AgcContact::bulkAddTag($contacts, $tag_id);
      break;
    case 'remove':
      AgcContact::bulkDeleteTag($contacts, $tag_id);
      break;
    default:
      return ['is_error' => true, 'report' => 'bad tag mode'];
    }
    $count_post = AgcContact::countTags($contacts, $tag_id);
    $count_diff = $count_post - $count_pre;
    return [ 'is_error' => false,
      'report' => "$count_pre of your search results were tagged before, " .
      "$count_post are now."
      ];
  }

  /**
   * Handler for bulk group operations on lists of contacts
   *
   * @param $params array
   * - list/filters - list definition
   * - group_mode new|add
   * - group_name string (new only)
   * - group_id int (add only)
   * @returns array
   * @fixme tests!!
   */
  public static function groupApi($params = []) {
    $defaults = [
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    //fixme check permission to add to group
    switch ($p['group_mode']) {
    case 'new':
      // make the group first
      // fixme verify acceptable name;
      $group_result = AgcGroup::createGroup([
        'name' => $p['group_name'],
        'source' => 'Rocket']);
      if ($group_result['is_error']) {
        return $group_result;
      }
      else {
        $group_id = $group_result['id'];
      }
      break;
    case 'add':
      $group_id = (int)$p['group_id'];
      break;
    default:
      return ['is_error' => true, 'report' => 'bad group mode'];
    }
    if ($group_id) {
      $list = AgcQuickList::fromComboToPermitted($p, 'access election');
      $number_in_list = $list->count();
      $contacts = $list->contactIds();
      $count_pre = AgcContact::countGroup($group_id);
      AgcContact::bulkAddToCiviGroup($group_id, $contacts);
      $count_post = AgcContact::countGroup($group_id);
      $count_diff = $count_post - $count_pre;
      return [
        'is_error' => false,
        // 'filter' => $list->getFilters(),
        'group_id' => $group_id,
        'count_pre' => $count_pre,
        'count_post' => $count_post,
        'number_in_list' => $number_in_list,
        'report' => "$count_diff contacts added to group $group_id successfully."];
    } else {
      return [
        'is_error' => true,
        'report' => 'No group ID provided.'];
    }
  }

  /**
   * Construct SQL and meta-information from poly morphic list definition.
   * Will take params and return a sql list based on a:
   * - AgcList (list_id, params)
   * - AgcReport (report, p1, p2, p3, p4),
   * - AgcReport log entry (report_log_id)
   * - AgcReport url (string) - see class AgcReport
   *
   * This is the primary interface between the QIF engine and the older
   * "AgcReport" (tokenised SQL) library.
   *
   * @returns array [sql,title,desription,contact_id] | false on error
   * @deprecated
   */
  public static function buildPolymorphicMeta($params = []) {
    $defaults = [
      'params' => [],
      'acl' => -1, // true = enforce access control
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    // define access control setting
    if ($p['acl'] == -1) {
      throw new Exception("must specify acl");
    }
    $report_mode = ($p['acl'] ? 'meta' : 'meta_no_acl');
    // check we only have a single list definition
    $type = Agc::filter($p, ['list_id', 'report_log_id', 'report', 'uri']);
    if (count($type) != 1) {
      throw new Exception("must supply only one of 'list_id', 'log_id', 'report'");
    }
    // build the metadata
    switch (current(array_keys($type))) {
      case 'list_id':
        // load an AgcList and apply parameters
        $list = new AgcList($p['list_id']);
        $meta = $list->meta($p['params']);
        return $meta;
      case 'report_log_id':
        // load logged report by id and re-build meta
        $log = AgcReport::loadFromLog($p['report_log_id']);
        $meta = AgcReport::get($log['report'],
            $report_mode, $log['p0'], $log['p1'], $log['p2'], $log['p3']);
        return $meta;
      case 'report':
        // fill in blanks in supplied params
        $ps = $p['params'] + [null, null, null, null, null, null];
        $meta = AgcReport::get($p['report'], $report_mode, $ps[0], $ps[1], $ps[2], $ps[3]);
        return $meta;
      case 'uri':
        // parse URI fragment using AgcReport conventions
        $u = explode('/', $p['uri']) + [null, null, null, null, null, null];
        if (!$u[0]) {
          throw new Exception("empty URI");
        }
        $meta = AgcReport::get($u[0], $report_mode, $u[2], $u[3], $u[4], $u[5]);
        return $meta;
      default:
        return false;
    }
  }

  /**
   * Load a set of templateable lists for a given context
   * todo: expand this so that the lists are context aware
   * @deprecated
   */
  public static function templateable(array $params = []) {
    // load every list:
    $saved_lists =  self::rawLoadWhere();
    // decode defaults:
    foreach ($saved_lists AS &$rec) {
      $rec['defaults'] = json_decode($rec['defaults'], true);
    }
    //    echo 'xxxx' .json_encode($saved_lists);
    return $saved_lists;
  }

  /**
   * Load a set of lists for a given context.
   *
   * This is a core function in Rocket
   *
   * todo: expand this so that the lists are context aware
   * @params - elements of "context" to which the system should respond
   * @return array of list buttons
   * - name
   * - list
   * - (deletable) - boolean
   * - (emailable) - boolean
   */
  public static function applicable(array $params) {
    $defaults = [
      'election' => false,
      'my_branches' => false, // an array of my branches (generally only 1)
      'my_branch_exec_roles' => false, // array of my executive roles
      'me' => false, // the contact object to use
      'phone_bank_count' => false, // count of contacts allocated to 'me'
      ];
    $p = $params + $defaults;

    // get my saved buttons if I have any:
    $my_buttons = $p['me'] ? $p['me']->getSetting('buttons', []) : [];
    // flag these buttons so the UI can allow the user to request deletion:
    foreach ($my_buttons AS &$button) {
      $button['deleteable'] = true;
    }

    // turn on emailing list for Qld only:
    $emailing_enabled = (AgcPerm::currentDomain() == 'qld');

    // A set of context_lists
    $context_lists = [];

    if ($p['me']) {
      $context_lists[] = [
        'name' => 'Follow-ups',
        'filters' => [[
          'id' => 'activity',
          'filter' => [
            'status_id' => 1, //scheduled
            'record_type_id' => 3, // target
            'assignee' => $p['me']->id,
            'activity_type_id' => false
            ]]],
        // set fields per AgcContact::composeCallListSql()
        'fields' => 'follow_ups',
        // disable emailer
        'emailable' => false,
        // turn on nav bar tracking of this list:
        'track' => true,
        ];
    }

    if ($p['my_branch_exec_roles']) {
      //    'my_branches' => $my_branches,
      $context_lists[] = [
        'name' => 'Branch Members ',
        'list' => ['report' => 'mybranch', 'params' => []],
        'emailable' => $emailing_enabled,
        ];
      $context_lists[] = [
        'name' => 'Provisional Members',
        'list' => ['report' => 'mybranch', 'params' => [7]],
        'emailable' => $emailing_enabled,
        ];
      $context_lists[] = [
        'name' => 'Grace Members',
        'list' => ['report' => 'mybranch', 'params' => [3]],
        'emailable' => false,
        ];
    }

    // phone bank is supreme if it exists
    if ($p['phone_bank_count']) {
      $context_lists[] = [
        'name' => 'My Call List',
        'list' => ['report' => 'mycalllist', 'params' => []],
        ];
    }

    if ($p['my_branches']) {
      foreach ($p['my_branches'] AS $branch_id) {
        $branch = new AgcBranch($branch_id);
        $branch_title = $branch->title();
        $branch_poly = $branch->polyId();
        // example of adding a task poly report for branches:
        $context_lists[] = [
          'name' => "$branch_title Polling Day Volunteers",
          'list' => ['report' => 'taskpoly', 'params' => ['htv', $branch_poly, 'PCX']]
          ];
      }
      if ($p['election']) {
        $election_rec = $p['election']->load();
        $election_code = $election_rec['shortname'];
        $context_lists[] = [
          'name' => "$branch_title Current Election Volunteers",
          'list' => ['report' => 'taskpoly', 'params' => [$election_code, $branch_poly, 'PXC']]
          ];
      }
    }


    // load every list:
    // saved lists come from the DB and give us the option of storing list there.
    // - on reflection, this is probably a dumb idea.
    $saved_lists =  []; //self::rawLoadWhere();
    // decode defaults:
    foreach ($saved_lists AS &$rec) {
      $rec['defaults'] = json_decode($rec['defaults'], true);
    }
    $result = array_merge($my_buttons, $context_lists, $saved_lists);
    return $result;
  }

  /**
   * Instance method to generate the SQL and other meta data to build this list
   *
   * @param list_params array
   * @deprecated
   */
  public function meta($list_params) {
    $rec = $this->load();
    $l = $list_params + $rec['defaults'];
    // look up parameters if needed
    if (isset($l['election_id']) && $l['election_id']===false) {
      $l['election_id'] = AgcElection::defaultElection();
    }
    if (isset($l['poly_id']) && $l['poly_id']===false) {
      // if we need a poly_id but we get an electorate, then use the electorate's poly:
      if (isset($l['electorate_id'])) {
        $electorate = new AgcElectionElectorate($l['electorate_id']);
        $electorate_rec = $electorate->load();
        $l['poly_id'] = $electorate_rec['poly_id'];
      }
      else {
        $l['poly_id'] = AgcPerm::statePolyId();
      }
    }
    // extract paramaters for AGC report
    $ps = self::applyParameterTemplate($rec['parameter_template'], $l);
    $meta = AgcReport::get($rec['report'], 'meta', $ps[0], $ps[1], $ps[2], $ps[3]);
    return $meta;
  }

  /**
   * @deprecated - replaced by AgcQuickList
   */
  public static function get($params=array()) {
    return false
  }

}

/**
 * This class support rocket buttons
 *
 */
class AgcButton {

  /**
   * Save a button to a contact or set of contacts
   * @param array
   * - button (name, filters/list, fields)
   * - target [tag|contact_id]
   * - simplify - boolean - triggers compiling the definition into a simple raw list
   * @tested indirectly via button.save.test.inc
   */
  public static function save(array $params) {
    $defaults = [
      'simplify' => FALSE,
      'button' => array(),
      ];
    $p = $params + $defaults;
//    echo json_encode($p); exit;
    $trace = [];
    $result = [];
    // make an array of contacts from tag or single value:
    $targets = AgcContact::targetToContactArray($p['target']);
    $button = $p['button'];
    if ($p['simplify']) {
      $list = AgcQuickList::fromComboToPermitted($button, 'access election');
      $contacts = $list->getContacts();
      if (count($contacts) > 10000) {
        return ['is_error' => true,
          'error_message' => 'Sorry this list is too long.
          Please use a CiviCRM tag or group to manage this list'];
      }
      $button['filters'] = [
        ['id' => 'intersection',// outer wrapper shouldnt really be neccessary
        // but maybe some glitches if it goes :-/
        'filter' => [['id' => 'raw', 'filter' => $contacts]]]];
      unset($button['list']); // delete an old style list if one exists
      $result['button'] = $button;
    }
    $n = $skipped = 0;
    if ($errs = AgcButton::errors($button)) {
      return ['is_error' => true, 'error_message' => implode("\n", $errs)];
    }
    foreach ($targets AS $contact_id) {
      $contact = new AgcContact($contact_id);
      if (self::permittedToManageButtons($contact)) {
        $n++;
        $trace[] = "got perm on #$contact_id";
        // get the saved buttons (default empty array)
        $buttons = $contact->getSetting('buttons', []);
        // delete this button if it already exists:
        self::deleteButtonFromList($buttons, $button['name']);
        // add button and save:
        array_unshift($buttons, $button);
        $contact->setSetting('buttons', array_values($buttons));
      }
      else {
        $skipped++;
        $trace[] = "permission denied on #$contact_id";
      }
    }
    return $result + [
      'is_error' => !$n, // (error if $n == 0),
      ($n ? 'report' : 'error_message') =>
        'Button "' . $button['name'] . '" added to '. $n . ' contact' . Agc::s($n),
      'n' => $n,
      'skipped' => $skipped,
      'log' => implode("\n", $trace),
      ];
  }

  /**
   * Is this user permitted to alter this button?
   * @param object $contact AgcContact
   * @return boolean
   */
  public static function permittedToManageButtons($contact) {
    if ($contact->id == Agc::myCiviID()) {
      // use standard drupal global permissions to to permission:
      return user_access('manage own list buttons');
    }
    else {
      // use custom
      return $contact->has('manage list buttons', ['onFail'=>'return']);
    }
  }

  /**
   * Delete a button from a contact or set of contacts
   * @param array
   * - name - button name
   * - target [tag|contact_id]
   * @tested button.save.test.inc
   */
  public static function deleteButton(array $params) {
    $defaults = [
      ];
    $p = $params + $defaults;
    $trace = [];
    // make an array of contacts from tag or single value:
    $targets = AgcContact::targetToContactArray($p['target']);
    $n = $skipped = 0;
    foreach ($targets AS $contact_id) {
      $contact = new AgcContact($contact_id);
      if (self::permittedToManageButtons($contact)) {
        $n++;
        $trace[] = "got perm on #$contact_id";
        // get the saved buttons (default empty array)
        $buttons = $contact->getSetting('buttons', []);
        // delete this button if it already exists:
        self::deleteButtonFromList($buttons, $p['name']);
        // save updated list
        $contact->setSetting('buttons', array_values($buttons));
      }
      else {
        $skipped++;
        $trace[] = "permission denied on #$contact_id";
      }
    }
    return [
      'is_error' => !$n, // (error if $n == 0)
      ($n ? 'report' : 'error_message') =>
        'Button "' . $p['name'] .
        '" removed from '. $n . ' contact' . Agc::s($n),
      'n' => $n,
      'skipped' => $skipped,
      'log' => implode("\n", $trace),
      ];
  }

  /**
   * Validate a button
   * @param array button definition
   * @return array of errors or empty array if none
   * @todo make this validation strong
   * @tested indirectly via button.save.test.inc
   */
  public static function errors($button) {
    $errs = [];
    if (strlen($button['name'])<4) {
      $errs[] = "button name is too short";
    }
    if ($button['name'] != filter_xss($button['name'])) {
      $errs[] = "illegal characters in name";
    }
    return $errs;
  }

  /**
   * Remove a button from a set by name
   * @param array buttons list of buttons (by reference)
   * @param string button name
   * @tested indirectly via button.save.test.inc
   */
  public static function deleteButtonFromList(&$buttons, $name) {
    foreach($buttons AS $i => $button) {
      if ($button['name'] == $name) {
        unset($buttons[$i]);
      }
    }
  }

}

/**
 * Ideas
 * - key - a string that uniquely identifies this list
 * - "QIF" filters array of ['id'=> x, 'filter' => y]
 * - combolist - an AGC report combined with standard intersection set of filters
 *
 * @tested
 */
class AgcQuickList {

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
   * @params array
   * - filter|filters - must supply one of these
   */
  function __construct(array $params) {
    $defaults = [
      'filters' => false, // set of filters with assumed outer intersection
      'filter' => false, // individual QIF (id+filter) array
      ];
    $p = $params + $defaults;
    //    echo AgcDev::getBacktrace();
    //echo 'constructing with: $' . json_encode($params) . '$';
    // reference to custom file cache system
    $this->book = new AgcBook(self::BOOKNAME);
    $input = 0; // parameter validation counter
    if ($p['filters'] !== false) {
      // the standard list is an intersection list
      $this->filter = [[
        'id' => 'intersection',
        'filter' => $params['filters'],
        ]];
      $input++;
    }
    if ($p['filter']) {
      $this->filter = [$params['filter']];
      $input++;
    }
    if ($input !== 1) {
      throw new Exception(
        "must supply one of 'filter' or 'filters' - supplied $input");
    }
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
   * Convert an untrusted (eg via web API) polymorphic list and optional quick
   * filters to an AgcQuickList object applying a specified permission.
   *
   * This signature of "list + permission" has #futurerole.
   *
   * @param combolist array (untrusted)
   * - list - optional polymorphic list definition
   * - filter - optional QIF filter
   * - filters - optional array of QIF filters (for intersecting)
   * @param string $permission eg 'access election',
   * @returns AgcQuickList object
   */
  public static function fromComboToPermitted($combolist, $permission) {
    // extract just 'list' and 'filters' properties (to prevent injection):
    $list_definition = AgcQuickList::extractComboProperties($combolist);
    // apply permissions and build quick list:
    return AgcQuickList::comboListToQuickList([
      'enforce_permissions' => TRUE,
      'permission' => $permission,
      ] + $list_definition);
  }

  /**
   * Convert a polymorphic list and optional quick filter(s) to an AgcQuickList
   * object
   *
   * @param array
   * - list - optional polymorphic list definition
   * - filter(s) - optional QIF filter(s)
   * - enforce_permissions boolean (TRUE default from comboListToFilter())
   * - permission string ('access election' default from comboListToFilter())
   * @returns AgcQuickList object
   * @tested via quick
   */
  public static function comboListToQuickList($params = []) {
    $defaults = [];
    // merge params in with defaults
    $p = $params + $defaults;
    $p['format'] = 'full';
    $combo = AgcQuickList::comboListToFilter($p);
    $quick_list = new AgcQuickList(['filters' => $combo['filters']]);
    // todo is this json encoding too expensive to do in prod??
    $quick_list->trace('meta: ' . json_encode($combo['meta']));
    return $quick_list;
  }

  /**
   * Extract relevant properties from an array (leaving a specific set).
   *
   * Passing through this filter step allows differentiation between trusted
   * and untrusted params.
   *
   * @param array
   * - list - optional polymorphic list definition
   * - filters - optional array of QIF filters (for intersecting)
   * - filter - optional single QIF filter
   * @returns array
   */
  public static function extractComboProperties($value) {
    return Agc::filter($value, ['list', 'filters', 'filter']);
  }

  /**
   * Convert a single QIF filter to a filters array.
   *
   * @param $filter array
   * @returns array filters of QIF filters
   */
  public static function filterToFilters($filter) {
    return $filter['id'] == 'intersection'
      ? $p['filter']['filter']
      : [$filter];
  }

  /**
   * Convert a filters array to a single QIF filter
   *
   * @param $filters array of QIF filters (with implied intersection)
   * @returns single filter
   */
  public static function filtersToFilter($filters) {
    return count($filters) == 1
      ? $filters[0] // intersection of single set is itself!
      : ['id' => 'intersection', 'filter' => $filters];
  }

  /**
   * Convert a list, with optional quick filters to an AgcQuickList object
   *
   * This function adds a permission filter by default.
   *
   * If no list is specified than a permission filter is added. (Otherwise we
   * assume the AgcReport logic perform permission filtering)
   *
   * @param array
   * - list - optional polymorphic list definition
   * - filters - optional QIF array with implied intersection
   * - filter - optional single QIF filter
   * - enforce_permissions - boolean
   * - format - filters | full
   * @returns array QIF of filters array (or [filters, meta] in full format)
   * @tested via quick
   */
  public static function comboListToFilter($params = []) {
    $defaults = [
      'list' => false,
      'filters' => false,
      'filter' => false,
      'enforce_permissions' => true, // fixme - must set at service level
      'permission' => 'access election',
      'format' => 'filters',
      ];
    // merge params in with defaults
    $p = $params + $defaults;
    $filters = [];
    $meta = false;
    if ($p['list']) {
      // unpack the AgcReport (or whatever it is)
      $meta = AgcList::buildPolymorphicMeta(
        ['acl' => false] + // skip access control in AGC report as we'll add a filter
        $p['list']);
      // attempt to make a quick filter array out of the meta data
      $filters = self::listMetaToQuickFilters($meta);
      if (!$filters) {
        //echo "metaa:" . json_encode($meta);
        // no quick filter so run the sql to pull out the IDs
        if (!isset($meta['contact_id'])) {
          throw new Exception ('supplied list did not include a contact_id field');
        }
        $filters = [[
          'id' => 'raw',
          'filter' => AgcDB::sqlToIdIndex(
            $meta['sql'], ['field' => $meta['contact_id']])
            ]];
      }
    }
    if ($p['filters'] && $p['filter']) { // unusual to want both, but...
      $p['filters'][] = $p['filter']; // just in case
    }
    else if ($p['filter']) { // translate single:
      $p['filters'] = AgcQuickList::filterToFilters($p);
    }
    if ($p['filters']) {
      $filters = array_merge($filters, $p['filters']);
    }
    if ($p['enforce_permissions']) {
      // add the permission filter:
      $permission_filter = [
        'id' => 'acl',
        'filter' => [
          'uid' => AgcPerm::myDrupalID(),
          'permission' => $p['permission'],
        ]
      ];
      $filters[] = $permission_filter;
    }
    switch ($p['format']) {
    case 'filters':
      return $filters; // traditional bare format
      break;
    case 'full': // fuller format for debugging
      return ['filters' => $filters, 'meta' => $meta];
      break;
    default:
      throw new Exception('bad format');
    }

  }

  /**
   * Build a set of "quick" filters that represents the same logic as an
   * AgcReport EXCLUDING the permission filter.
   *
   * This is the secondary interface between the QIF engine and the older
   * "AgcReport" (tokenised SQL) library.
   *
   * It relies on the parameter types defined in each list. Only some reports
   * can be translated to quick filters.
   *
   * @returns a array|false
   * @tested via comboListToQuickList()
   * @deprecated
   */
  public static function listMetaToQuickFilters($meta) {
    // these are the AgcReports that we currently have logic in place
    $report_white_list = ['allpoly', 'taskpoly', 'fptaskpoly', 'taskpolyfull'];
    $request = $meta['request']; // this is what this list is really based on
    if (!in_array($request['report'], $report_white_list)) {
      return false;
    }
    $params = ['p1', 'p2', 'p3', 'p4'];
    $filters = [];
    foreach ($params AS $param) {
      if ($type = Agc::hasValue($meta, $param)) {
        switch ($type) {
        case 'poly':
          $filters[] = ['id' => $type, 'filter' => $request[$param]];
            break;
        case 'task':
          if ($request[$param] != '_') { // skip wild card task codes
            $filters[] = ['id' => $type, 'filter' => $request[$param]];
          }
          break;
        case 'emsstatus': // this one was renamed (doh)
          $filters[] = ['id' => 'primary', 'filter' => $request[$param]];
            break;
        default:
          throw new Exception('bad param type: ' . $filter_id);
        }
      }
    }
    return $filters;
  }

  /**
   * Translate a traditional AGC Report status filter into SQL
   *
   * This function bridges to the first generation PCX!flag!filters logic
   * @param string $filter
   * @returns array
   * - sql
   * - field name (contact_id)
   */
  public function statusFilter($filter) {
    $filter_params = ['enforce_status_permissions' => false];
    $bits = AgcReport::contactFilter($filter, $filter_params);
    // need to benchmark doing this separately
    $bits['where'][] = "NOT (c.is_deleted OR c.is_deceased)";
    return [
      'sql' =>
        "SELECT c.id FROM civicrm_contact c " . AgcReport::bitsToSqlBody($bits),
      'contact_id' => 'id'];
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
    case 'acl': // get the perms for this user
      if (!$filter['uid'] || !$filter['permission']) {
        throw new Exception('incomplete permission filter');
      }
      return [
        'sql' => AgcPerm::contactSql($filter),
        'contact_id' => 'contact_id'
        ];
    case 'poly':
      return [
        'sql' => AgcContact::sqlFilter([
          'mode' => 'bounds',
          'poly_id' => $filter,
        ]),
        'contact_id' => 'contact_id'
        ];
    case 'member':
      return [
        'sql' => AgcContact::sqlFilter( // look up mode and submode
          AgcReport::memberFlagToFilterMode($filter)),
        'contact_id' => 'contact_id'
        ];
    case 'branch':
      return AgcBranch::filter($filter);
    case 'activity':
      return AgcContact::activityFilterSql(['format' => 'filter'] + $filter);
    case 'event':
      return AgcContact::eventFilterSql(['format' => 'filter'] + $filter); // #eventfeature_todo
    case 'mailing':
      return AgcContact::mailingFilterSql(['format' => 'filter'] + $filter);
    case 'organisation':
      return AgcContact::organisationTypeFilter($filter);
    case 'pod':
      return AgcGhs::podFilter($filter);
    case 'profile':
      return AgcContact::profileSql(['format' => 'filter'] + $filter);
    case 'roster':
      return AgcContact::rosterSqlFilter($filter);
    case 'group': // todo - this could be a custom filter and bit quicker
      $filter_string = AgcDb::arrayToSQLList($filter);
      if (preg_match('/[^0-9,]/', $filter_string)) { // fixme this test belongs in the end-function
        throw new Exception('iillegal characters in filter');
      }
      // use the legacy tag filter
      return AgcQuickList::statusFilter("!!g$filter_string");
    case 'tag': // todo - this could be a custom filter and bit quicker
      $filter_string = AgcDb::arrayToSQLList($filter);
      if (preg_match('/[^0-9,]/', $filter_string)) { // fixme this test belongs in the end-function
        throw new Exception('iillegal characters in filter');
      }
      // use the legacy tag filter
      return AgcQuickList::statusFilter("!!t$filter_string");
    case 'status': // todo - this could be a custom filter and bit quicker
      $filter_string = (is_array($filter) ? implode($filter) : $filter);
      if (preg_match('/[^A-Z]/', $filter_string)) { // fixme this test belongs in the end-function
        throw new Exception('illegal characters in filter');
      }
      return AgcQuickList::statusFilter($filter_string);
    case 'primary': // this has become the primary EMS filter
      return AgcQuickList::statusFilter($filter);
    case 'all': // this is occasionally required for negation '
      return [
        'sql' => "SELECT id FROM civicrm_contact",
        'contact_id' => 'id'
        ];
    case 'empty_set':
      return [
        'sql' => "SELECT -1 AS entity_id",
        'contact_id' => 'entity_id'];
    case 'task':
      return AgcContact::taskFilterSql($filter);
    case 'phone':
      return AgcContact::withPhoneSql(['format' => 'filter'] + $filter);
    case 'email':
      return AgcContact::withEmailFilter($filter);
    case 'user':
      return AgcPerm::roleFilter(
        Agc::filter($filter, ['poly_id', 'min_overlap', 'rids']));
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

