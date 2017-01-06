# Introduction

This CiviCRM extension allows API clients to compose infinitely complex Boolean
logic trees out of API calls that generate contact ID lists.

The extension allows caching of each sub-list via a standard CiviCRM cache
object.

The code is ready for alpha testing and comments on the code are very welcome.

# CLIF

CLIF is short for "Contact List Interchange Format". [See the Google Doc notes](https://docs.google.com/document/d/1S9LqLJfqCEVY8UAIWY5UNuTRRvnWC2zCPVqC7kwcKdk/edit?usp=sharing) for non-developmental documentation.

The purpose of the CLIF engine is to support the exchange of high-performance,
    flexible contact list (i.e. group) definitions. It provides a
    better-defined abstraction for smart groups and advanced searches and has
    the potential for more efficient SQL that that makes better use of indices.

The primary benefits of CLIF over the current Advanced Search screen of CiviCRM
are that it supports complex Boolean constructs and interfaces with a caching
system.

Each CLIF node is a simple object (in the JSON sense) with a `type` and a
`params` property. The type property defines the overall behaviour.

This extension currently supports the following CLIF node types:

- `union`, `intersection` and `not` - each accepting an array of CLIF nodes
- `api3` - wrapper around the V3 CiviCRM API (entity, action and params)
- `raw` - enumerated list of contact IDs
- `empty` - the null set
- `all` - every contact in the database

The nested use of `union` and `intersection` allows composition of lists of
clauses into flexible trees:

    "clif": {
      "type": "union",
      "params": [
        { "type": "api3",
          "params": {
            "entity": "EntityTag",
            "action": "get",
            "params": {
                "tag_id": {"IN": ["Major Donor"]},
                "entity_table": "civicrm_contact"}}},
        { "type": "api3",
          "params": {
            "entity": "EntityTag",
            "action": "get",
            "params": {
                "tag_id": {"IN": ["Volunteer"]},
                "entity_table": "civicrm_contact" }}}]}


   { "type": "intersection",
     "params": [
       { "type": "api3",
         "params": {
           "entity": "EntityTag",
           "action": "get",
           "params": {"tag_id": {"IN": ["Volunteer"]}, "entity_table": "civicrm_contact"}}},
       { "type": "api3",
         "params": {
           "entity": "GroupContact",
           "action": "get",
           "params": {"group_id": {"IN": ["Administrators", "Newsletter Subscribers"]}, "status": "Added"}}},
       { "type": "union",
         "params": [
           { "type": "api3",
             "params": {
               "entity": "GroupContact",
               "action": "get",
               "params": {"group_id": {"IN": ["Advisory Board"]}, "status": "Added"}}},
           { "type": "api3",
             "params": {
               "entity": "EntityTag",
               "action": "get",
               "params": {"tag_id": {"IN": ["Major Donor"]}, "entity_table": "civicrm_contact"}}}]}]
   },


This example is non-standard but illustrates a deeper Boolean tree:

    {"type": "intersection",
     "params": [
        {"type": "status","params": ["C", "P","X"]},
        {"type": "email", "params": {"type": "usable"}},
        {"type": "union",
          "params": [
            {"type": "tag", "params": ["529"]},
            {"type": "not",
             "params": [{"type": "phone","params": {"type": "any"}}]
            }]}]}


# Testing

Because Erich is clueless and currently on CiviCRM 4.6 for a bit longer you
need to declare an environment variable so phpunit test codes can find the bits
of CiviCRM it needs:

    declare -x CIVICRM_ROOT="[[your module path]]/civicrm"
    declare -x CIVICRM_TEST_CONFDIR="[[your settings directory path]]"

With that hack you can then
run the unit tests of core classes with phpunit from within the root directory:

    phpunit tests

or

    phpunit tests --color

If you like live testing set up the `npm` `watch` package to run all the tests
when a file changes:

    ln $(which phpunit) bin/phpunit -s
    npm install

Then you can run tests continually with:

    npm test:watch

The `tests/first_gen/ClifApiTest.php` file contains the most illuminating set
of tests.  These simply count the records returned by the API call made against
the `MODULE_ROOT/civicrm/sql/civicrm_generated.mysql` database

# Example implementation

Within the `historic/protype` folder are some of the source files that
demonstrate the first implementation in php and angular. These are provided
merely to add substance to the discusion of how best to implement the CLIF
notion into CiviCRM. Unfortunately, while this version is active use our
servers we do not currently have an adequately anonymised version of our
production system that we can make available to demonstrate the UI.

CLIF was formerly known as QIF, potentially short for "Quick ID + Filter" format
or  "Query interchange
format" depending on your mood. If looking at the prototype code be aware that
`id` has become `type` in CLIF, and that `filter` has become `params`.

All Erich's initial work in `historic/protype` is currently copyright by the
Australian Greens although we expect it to be released fully under a permissive
licence very shortly.
