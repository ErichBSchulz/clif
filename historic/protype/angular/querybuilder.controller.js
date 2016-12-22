// copyright Australian Greens 2014-2016
angular.module('queryBuilderControllers', [ 'RecursionHelper']
    ).
// // Config step: //
config(['$stateProvider', function($stateProvider) {
  // set up the route:
  $stateProvider.
    state('querybuilder', {
      url: '/querybuilder?filter',
      templateUrl: 'querybuilder.html',
      controller: 'queryBuilderCtrl',
      controllerAs: 'vm',
      reloadOnSearch: false, // needed so can do two way binding with url param
    });
}])
// a bunch of utilities for services
.service('queryBuilderService',
    ['agcHttpService', "startUpService",
    function (agcHttpService, startUpService) {

  // return true for "collection" type filters
  // fixme really belongs in the generic service
  var isBoolean = this.isBoolean = function(filter) {
    return _.includes(['intersection', 'union', 'not'], filter.id);
  };

  // preprocess by adding "open" property
  var openAll = this.openAll = function(filter) {
    filter.open = true;
    if (isBoolean(filter)) {
      _.each(filter.filter, function(subfilter) {
        if (subfilter) {
          openAll(subfilter);
        }
      });
    }
  };

  // strip all the junk properties of filter
  // leaves only tree of:
  // - id
  // - filter
  var cleanClone = this.cleanClone = function(source, recursing) {
    var result = {id: source.id};
    if (!recursing) {
      // we are at the root
      if (result.id == 'root') {
        result.id = 'intersection';
      }
    }
    if (isBoolean(result)) { // recurse down branch
      result.filter = [];
      _.each(source.filter, function(subfilter) {
        if (subfilter && !subfilter.deleted) {
          result.filter.push(cleanClone(subfilter, true));
        }
      });
    }
    else { // just take the leaf:
      result.filter = source.filter;
    }
    return result;
  };

  // Make a default QIF clause given a set of metadata
  // ie process the pre-cooked filter types
  this.defaultFilter = function(meta) {
    var result = _.cloneDeep(meta.default_filter);
    result.open = true;
    console.log('result', result);
    return result;
  };

  // Extra a QIF filter from a list definition
  // eg a saved list
  this.listFilter = function(meta) {
    var result;
    var filters = _.cloneDeep(meta.filters);
    _.forEach(filters, function(filter) {
      filter.open = true;
      console.log('filter', filter);
    });
    if (filters.length > 1) {
      result = {id: 'intersection', filter: [filters], open: true};
    }
    else {
      result = filters[0];
    }
    console.log('result', result);
    return result;
  };

  // debug starter:
  var test_data  = [{
    id: 'intersection',
    filter: [
      {id: 'activity', filter: {start: 0, end: 96, activity_type_id: "32"}},
      {id: 'raw', filter: {"32": 1, "523":1}},
      {id: 'event', filter: {participation_class:"+", event_id:[468]}},
      {id: 'mailing', filter: {mode: 'opened', mailing_id: []}},
      {id: 'roster', filter: {
        election_id:startUpService.defaultElection(), time:'pollingday', mode:'rostered'}},
      {id: 'poly', filter: [8320]},
      {id: 'group', filter: [120]},
      {id: 'union', filter:[
        {id: 'status', filter: ['P', 'C', 'X']},
        {id: 'member', filter: 'i'},
        {id: 'task', filter:
          {election:[startUpService.defaultElection()],
            value: ['Y', 'E'],
            task:['DN','PH']}},
        {id: 'task', filter: {}},
        {id: 'tag', filter: ["495","528"]},
      ]},
  ]}, {
    "id": "union",
    "filter": [
      {"id": "tag", "filter": ["445"]},
      {"id": "tag", "filter": ["445"]},
      {"id": "activity", "filter": {activity_type_id: "32"}}]}
  ];

  // debug starter:
  test_data  = [{
    id: 'intersection',
    filter: [
      {id: 'raw', filter: {78420: 1, 309249: 1, 24990:1}},
  ]},
  ];

  this.getTestData = function(n) {
    openAll(test_data[n]);
    return test_data[n];
  };

  this.getClauseMeta = function() {
    var meta = [
     {
        type: 'poly',
        label: 'Geographic',
        default_filter: {id: 'poly', filter: {}},
        blah: 'x'},
     {
        type: 'task',
        label: 'Task',
        default_filter: {id: 'task', filter: {
          election:[startUpService.defaultElection()],
          value: ['Y', 'E'],
          task: ['DN','PH'],
        }},

        blah: 'x'},
     {
        type: 'email',
        label: 'Email',
        default_filter: {id: 'email', filter: {type: 'usable'}},
        blah: 'x'},
     {
        type: 'phone',
        label: 'Phone',
        default_filter: {id: 'phone', filter: {type: 'any'}},
        blah: 'x'},
     {
        type: 'activity',
        label: 'Activity',
        default_filter:
          {id: 'activity',
          filter: {start: 0, end: 96, activity_type_id: "32"}},
     },
     {
        type: 'event',
        label: 'Event',
        default_filter: {id: 'event', filter: {}},
        blah: 'x'},
     {
        type: 'mailing',
        label: 'Mailing',
        default_filter: {id: 'mailing', filter:{}},
        blah: 'x'},
     {
        type: 'tag',
        label: 'Tag',
        default_filter: {id: 'tag', filter: []},
        blah: 'x'},
     {
        type: 'status',
        label: 'Status',
        default_filter: {id: 'status', filter: ['P', 'C', 'X']},
        blah: 'x'},
     {
        type: 'member',
        label: 'Member',
        default_filter: {id: 'member', filter: 'i'},
        blah: 'x'},
     {
        type: 'roster',
        label: 'Roster',
        default_filter: {
          id: 'roster', filter: {
            election_id:startUpService.defaultElection(),
            time:'pollingday', mode:'rostered'}},
        blah: 'x'},
     {
        type: 'pod',
        label: 'Pod',
        default_filter: {
          id: 'pod', filter: {role: 'member'}},
        blah: 'x'},
     {
        type: 'user',
        label: 'User',
        default_filter: {
          id: 'user', filter: {poly_id: [], min_overlap: 80}},
        blah: 'x'},
     {
        type: 'skill',
        label: 'Skill',
        default_filter: {id: 'profile', filter: {
          table: 'skill',
          term_id: [],
          attributes: [
            {attribute: 'volunteer_availability', lo: "3", hi: "9"},
            {attribute: 'self_rated_proficency', lo: "0", hi: "9"},
            {attribute: 'qualification', lo: "0", hi: "9"},
          ]
       }},
        blah: 'x'},
     {
        type: 'interest',
        label: 'Interest',
        default_filter: {id: 'profile', filter: {
          table: 'interest',
          term_id: [],
          attributes: [
            {attribute: 'interest_level', lo: "3", hi: "9"},
            {attribute: 'qualification', lo: "0", hi: "9"},
            {attribute: 'email_preference', lo: "0", hi: "9"},
            {attribute: 'self_rated_knowledge', lo: "0", hi: "9"},
            {attribute: 'policy_engagement', lo: "0", hi: "9"},
          ]
        }},
        blah: 'x'},
     {
        type: 'group',
        label: 'Group',
        default_filter: {id: 'group', filter: []},
        blah: 'x'},
//     {
//        type: 'donor',
//        label: 'Donor',
//        default_filter: {id: 'donor', filter: 8320},
//        enabled: startUpService.has('view all contacts in domain'),
//        blah: 'x'},
     {
        type: 'intersection',
        label: 'AND',
        default_filter: {id: 'intersection', filter: []},
        blah: 'x'},
     {
        type: 'union',
        label: 'OR',
        default_filter: {id: 'union', filter: []},
        blah: 'x'},
     {
        type: 'raw',
        label: 'List',
        default_filter: {id: 'raw', filter: []},
        blah: 'x'},
    ];
    if (!startUpService.show_new_profile()) {
      _.remove(meta, {type: 'interest'});
      _.remove(meta, {type: 'skill'});
    }
    return meta;
  };

  // The widget controllers are basically all the same,
  // this function handles the generic constructions
  // returns an angular controller definition
  // (individual directives can over-ride these defaults)
  this.makeDirective = function(name) {
    return {
      restrict: "E",
      scope: {clause: '='},
      templateUrl: "filter" + name.toLowerCase() + ".html",
      controller: "filter" + name + "Ctrl",
      controllerAs: 'vm',
      bindToController: true,
      link: function(scope) {
        // standard link function, simply grabs vm.options for the filter
        scope.$watch('vm.options', function(data){
          scope.vm.clause.filter = data;
        }, true);
      }
    };
  };

  // get the list from the server
  // return a promise
  this.getList = function(root) {
    var request_filters = root.id == 'union' ? [root] : root.filter;
    var request = {
      entity: 'list',
      params: {
        mode: 'get',
        filters: request_filters
    }};
    console.log('root', root);
    console.log('request_filters', request_filters);
    return agcHttpService.post(request);
  };

}])
.filter("filterIsNotSame", function() { // register new filter
  // the role of this filter is to exclude "AND" filters from "AND" lists etc
  return function(items, type) { // filter arguments
    return _.reject(items, 'type', type);
  };
})
// controller for query builder
.controller('queryBuilderCtrl', [
    '$location', '$state', '$scope', '$stateParams',
    'agc', 'queryBuilderService', 'state',
 function(
    $location, $state, $scope, $stateParams,
    agc, queryBuilderService, state
         ) {

  var me = this;

  // watch the query the user is making and keep a clean copy
  $scope.$watch(
    function() {return me.root_clause;},
    function(root_clause) {
      console.log('cleaning the root');
      me.clean_root_clause = queryBuilderService.cleanClone(root_clause);
      $location.search('filter', angular.toJson(me.clean_root_clause));

      if (_.isEqual(me.clean_root_clause, me.fetched_filter)) {
        me.status = 'ok';
      }
      else {
        // the list we have loaded isnt the same the one we currently have
        // defined
        me.status = 'dirty';
      }
  }, true);

  function initialise() {
    me.clausemeta = queryBuilderService.getClauseMeta();

    if ($stateParams.filter) {
      me.root_clause = angular.fromJson($stateParams.filter);
    }
    else {
      // simplest default starter filter:
      me.root_clause = {
          id: 'intersection',
          filter: [
            {id: "status", filter: ["P", "C", "X"]}
            ],
      };
    }

    queryBuilderService.openAll(me.root_clause);
    me.isDebug = state.isDebug;

    // menu for nav bar
    $scope.menu = {
      debug: {
        text: 'Toggle debugging',
        order: 10,
        icons: ["gears"],
        method: state.toggleDebug
      },
      test_data0: {
        text: 'Set filter to test data',
        order: 10,
        icons: ["gears"],
        method: function() {
          me.root_clause = queryBuilderService.getTestData(0);
        }
      },
      test_data1: {
        text: 'Set filter to test data (root union)',
        order: 10,
        icons: ["gears"],
        method: function() {
          me.root_clause = queryBuilderService.getTestData(1);
        }
      },
    };
  }

  initialise();

  // transfer our list to catch state
  this.catchList = function() {
    // should not be necessary to json enocode state params but appears to be
    $state.go('listcatch', {
      filter: angular.toJson(me.clean_root_clause, false)
    });
  };

  //
  $scope.previewing = function() {
    console.log('previewing');
    if (me.status=='dirty') {
      me.viewList();
    }
  };

  // get the list from the server
  this.viewList = function() {
    me.status = 'fetching';
    var fetched_filter = _.cloneDeep(me.clean_root_clause);
    queryBuilderService.getList(fetched_filter).then(
      function(data) {
        // this is the filter we used to generate this list:
        me.preview_clause = fetched_filter;
        me.status = 'ok';
        console.log('controller success. data:', data);
        me.data = data; },
      function(result) {
        me.status = 'failed';
        console.log('in controller failed:', result);
        me.data = 'failed';
        }
     );
  };

  // transfer our list to catch state
  this.cacheFlush = function() {
    var list = me.clean_root_clause;
    console.log('flushing list', list);
    me.flushing++;
    agc.cacheFlush(list.filter).then(
      function(data) {
        me.flushing--;
        alert('Server has flushed ' + data.count + ' cache sets');
        console.log('controller success. data:', data);
      },
      function(result) {
        me.flushing--;
        alert('sorry, something bad happened');
        console.log('in controller failed:', result);
      }
    );
  };

  // counter for the button spinner
  this.flushing = 0;



}])
//// typeahaed
.directive("agcTypeAhead", [function() {
  return {
    restrict: "E",
    scope: {type: '@', filter: '@', values: '='},
    templateUrl: "agctypeahaed.html",
    controller: "typeAheadCtrl",
    controllerAs: 'vm',
    bindToController: true,
  };
}])
.controller('typeAheadCtrl', [
    'queryBuilderService', 'agcHttpService',
    function(queryBuilderService, agcHttpService) {
  console.log('starting type aheadcontroller', this);
  this.serverFind = function(key) {
    var filter = this.filter || '-';
    console.log('filter', filter);
    return agcHttpService.serverFind(this.type + '.ems8', filter, key);
  };

  // item selected by user in autocomplete
  this.Selected = function(entity) {
    // add to the list
    this.values.push(entity);
    // clear the search box
    this.search = "";
  }.bind(this);

  this.delete_entity = function(entity) {
    _.pull(this.values, entity);
  };

}])
//// the Tree ////
.directive("filterCollection", ["RecursionHelper", function(RecursionHelper) {
  return {
    restrict: "E",
    scope: {root: '=', filters: '=', clausemeta: '='},
    templateUrl: "filtercollection.html",
    compile: RecursionHelper.compile,
    controller: "FillterCollectionCtrl",
    controllerAs: 'vm',
    bindToController: true,
  };
}])
.controller('FillterCollectionCtrl', [
    '$timeout', 'queryBuilderService', 'startUpService', 'state',
    function($timeout, queryBuilderService, startUpService, state) {

  var vm = this;

  console.log('starting controller', this);

  vm.lists = startUpService.lists().priority;

  this.meta = function(id) {
    switch (id) {
      case 'union':
        return {
          label: 'OR',
          help: 'where ANY of these are true'};
      case 'intersection':
        return {
          label: 'AND',
          help: 'where ALL of these are true'};
      case 'not':
        return {
          label: 'NOT',
          help: 'where this is NOT true'};
      case 'root': // an alias of 'intersection' for the view
        return {
          label: 'Where',
          help: 'where ALL of these are true'};
      default:
        return {label: 'ERROR'};
    }
  };

  this.set_decorators = function(id) {
    // set label and help
    _.assign(vm, vm.meta(id));
  };

  this.activate = function() {
    // set label and help
    vm.set_decorators(vm.filters.id);
  };

  this.add_new_clause = function(clauses, meta) {
    clauses.filter.push(queryBuilderService.defaultFilter(meta));
  };

  this.add_new_clause_from_list = function(clauses, list) {
    clauses.filter.push(queryBuilderService.listFilter(list));
  };

  this.delete_clause = function(clause) {
    clause.deleted = true;
  };

  this.negate_clause = function(clause) {
    console.log('negating', clause);
    clause.filter = [{id: clause.id, filter: clause.filter}];
    clause.id = 'not';
    clause.fred = 'fish';
    // for some reason the "open" value is overwritten,
    // so delay
    $timeout(function () {
      clause.open = true;
      clause.filter[0].open = true;
    }, 0);
  };

  // function to identify AND/OR/NOT filters
  this.isBoolean = queryBuilderService.isBoolean;

  this.isDebug = state.isDebug;

  this.toggle = _.debounce(function(clause) {
    console.log('togling', clause);
    switch (clause.id) {
      case 'union':
        clause.id = 'intersection';
        break;
      case 'intersection':
        clause.id = 'union';
        break;
      case 'not':
        if (clause.filter[0].id) {
          clause.id = clause.filter[0].id;
          clause.filter = clause.filter[0].filter;
        }
        else {
          vm.delete_clause(clause);
        }
    }
    vm.set_decorators(clause.id);
  }, 100, {leading: true, trailing: false});

  // does this filter have what it takes?
  // ie not deleted and in the modern QIF format
  this.isAddableList = function(list) {
    var insertable = (!list.deleted) && list.filters;
    return insertable;
  };

  /**
   * Generate text to display on query componenent heading bars
   */
  this.clauseTitle = function(clause) {
    // fall back to using the id in case we get hit with an unknown type
    var meta = _.defaults({},
        _.find(vm.clausemeta, {type: clause.id}),
        {label: clause.id});
    return meta.label;
  };


  this.activate();

}])
//// tags ////
.directive("filterTags", [function() {
  return {
    restrict: "E",
    scope: {clause: '='},
    templateUrl: "filtertags.html",
    controller: "filterTagsCtrl",
    controllerAs: 'vm',
    bindToController: true,
  };
}])
.controller('filterTagsCtrl', [
    "startUpService", function(
      startUpService) {

  this.state_tags = startUpService.state_tags();
  this.options = _.clone(this.clause.filter);

}])
//// groups ////
.directive("filterGroups", ["queryBuilderService",
    function(queryBuilderService) {
  // base controller
  var d = queryBuilderService.makeDirective('Groups');
  // over-ride link (export the view scope to desired format):
  d.link = function(scope) {
    scope.$watch('vm.options', function(data){
      // return a simple array of IDs
      scope.vm.clause.filter = _.pluck(data, 'id');
    }, true);
  };
  return d;
}])
.controller('filterGroupsCtrl', ["agc", function(agc) {
  // convert the id array to a [{id, label}, ...] format
  this.options = agc.idArrayToLabels('group', this.clause.filter);
 }])
//// polys ////
.directive("filterPolys", ["queryBuilderService",
    function(queryBuilderService) {
  // base controller
  var d = queryBuilderService.makeDirective('Polys');
  // over-ride link (export the view scope to desired format):
  d.link = function(scope) {
    scope.$watch('vm.options', function(data){
      // return a simple array of IDs
      scope.vm.clause.filter = _.pluck(data, 'id');
    }, true);
  };
  return d;
}])
.controller('filterPolysCtrl', ["agc", function(agc) {
  this.options = agc.idArrayToLabels('poly', this.clause.filter);
}])
//// EMS statuses ////
.directive("filterStatus", [function() {
  return {
    restrict: "E",
    scope: {clause: '='},
    templateUrl: "filterstatus.html",
    controller: "filterStatusCtrl",
    controllerAs: 'vm',
    bindToController: true,
  };
}])
.controller('filterStatusCtrl', [
    "startUpService", function(
      startUpService) {

  this.ems_statuses = startUpService.ems_statuses();
  if (!startUpService.has("view all contacts in domain")) {
    // limit options for non-state officers
    this.ems_statuses = _.omit(this.ems_statuses, ['D', 'S']);
  }
  this.select_size  = _.size(this.ems_statuses);
  this.options = _.clone(this.clause.filter);

}])
//// Member statuses ////
.directive("filterMember", [function() {
  return {
    restrict: "E",
    scope: {clause: '='},
    templateUrl: "filtermember.html",
    controller: "filterMemberCtrl",
    controllerAs: 'vm',
    bindToController: true,
  };
}])
.controller('filterMemberCtrl', [
    "startUpService", function(
      startUpService) {

  this.member_status_options = startUpService.member_status_options();
  this.options = _.clone(this.clause.filter);

}])
//// election tasks ////
.directive("filterTask", ["queryBuilderService",
    function(queryBuilderService) {
  return queryBuilderService.makeDirective('Tasks');
}])
.controller('filterTasksCtrl', [
    "startUpService", function(
      startUpService) {
  this.standard_tasks = _.clone(startUpService.standard_tasks());
  this.elections = _.clone(startUpService.elections());
  this.values = [
   {id: 'Y', label: 'Yes'},
   {id: 'E', label: 'Interested'},
   {id: 'N', label: 'No'}];
  this.options = _.clone(this.clause.filter);
  // make a sorted list of elections:
  this.elections = _.clone(startUpService.elections());
  _.forEach(this.elections, function(e) {
    e.clean_date = _.trim(e.election_date.replace('aprox', ''));
  });
}])
//// activitys ////
.directive("filterActivity", ["queryBuilderService",
  function(queryBuilderService) {
  return _.defaults({
    // over-ride link (export the view scope to desired format):
    link: function(scope) {
      scope.$watch('vm.options', function(data){
        scope.vm.clause.filter = data;
        // convert hours to days
        scope.vm.clause.filter.start = data.start_hours * 24;
        scope.vm.clause.filter.end = data.end_hours * 24;
    }, true);
  }}, queryBuilderService.makeDirective('Activitys'));
}])
.controller('filterActivitysCtrl', [function() {
  this.activity_types = {
    32: 'EMS comms log',
    form: 'Form',
    any: 'Any',
  };
  this.status_types = {
    1: 'Scheduled',
    2: 'Completed',
    3: 'Cancelled',
    4: 'Left message',
    5: 'Unreachable'
  };
  this.options = _.clone(this.clause.filter);
  this.options.start_hours = Math.round(this.options.start / 2.4) / 10;
  this.options.end_hours = Math.round(this.options.end / 2.4) / 10;
}])
//// raw ////
.directive("filterRaw", ["queryBuilderService",
  function(queryBuilderService) {
  return _.defaults({
    // over-ride link (export the view scope to desired format):
    link: function(scope) {
      scope.$watch('vm.options', function(data){
        // invert the contact list so it is as an object with properties as id
        // and values of 1:
        //
        //     ["X", "Y", "Z"] -> {"X": 1, "Y": 1, "Z": 1}
        scope.vm.clause.filter = _.zipObject(data, _.fill(_.clone(data),1));
    }, true);
  }}, queryBuilderService.makeDirective('Raw'));
}])
.controller('filterRawCtrl', [function() {
  // just take the keys:
  // {"X": 1, "Y": 1, "Z": 1} -> ["X", "Y", "Z"]
  this.options = _.keys(this.clause.filter);
}])
//// phone ////
.directive("filterPhone", ["queryBuilderService",
  function(queryBuilderService) {
  return queryBuilderService.makeDirective('Phone');
}])
.controller('filterPhoneCtrl', [function() {
  this.phone_types = {
    'any': 'any phone',
    'mobile': 'a mobile phone',
    'land': 'a landline'
  };
  this.options = _.clone(this.clause.filter);
}])
//// email ////
.directive("filterEmail", ["queryBuilderService",
  function(queryBuilderService) {
  return queryBuilderService.makeDirective('Email');
}])
.controller('filterEmailCtrl', [function() {
  this.type_options = [
    {id: 'yes', label: 'a primary email'},
    {id: 'usable', label: 'a usable primary email'},
    {id: 'unusable', label: 'a primary email we cannot use'},
    {id: 'none', label: 'no primary email'},
    {id: 'onhold', label: 'a primary email that is on hold'}
  ];
  this.options = _.clone(this.clause.filter);
}])
//// events ////
.directive("filterEvent", ["queryBuilderService",
    function(queryBuilderService) {
  return _.defaults({
    // over-ride link (export the view scope to desired format):
    link: function(scope) {
      scope.$watch('vm.options', function(data){
        scope.vm.clause.filter = data;
        // return a simple array of IDs
        scope.vm.clause.filter.event_id = _.pluck(data.events, 'id');
      }, true);
    }}, queryBuilderService.makeDirective('Events'));
}])
.controller('filterEventsCtrl', ["agc", function(agc) {
    //  SELECT concat('{id: "',id,'", name: "',label
    //  ,'", class: "',class,'"},',class)
    //  FROM civicrm_participant_status_type
    //  where is_active
  this.attendance_status_options = [
    {id: "13", name: "Invited", class: "Pending"},
    {id: "1", name: "Registered", class: "Positive"},
    {id: "17", name: "Confirmed", class: "Positive"},
    {id: "2", name: "Attended", class: "Positive"},
    {id: "14", name: "Invitation Declined", class: "Negative"},
    {id: "3", name: "No-show", class: "Negative"},
    {id: "4", name: "Cancelled", class: "Negative"},
    //{id: "5", name: "Pending from pay later", class: "Pending"},
    //{id: "6", name: "Pending from incomplete transaction", class: "Pending"},
    //{id: "7", name: "On waitlist", class: "Waiting"},
    //{id: "9", name: "Pending from waitlist", class: "Pending"},
    //{id: "12", name: "Expired", class: "Negative"},
    //{id: "15", name: "Pending in cart", class: "Pending"},
    //{id: "16", name: "To be invited", class: "Positive"},
    //{id: "18", name: "Partially paid", class: "Positive"},
    //{id: "19", name: "Pending refund", class: "Positive"},
  ];

  this.options = _.defaults({
    participation_status: ["1", "2", "17"]
  },this.clause.filter);
  this.options.events = agc.idArrayToLabels('event',
    this.clause.filter.event_id);

}])
/// mailings ////
.directive("filterMailing", ["queryBuilderService",
    function(queryBuilderService) {
  return _.defaults({
    // over-ride link (export the view scope to desired format):
    link: function(scope) {
      scope.$watch('vm.options', function(data){
        // return a simple array of IDs
        scope.vm.clause.filter = data;
        scope.vm.clause.filter.mailing_id = _.pluck(data.mailings, 'id');
      }, true);
    }}, queryBuilderService.makeDirective('Mailings'));
}])
.controller('filterMailingsCtrl', ["agc",  function(agc) {

  this.mailing_status_options = {
    opened: 'Opened',
    unopened: 'Received but did not open',
    received: 'Either opened or did not open',
  };

  this.options = _.clone(this.clause.filter);
  this.options = _.defaults({
    mode: 'received',
    mailings: agc.idArrayToLabels('mailing', this.clause.filter.mailing_id),
  },this.clause.filter);

}])
//// rosters ////
.directive("filterRoster", ["queryBuilderService",
    function(queryBuilderService) {
  return queryBuilderService.makeDirective('Rosters');
}])
.controller('filterRostersCtrl', [
    "startUpService", function(
      startUpService) {
  this.elections = _.clone(startUpService.elections());

  this.mode_options = [
    {id: 'rostered', label: 'Rostered'},
    {id: 'unrostered', label: 'Unrostered'},
    {id: 'coordinator', label: 'Coordinator'},
    {id: 'captain', label: 'Captain'},
    {id: 'setup', label: 'Set-up'},
    {id: 'packup', label: 'Pack-up'},
    {id: 'scrutineer', label: 'Scrutineer'},
    {id: 'unrostered_scrutineer', label: 'Unrostered Scrutineer'},
  ];

  this.time_options = {
    all: 'Any time',
    prepoll: 'Prepoll',
    pollingday: 'Polling day',
  };

  this.options = _.clone(this.clause.filter);
}])
//// Pods ////
.directive("filterPod", ["queryBuilderService",
    function(queryBuilderService) {
  var d = queryBuilderService.makeDirective('Pod');
  return d;
}])
.controller('filterPodCtrl', [
    "agcHttpService", "startUpService", function(
      agcHttpService, startUpService) {

  vm = this;
  vm.serverFind = agcHttpService.serverFind

  // item selected by user in autocomplete
  this.podSelected = function(pod) {
    console.log('pod', pod);
    vm.options.pod = pod.id
  }

  this.role_options = [
    {id: 'member', label: 'Member'},
    {id: 'admin', label: 'Admin'},
  ];

  this.options = _.clone(this.clause.filter);

}])
//// Users ////
.directive("filterUser", ["queryBuilderService",
    function(queryBuilderService) {
  var d = queryBuilderService.makeDirective('User')
  d.link = function(scope) {
    scope.$watch('vm.options', function(vm_options){
      // translate the 'polys' autocomplete collection to the final form:
      scope.vm.clause.filter = _.omit(vm_options, 'polys')
      scope.vm.clause.filter.poly_id = _.pluck(vm_options.polys, 'id')
    }, true)
  }
  return d
}])
.controller('filterUserCtrl', [
    'agc', "agcHttpService", "startUpService", function(
      agc, agcHttpService, startUpService) {

  vm = this
  // setup view model:
  vm.role_options = startUpService.roles()
  vm.select_size  = _.size(vm.role_options)
  vm.options = _.clone(vm.clause.filter)
  vm.options.polys = agc.idArrayToLabels('poly', vm.clause.filter.poly_id)

}])
//// skills //// & //// interests ////
.directive("filterProfile", ["queryBuilderService",
    function(queryBuilderService) {
  var d = queryBuilderService.makeDirective('Profile');
  // over-ride link (export the view scope to desired format):
  d.link = function(scope) {
    scope.$watch('vm.options', function(data){
      // return a simple array of IDs
      scope.vm.clause.filter = _.clone(data);
      // grab term_ids:
      scope.vm.clause.filter.term_id = _.pluck(data.term_id, 'id');
    }, true);
  };
  return d;
}])
.controller('filterProfileCtrl', [
    "startUpService", function(
      startUpService) {
  this.scale = startUpService.genericScale();
  this.options = _.clone(this.clause.filter);
}])
;
