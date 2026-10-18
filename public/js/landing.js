/**
 * landing.js - the public landing page: stat count-up and the mobile menu.
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Stat count-up ---------- */

  function easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
  }

  function format(value, decimals, suffix) {
    return value.toFixed(decimals) + suffix;
  }

  function countUp(el, i) {
    var target   = parseFloat(el.dataset.target) || 0;
    var decimals = parseInt(el.dataset.decimals, 10) || 0;
    var suffix   = el.dataset.suffix || '';
    var duration = 1500 + i * 80;

    el.textContent = format(0, decimals, suffix);

    setTimeout(function () {
      var start = null;
      function frame(now) {
        if (start === null) start = now;
        var t = Math.min((now - start) / duration, 1);
        el.textContent = format(target * easeOutCubic(t), decimals, suffix);
        if (t < 1) requestAnimationFrame(frame);
      }
      requestAnimationFrame(frame);
    }, 480 + i * 90);
  }

  var values = document.querySelectorAll('.stat__value');

  // The server renders final values, so reduced motion / no observer needs nothing.
  if (!reduceMotion && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        countUp(entry.target, Array.prototype.indexOf.call(values, entry.target));
        io.unobserve(entry.target);
      });
    }, { threshold: 0.25 });

    values.forEach(function (el) { io.observe(el); });
  }

  /* ---------- Mobile menu ---------- */

  var burger  = document.querySelector('.burger');
  var menu    = document.getElementById('mobile-menu');
  var overlay = document.querySelector('.menu-overlay');
  if (!burger || !menu || !overlay) return;

  function setOpen(open) {
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menu.hidden = !open;
    overlay.hidden = !open;
    document.body.classList.toggle('menu-open', open);
  }

  burger.addEventListener('click', function () {
    setOpen(burger.getAttribute('aria-expanded') !== 'true');
  });

  overlay.addEventListener('click', function () { setOpen(false); });

  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) setOpen(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.hidden) {
      setOpen(false);
      burger.focus();
    }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 720 && !menu.hidden) setOpen(false);
  });
})();
