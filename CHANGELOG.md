# Changelog

## 1.2.0
- New look: search card with gradient hero, status quick filters (All / Active / Suspended / Terminated / Cancelled), example chips, collapsible operator help.
- Results: coloured status pills and row accents, client avatars, overdue badges (amber, red after 30 days), matches highlighted in the text, IPs as click-to-copy chips, per-status summary, domains and clients side by side.
- `/` focuses the search box; responsive (less important columns hide on narrow screens).
- 91 tests.

## 1.1.0
- Fix: Enter in the search box went to the dashboard (the form posted to `index.php`); it now posts to `addonmodules.php`.
- English UI by default, Ukrainian translation included (`lang/`).
- Quoted operator values: `product:"FTP storage"`.
- Faster multi-word search (custom fields and add-ons are joined once instead of one subquery per word).
- Test suite (`tests/run.php`, 80 checks, uses temporary tables only).

## 1.0.0
- First version: one search box over every field of a service, SQL backend, optional Elasticsearch backend.
