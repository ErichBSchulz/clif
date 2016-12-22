// copyright Australian Greens 2014-2016
"use strict"

// core server service
ems8Ap.service('agc', ['agcHttpService', 'startUpService',
    function(agcHttpService, startUpService) {

  var agc = this

  this.idArrayToLabels = function (type, ids) {
    // fixme this function needs to work with a local cache and http service to
    // retrieve and store the labels for entities
    var result = []
    _.forEach(ids, function(id) {
      result.push({id: id, label: type + " #" + id})
    })
    return result
  }

  // combine two lists (union)
  this.listUnion = function (A, B) {
    return simplify({id: "union", filter: [A, B]})
  }

  // combine two lists (intersection)
  this.listIntersect = function (A, B) {
    return simplify({id: "intersection", filter: [A, B]})
  }

  // subtact two lists (in A but not in B)
  this.listMinus = function (A, B) {
    return simplify({
      id: "intersection",
      filter: [
        A,
        {id: "not", filter: [B]}
    ]})
  }

  // filters takes a list with implied intersection but frequently it is a
  // single item
  this.filtersToFilter = function (filters) {
    return (filters.length === 1)
      ? filters[0]
      : {id: "intersection", filter: filters}
  }

  // simplify list:
  var simplify = this.simplify = function (list) {
    if (list.id == 'union' || list.id == 'intersection') {
      if (_.keysIn(list.filter).length === 1) {
        var simple = simplify(list.filter[0])
        return simple
      }
      // return list
      var complex = [], intersection = false, union = {}, raw_not = {}
      _.forEach(list.filter, function(filter) {
        var s
        var f = simplify(filter)
        if (f.id == 'raw') {
          if (list.id == 'union') {
            _.assign(union, f.filter)
          }
          else {
            intersection = (intersection === false)
            ? f.filter // first list
            : intersectLists(intersection, f.filter)
          }
        }
        else if(f.id == 'not') {
          s = simplify(f.filter[0])
          if (s.id == 'raw') {
            _.assign(raw_not, s.filter) // union this list with any previous nots
          }
          else {
            complex.push(f) // treat this list as complex
          }
        }
        else {
          complex.push(f)
        }
      })
      var base = (list.id == 'union') ? union : intersection
      var nots = _.keysIn(raw_not)
      // if we have an intersection set and some nots, but no complex values
      if (list.id == 'intersection' && _.keysIn(base).length && !complex.length && nots.length) {
        // then subtract out the nots:
        base = _.omit(base, nots) // delete the nots from base list
        nots = [] // clear nots since we've dealt with them
      }
      var haves = {id: 'raw', filter: base}
      if (nots.length) { // weren't able to handle nots so insert back into complex filters
        complex.push({id: 'not', filter: [{id: 'raw', filter: raw_not}]})
      }
      if (complex.length) {
        complex.push(haves)
        list.filter = complex // update the filter
      }
      else {
        list = haves
      }
    }
    return list
  }

  function intersectLists(A, B) {
    // not overly optimised but this shouldn't get very heavy traffic
    return idsToQifRaw(_.intersection(_.keysIn(A), _.keysIn(B)))
  }

  // convert [123, 342] to {123: 1, 342: 1}
  function idsToQifRaw(ids) {
    return _.zipObject(ids, _.fill(_.clone(ids),1))
  }

  // Convert contact collection to raw QIF filter
  // @param [integer]|[collection objects]
  // @return {} - QIF
  this.contactListToRaw = function(contacts) {
    var ids = _.isPlainObject(contacts[0])
      ? _.pluck(contacts, 'contact_id') // take the id field
      : contacts
    return {
      id: 'raw',
      filter: idsToQifRaw(ids)
    }
  }

  // return a promise
  // params
  // - contact_id
  // - list
  //   - name
  //   - filters or list: new or old style list definition
  //   - fields (not currently used but potentially can configure ui states)
  // - simplify: boolean if true triggers a snapshot
  this.saveButton = function (p) {
    var data = {
      entity:"list",
      params:{
        mode: "savebutton",
        target: {contact_id: p.contact_id},
        simplify: p.simplify,
        button: _.pick(p.list, ['name', 'filters', 'list', 'fields'])
      }}
    return agcHttpService.post(data)
  }

  // this function deletes a button from a users list
  // params
  // - name
  // - contact_id
  // returns a promise
  // (this should really use the RESTfullish interface, but meh)
  this.deleteButton = function(p) {
    var data = {
      entity: 'deletebutton',
      params: {
        name: p.name,
        target: {contact_id: p.contact_id}
      },
    }
    return agcHttpService.post(data, {})
  }

  // return a promise
  // NB this is a poor patern and needs some tweaking (both request and result)
  this.countList = function (list) {
    var params = _.merge(
        _.pick(list, ['list', 'filters']),
        {mode: 'count'})
    var data = {
      entity: 'c',
      id: 'me',
      field: 'call_list',
      value: params
    }
    return agcHttpService.post(data)
  }
  // ask the server to flush this list
  // return a promise
  this.cacheFlush = function(filter) {
    var request = {
      entity: 'list',
      params: {
        mode: 'clearcache',
        filters: filter
    }}
    return agcHttpService.post(request)
  }

  var lists_cache
  // get a massaged set of lists with 'editable' and 'simple' flags set
  this.lists = function() {
    if (!lists_cache) {
      lists_cache = startUpService.lists().priority
      _.forEach(lists_cache, augment_list_meta)
    }
    return lists_cache
  }

  var augment_list_meta = this.augment_list_meta = function (list) {
    _.defaults(list, {
      // is this a modern QIF list?
      editable: (list.filters && list.filters.length == 1),
      simple: isSimpleList(list),
      spinners: 0, // actions happening
      count: -1, // count
    })
  }

  // return the actual list from a simple list...
  // @returns false if not a simple list or array
  //
  // copes with single level nesting in an intersection clause
  this.simpleList = function(filters) {
    if (filters.filter.length !== 1) {
      return false
    }
    else if (filters[0].id == 'raw') {
      return filters[0].filter
    }
    else if (filters[0].id == 'intersection' &&
      filters[0].filter.length == 1 && filters[0].filter[0].id == 'raw') {
      return filters[0].filter[0].filter
    }
    else {
      return false
    }
  }


  // test to see if a list meets the criteria for being simple
  // (made up of a single list)
  // copes with single level nesting in an intersection clause
  function isSimpleList(list) {
    // a list is simple if it is just a raw list of contacts
    // for legacy reasons the list maybe nested in an "intersection"
    return list.filters && list.filters.length == 1 && (
        list.filters[0].id == 'raw' ||
          (list.filters[0].id == 'intersection' &&
            list.filters[0].filter.length == 1 &&
            list.filters[0].filter[0].id == 'raw')
        )
  }

  this.addButton = function (button) {
    var lists = startUpService.lists().priority
    augment_list_meta(button)
    lists.unshift(button)
  }

  // updates the status object as http calls are made:
  // currently this is just for the "schedule follow-ups"
  // the navbar renders this object
  // as we expand the repetoire we we'll need to include enough metadata to
  // enable effective rendering
  this.refreshState = function (status) {
    // find the trackable list and loop over them
    var lists = _.where(startUpService.lists().priority, {track: true})
    _.forEach(lists, function(list, key) {
      var list_definition = _.pick(list, ['name', 'filters', 'list', 'fields'])
      var state = {
        icons: 'fa-circle-o-notch fa-spin',
        state: 'fetching',
        title: 'stand-by',
        list: list_definition,
        count: undefined
      }
      status[key] = state
      // get the count then update the state:
      agc.countList(list_definition).then(
        function (response) {
          state.icons = undefined
          state.class = 'label-success'
          state.state = 'loaded'
          state.title = response.commands.count //fixme
            ? 'you have some follow-ups' //fixme
            : 'no follow-ups' // fixme
          state.count = response.commands.count
        },
        function (error) {
          console.log('error', error)
          state.icons = 'fa-warning'
          state.state = 'error'
          state.title = 'sorry server is confusing me'
          state.class = 'text-warning'
          state.count = undefined
        }
      )

    })
  }

  /**
   * Left join c2 to c1 on `on` property.
   * That is include all records in c1, compbined with c2 where a match exists
   */
  this.leftJoin = function(c1, c2, on) {
    var c2_indexed = _.indexBy(c2, on)
    _.forEach(c1,function(object) {
      _.assign(object, c2_indexed[object[on]])
    })
  }

}])

