# Content and extension boundaries

## Surface Tension demonstration

The optional submodule supplies original illustrative teaching content: force and energy, curved interfaces and excess pressure, capillary rise/contact angle, and a short extension on hysteresis and surface-tension gradients. It includes original raster diagrams, a worked numerical example and ungraded revealable practice. New imports consistently use S for surface tension. The diagrams have editable SVG masters and a development-only Matplotlib rebuild script under the demo assets directory; production Drupal uses the supplied PNGs. Existing demo nodes/files are not overwritten by this release.

The demonstration is a small reusable example rather than a conversion or publication of every document in `ST.zip`. Source manuscripts, permissions, scientific editorial approval and complete course sequencing remain an explicit migration project.

## Existing taxonomy

The external seed previously reviewed identifies the chapter as `PHY11_SURFACE_TENSION` and the following eight concepts. This module does not execute or modify that PHP seed.

| Code | Concept |
| --- | --- |
| `PHY11_SURF_SURFACE_FORCE` | Surface force |
| `PHY11_SURF_SURFACE_ENERGY` | Surface energy |
| `PHY11_SURF_INTERFACIAL_TENSION` | Interfacial tension |
| `PHY11_SURF_CONTACT_ANGLE` | Contact angle |
| `PHY11_SURF_EXCESS_PRESSURE` | Excess pressure |
| `PHY11_SURF_BUBBLES_DROPS` | Bubbles and drops |
| `PHY11_SURF_FREE_SURFACE_SHAPE` | Free-surface shape |
| `PHY11_SURF_CAPILLARY_RISE` | Capillary rise |

If `edu_topics` is present when the main module is enabled, native chapter/concept reference fields are created if missing. Editors choose existing terms; no code-field name or term identity is guessed. If the vocabulary is installed later, rerunning `Drupal\actools_lesson\StructureInstaller::install()` in a reviewed development update creates the optional missing references idempotently. Export that configuration normally for deployment.

## Question bank and assessments

Practice components are teaching text with optional hint, answer and solution reveals. Optional `variant_ref` (up to 255 characters) and `variant_version` (up to 64) must be nonempty strings together and are permitted only on practice. They are revision-owned editorial identifiers, retained on duplication, and are not rendered to learners. They perform no lookup or synchronization. Practice is not a question-bank record or test attempt and stores no scores.

A later assessment integration should resolve an exact external item ID, variant ID and immutable version before generating a link or rendered attempt. It should implement its own permission checks and propagate access/cache metadata. Keep examination rendering, grading, attempt state and result retention outside the lesson component JSON. Do not point at a mutable “latest variant” when reproducing a prior assigned task.

## Source preservation

For a future document importer, retain the original DOCX and OMML source, source file checksum and location, original figure, migration timestamp and scientific/editorial status. Convert approved material into editable native text/TeX/components. Web TeX does not promise a lossless OMML/DOCX round trip, and a polished reader does not substitute for editorial review. See [CONTENT_MAPPING.md](CONTENT_MAPPING.md) for separate book/UUID identities, approved asset paths and the representative-lesson acceptance gate. Scientific figures can now be attached to same-step example/practice regions, including a closed solution, without changing the storage model.
