# Manuscript identity and asset mapping

The lesson module supplies structured authoring and reading. It does not supply a general manuscript importer or a lossless Word/OMML exporter. This guide fixes the mapping decisions needed before importing the Surface Tension source; it does not convert or publish that source.

## Keep three identities distinct

| Identity | Purpose | Rule |
| --- | --- | --- |
| Source key, e.g. `ST/ch04/problem/4.3` | Identify one manuscript item across repeated preparation | Record it in the migration manifest; never infer it from current row order |
| Book label, e.g. `Problem 4.3` | Human-readable numbering and references | Retain it in the authored heading/caption, or explicit equation reference label, independently of route position |
| Drupal step/component UUID | Stable reading links, revisions and progress | Keep the saved UUID when editing or moving; use a new UUID only for an independent duplicate |

Practice can additionally retain a paired `variant_ref` and `variant_version` for a reviewed external question. These do not replace book keys or Drupal UUIDs and do not resolve a live bank. The existing `source` remains the public citation; no new step source-reference column has been introduced.

A future import manifest should record source key, source file path/checksum, lesson UUID, step UUID, component UUID, book label and editorial status. These are migration records, not newly invented module fields. Resolve cross-references through that manifest to actual saved URLs and `#component-<UUID>` anchors. The existing step URL uses its stable UUID query value. Renumbering a book is a separate editorial decision.

Do not claim that the module interprets `[ref:...]` tokens or automatically renumbers source references. Those tools are not supplied. Authored links and labels must be checked before publication.

## Figure mapping

Each image needs an approved source path, SHA-256 checksum, alternative text, caption, file identity, target component and placement. Use the approved asset's full path and checksum, not basename matching.

For the supplied archive, the twelve capillary-rise images are under `ST/plan/pass5/ch04/figures`; they are not relative to `pass14/chapters`. The first two also have demonstration copies. Verify the intended edition before choosing a duplicate.

| Source position | Current module mapping |
| --- | --- |
| Figure between explanations | Standalone scientific figure in source order |
| Figure in a worked example or question | Scientific figure attached to that example/practice prompt |
| Figure inside a hint, answer or solution | Scientific figure attached to that exact disclosure |
| Several figures in one region | Several ordinary figure components attached to that region, ordered by their position in the step |

An attached figure is still one ordinary figure component. Its existing UUID, managed-file reference and version history are preserved. The target must be an example/practice in the same step. A solution-only figure must not be moved outside the solution to simplify an import.

## Capillary-rise inventory and capacity

The inspected pass-14 source has eight concept sections plus the solved-examples section, 41 problems, 41 answers, 41 solutions, eight solved examples, twelve image references, one table and fifteen display equations. It also has four result boxes, six concept illustrations, four traps and three stretch boxes.

One initial flat grouping suggests roughly 91 components across ten steps; this is a mapping estimate, not a completed conversion. Separating 28 practice Try-yourself prompts would raise it to 119, and separating another eight worked-example Try-yourself prompts would raise it to 127. Preserving those prompts inside their parent text/disclosures is an editorial choice, not a reason to silently drop material.

The native builder permits growth up to 120 components per lesson, with 40 steps and 60 components per step. Larger existing/API-seeded content is preserved subject to storage and actual server capacity. Grouping is necessary: one step for each of the 41 problems would exceed the step bound. Keep a machine-counted inventory before seeding a lesson and use the editor's whole-form capacity report.

For the proposed pilot, split the corrected source into the approved nine Core lessons and a separate chapter-practice Resource; keep Extension lessons distinct with prerequisites. The 41-problem chapter inventory need not be forced into the representative Capillary-rise lesson: choose its agreed theory/examples and ten reviewed problems, and map the rest to Capillary arrangements or chapter practice. Large papers remain downloadable Resources until a separately implemented bank is available. The module does not infer this editorial split or import the corrected source.

## Pilot gate

Before publishing the imported chapter:

1. Freeze the source edition and choose whether Drupal or the manuscript is authoritative after cutover. Never allow two uncontrolled masters.
2. Map every source item, figure and reference without changing its book identity. Record deliberate splits or combinations.
3. Create drafts only. Verify alternative text and the intended prompt/solution placement of every figure.
4. Reconcile all expected problems, answers, solutions, equations, tables and links. The module's general meaningful-prompt check does not require an answer or solution for every example/practice component; manuscript completeness needs its own acceptance gate.
5. Save/reopen first, middle and last edits through the real editor. Measure load/save/reorder behaviour with the actual mixed lesson and configured server limits.
6. Check mathematics with the full chosen corpus, the phone layout, no-JavaScript reading, private drafts and Print. Preserve original DOCX/OMML sources and approved raster masters.

Do not run the proposed architecture report's import/export commands against this release: those commands do not exist. There is no Paragraphs dependency, migration or implementation in this work.
