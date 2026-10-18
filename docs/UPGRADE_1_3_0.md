# Review corrections — Actools Lessons 1.3.0

This release adopts the verified, bounded improvements from the uploaded `lesson_module_Verification_Report(1).md` and `Surface_Tension_Review_and_Consolidated_Plan (1)(1).md`. Those reports describe 1.1.0 and an earlier proposed architecture; the starting code here is the published 1.2.0. Its chapter-ownership, safe Unpublish, pending-adoption and attached-figure corrections remain in place. Layout Builder remains optional shared presentation. No Paragraphs implementation, dependency or migration is included.

## Applied findings

| Finding | Result |
| --- | --- |
| D1: client-disabled external inputs cause a technical rejection | Conditional external controls may legitimately be omitted. Owned lesson scalars, signed identity inventory and whole-form worst-case input budgeting remain protected. Covers the same Form API states pattern used by automatic-alias widgets; a real installed Pathauto configuration still needs staging acceptance. |
| D2: empty upload controls incorrectly consume `max_file_uploads` | Empty slots no longer disable saving or builder actions. PHP's count applies to actual files in one request. File uploads being disabled, combined-body limits, file-size validation and figure access rules remain enforced. |
| D3: numeric strings compare as equal | Canonical JSON comparison keeps scalar types and list order. Changing TeX `10` to `1e1` advances its teaching version, clears review and makes old learner marks require a revisit after publication. Object-key ordering alone is not a teaching change. |
| D4: one uncached revision load per step | Retained-history checks batch once per field-list save and use fresh saved state for each operation. No process-wide stale entity cache is added. |
| D5: non-figure file IDs accepted | Any `fid` on a non-figure is rejected, including null/zero values. Trusted importers with invalid old data must correct it deliberately; no data is silently discarded. |
| D6: inconsistent snapshot key | Revision signatures bind the private key, hash salt, account and node context. Older open forms must be reloaded; rejected submissions retain their unsaved values for recovery. |
| D7: unrelated POSTs trigger completeness checks | Token checks apply while this form processes input. Oversized request-body protection remains before that gate. |

The final independent audit also reproduced a historical-private-draft edge: changing only an old step title could let an older nondefault revision reuse its former teaching version over newer published content. The batch guard now compares both the immutable source and the current public teaching/version. It rejects stale identities while an unchanged native historical restore still receives a fresh version. Earlier revisions remain intact and an old learner read mark cannot silently become current again.

## Small additions using the existing model

- Equation components accept an optional explicit `equation_number` label. It is plain text, identical in Guided, Read all and Print, and stays fixed on reorder. Authors maintain unique book labels and use UUID links; there is no automatic numbering or reference-token interpreter.
- Install/update creates a missing `actools_lesson_diagram` image style: proportional scaling within 1600 × 1600, no crop or upscale. Same-name operator configuration is preserved. Original links and access/cache metadata remain. This is one bounded derivative, not a responsive `srcset` system.
- Practice accepts paired `variant_ref` and `variant_version` strings. These preserve a reviewed external identity within native revisions and independent copies. They do not fetch, synchronize or render a live bank question; learner citations still use `source`.
- New demo imports use S consistently in text, TeX and the three original diagrams. Editable vector masters and a development-only rebuild script accompany the PNGs. Existing imported content/files are not rewritten.

The guides now document inline TeX, text-format permissions, configuration import order, actual upload counts and the nine-lesson pilot/chapter-practice split. Source approval, asset provenance and manuscript completeness remain editorial gates.

## Update an existing site

Back up the site and platform code, select the reviewed release commit and rehearse the platform's deployment procedure on staging. On BOA use the new-platform process in [INSTALLATION.md](INSTALLATION.md); do not update a live shared platform in place.

1. Deploy the complete module directory.
2. In the intended site's approved Drush context, run `drush updb -y` then `drush cr`. Update 11001 creates missing diagram-style configuration. There is no field/table schema migration and no uninstall/reinstall.
3. Review/export the resulting configuration through the site's normal process. On a new destination, follow [configuration import order](INSTALLATION.md#configuration-import-order).
4. Reload open lesson forms after copying any unsaved work. Snapshot signatures have changed.
5. Check conditional aliases, real figure upload/save/reopen, numeric TeX edits/review, learner revisit status, diagram originals, labels and Print with the actual theme/database/PHP limits.

Content, UUIDs, revisions, files and user progress are retained. New optional JSON properties require compatible code when editing them; use the site's consistent code/data rollback procedure. No full manuscript converter, source-reference database column, taxonomy rewrite, question-bank entity, larger editor limit or content-model reownership is introduced.

See [VALIDATION.md](../VALIDATION.md) for executed checks and their scope. Production BOA/Percona concurrency, installed theme, full scientific corpus and access-policy/CSP acceptance remain staging work.
