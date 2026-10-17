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

  function unavailable(roots) {
    roots.forEach((root) => {
      if (!root.isConnected || root.querySelector('.actools-math-status')) return;
      const notice = document.createElement('p');
      notice.className = 'actools-math-status';
      notice.setAttribute('role', 'status');
      notice.textContent = Drupal.t('Mathematics could not be typeset. The editable equation text is shown.');
      root.prepend(notice);
    });
  }

  function render(roots) {
    const math = window.MathJax;
    if (!math || typeof math.typesetPromise !== 'function' || !math.startup || !math.startup.promise) {
      unavailable(roots);
      return;
    }
    Promise.resolve(math.startup.promise)
      .then(() => {
        const connected = roots.filter((root) => root.isConnected);
        if (connected.length) return math.typesetPromise(connected);
      })
      .catch(() => unavailable(roots));
  }

  Drupal.behaviors.actoolsLessonMath = {
    attach(context) {
      const matching = [];
      if (context.matches && context.matches('.actools-lesson, .actools-chapter')) matching.push(context);
      if (context.querySelectorAll) matching.push(...context.querySelectorAll('.actools-lesson, .actools-chapter'));
      const roots = once('actools-math', matching);
      if (!roots.length) return;
      // Drupal normally attaches after all libraries execute. A single final
      // load check covers delayed, blocked or missing entry scripts. Late AJAX
      // roots on an already loaded page receive the same outcome immediately.
      // We never poll or alter the stored/rendered TeX on a failure.
      if ((window.MathJax && typeof window.MathJax.typesetPromise === 'function') || document.readyState === 'complete') render(roots);
      else window.addEventListener('load', () => render(roots), { once: true });
    },
  };
})(Drupal, once);
