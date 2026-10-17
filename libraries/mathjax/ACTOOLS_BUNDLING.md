# Locally bundled mathematics assets

MathJax and @mathjax/mathjax-newcm-font are pinned to npm release 4.1.3. See ACTOOLS_ASSET_MANIFEST.json for archive SHA-256 and npm integrity. Browser JavaScript, accessibility/speech assets, dynamic font tables and WOFF2 fonts are retained locally. Node-only font builds and examples were omitted. The packages declare Apache-2.0; the MathJax distribution includes the Apache licence, copied beside the font package whose npm archive omits a standalone licence file. Retained JavaScript preserves its upstream copyright notices.

Set output.fontPath to this directory plus `/actools-fonts/%%FONT%%` to avoid the default external font-package loader. Dynamic tables and WOFF2 files are available in `actools-fonts/mathjax-newcm/chtml`. Configure loader paths and speech-rule assets relative to this local directory.
