# QIF

QIF is potentially short for "Quick ID + Filter" format or  "Query interchange
format" depending on your mood.

The purpose of the QIF engine is to support the exchange of high-performance,
    flexible contact list (i.e. group) definitions. It provides a
    better-defined abstraction for smart groups and advanced searches and has
    the potential for more efficient SQL that that makes better use of indices.

The primary benefits of QIF over the current Advanced Search screen of CiviCRM
are that it supports complex Boolean constructs and interfaces with a caching
system.

QIF allows defining complex list definition in tree simple tree structure:

    {"id": "intersection",
     "filter": [
        {"id": "status","filter": ["C", "P","X"]},
        {"id": "email", "filter": {"type": "usable"}},
        {"id": "union",
          "filter": [
            {"id": "tag", "filter": ["529"]},
            {"id": "not",
             "filter": [{"id": "phone","filter": {"type": "any"}}]
            }]}]}

This QIF will combine the members of OSSC and OSUG:

      {"id": "union",
        "filter": [
          {"id": "pod", "filter": {"pod": 266114}},
          {"id": "pod", "filter": {"pod": 256219}}
      ]}

# Current status

Erich made a thing for the Australian Greens.

We know it works. We think it has potential to be even better, and we
think others in the CiviCRM community may benefit.

All Erich's work is currently copyright by the Australian Greens although we
expect it to be released fully under a permissive licence very shortly.

# Example implementation

Within the `historic/protype` folder are some of the source files that
demonstrate the first implementation in php and angular. These are provide
merely to add substance to the discusion of how best to implement the QIF
notion into CiviCRM.

# Mistakes made

Calling the filter type `id` - that is confusing with the autoincrement MySql
table IDs. `type` would have been better.

Combolists and all the pre-qif formats

Initially making `filters` the preferred interchange format. Much cleaner to
use a single filter, which maybe an `intersection` or `union` type.

# Open questions

* overall architecture
* support API V3 or V4
* change id to type ?
