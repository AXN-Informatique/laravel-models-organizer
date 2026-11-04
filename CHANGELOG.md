Changelog
=========

1.2.0 (2026-11-04)
------------------

- Fix traits used by a class other than a model, or listed in `ignored_traits`, being deleted as unused
- Fix comments and multi-line attributes above a moved method being cut off (`/* */`, `//`)
- Fix methods lost by the second model sharing a trait with another one
- Fix kept traits still using a deleted trait, aliases included
- Fix traits holding properties, constants or other traits being deleted
- Fix `--dry-run` creating directories and announcing actions the real run does not perform
- A trait also used outside the organized models is left untouched
- The "kept trait" message lists the remaining members
- Added a test suite (Pest, Testbench)
- Added the documentation in `docs/` and a Laravel Boost guideline


1.1.0 (2026-09-15)
------------------

- Added `ignored_traits` option


1.0.1 (2026-06-12)
------------------

- Fix namespace


1.0.0 (2026-06-12)
------------------

- First release
