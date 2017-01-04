# CLIF

CLIF is short for "Contact List Interchange Format". [See the Google Doc notes](https://docs.google.com/document/d/1S9LqLJfqCEVY8UAIWY5UNuTRRvnWC2zCPVqC7kwcKdk/edit?usp=sharing) for non-developmental documentation.

CLIF was formerly known as QIF, potentially short for "Quick ID + Filter" format
or  "Query interchange
format" depending on your mood.

The purpose of the CLIF engine is to support the exchange of high-performance,
    flexible contact list (i.e. group) definitions. It provides a
    better-defined abstraction for smart groups and advanced searches and has
    the potential for more efficient SQL that that makes better use of indices.

The primary benefits of CLIF over the current Advanced Search screen of CiviCRM
are that it supports complex Boolean constructs and interfaces with a caching
system.

CLIF allows defining complex list definition in tree simple tree structure:

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

This CLIF will combine the members of OSSC and OSUG:

      {"type": "union",
        "params": [
          {"type": "pod", "params": {"pod": 266114}},
          {"type": "pod", "params": {"pod": 256219}}
      ]}

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


# Current status

Erich made a thing for the Australian Greens.

We know it works. We think it has potential to be even better, and we
think others in the CiviCRM community may benefit.

All Erich's work is currently copyright by the Australian Greens although we
expect it to be released fully under a permissive licence very shortly.

# Example implementation

Within the `historic/protype` folder are some of the source files that
demonstrate the first implementation in php and angular. These are provided
merely to add substance to the discusion of how best to implement the CLIF
notion into CiviCRM.

# Open questions

* overall architecture
* support API V3 or V4
