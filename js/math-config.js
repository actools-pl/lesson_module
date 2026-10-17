/**
 * Pinned, locally served MathJax. This file and tex-chtml must not be aggregated:
 * the current script URL is the trusted base for local components and fonts.
 */
(function (Drupal, once) {
  'use strict';

  const script = document.currentScript;
  if (!script || !script.src) return;
  const base = new URL('../libraries/mathjax/', script.src).href;
  // The module is the sole math renderer for its own reader; do not silently
  // replace a renderer another integration has already initialised.
  if (window.MathJax && window.MathJax.typesetPromise) return;

  window.MathJax = {
    loader: {
      paths: { mathjax: base.replace(/\/$/, ''), sre: `${base}sre` },
      load: ['a11y/assistive-mml', 'ui/safe'],
    },
    tex: {
      inlineMath: [['\\(', '\\)']],
      displayMath: [['\\[', '\\]']],
      processEscapes: true,
      tags: 'none',
      maxBuffer: 20000,
    },
    output: {
      font: 'mathjax-newcm',
      fontPath: `${base}actools-fonts/%%FONT%%`,
      displayOverflow: 'scroll',
    },
    chtml: {
      fontURL: `${base}actools-fonts/mathjax-newcm/chtml/woff2`,
      dynamicPrefix: `${base}actools-fonts/mathjax-newcm/chtml/dynamic`,
    },
    options: {
      enableAssistiveMml: true,
      skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'annotation', 'annotation-xml'],
      safeOptions: { allow: { URLs: 'safe', classes: 'none', cssIDs: 'none', styles: 'none' } },
    },
    startup: { typeset: false },
  };

  Drupal.behaviors.actoolsLessonMath = {
    attach(context) {
      const roots = once('actools-math', '.actools-lesson, .actools-chapter', context);
      if (!roots.length) return;
      const render = () => {
        if (!window.MathJax || !window.MathJax.typesetPromise || !window.MathJax.startup.promise) return;
        window.MathJax.startup.promise.then(() => window.MathJax.typesetPromise(roots)).catch(() => {
          roots.forEach((root) => {
            if (root.querySelector('.actools-math-status')) return;
            const notice = document.createElement('p');
            notice.className = 'actools-math-status';
            notice.setAttribute('role', 'status');
            notice.textContent = Drupal.t('Mathematics could not be typeset. The editable equation text is shown.');
            root.prepend(notice);
          });
        });
      };
      // Drupal normally attaches after all libraries execute. The load listener
      // also covers a delayed MathJax script without polling or external calls.
      if (window.MathJax && window.MathJax.typesetPromise) render();
      else window.addEventListener('load', render, { once: true });
    },
  };
})(Drupal, once);
