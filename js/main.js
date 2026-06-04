/* ============================================================
   ТОНКО-КРЕПКО  ·  Landing JS
   Сейчас: измерение хедера + мобильное меню. Дальше: таймер, аккордеон, Stripe.
   ============================================================ */

(() => {
  'use strict';

  // ---- Реальная высота хедера → CSS-переменная --header-h
  // Нужно, чтобы Hero мог занять ровно (100vh - header) и маркиза прижалась к низу
  // экрана без появления скролла. Пересчёт на resize и после загрузки шрифтов.
  const header = document.querySelector('.site-header');

  const setHeaderH = () => {
    if (!header) return;
    const h = header.offsetHeight;
    document.documentElement.style.setProperty('--header-h', `${h}px`);
  };

  setHeaderH();
  window.addEventListener('resize', setHeaderH);
  window.addEventListener('load', setHeaderH);
  // шрифты могут догрузиться позже — пересчитываем
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(setHeaderH);
  }


  // ---- Mobile nav toggle
  const toggle = document.querySelector('.nav-toggle');
  const siteNav = document.querySelector('.site-nav');
  if (toggle && siteNav) {
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      siteNav.classList.toggle('is-open', !expanded);
      toggle.setAttribute('aria-label', expanded ? 'Открыть меню' : 'Закрыть меню');
    });
    // Закрываем меню при клике на ссылку
    siteNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        toggle.setAttribute('aria-expanded', 'false');
        siteNav.classList.remove('is-open');
        toggle.setAttribute('aria-label', 'Открыть меню');
      });
    });
  }


  // ---- Smooth scroll для якорей (на случай старых браузеров)
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', (e) => {
      const id = link.getAttribute('href');
      if (id.length > 1) {
        const target = document.querySelector(id);
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
  });


  // ---- Scroll-triggered fade-up
  // Любой элемент с классом .fade-up подхватывается; стаггер задаётся
  // через --i в инлайн-стиле (CSS считает delay = i * 70ms).
  const fadeEls = document.querySelectorAll('.fade-up');

  if ('IntersectionObserver' in window && fadeEls.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          io.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.12,
      rootMargin: '0px 0px -60px 0px',
    });

    fadeEls.forEach(el => io.observe(el));
  } else {
    // Старый браузер или нет IO — показываем сразу, без анимации
    fadeEls.forEach(el => el.classList.add('in-view'));
  }


  // ---- Demo modal — показывается, когда бэкенда нет (статичный хостинг, GH Pages).
  // Подменяет alert о неработающей оплате на красивое UX-объяснение.
  const demoModal = document.getElementById('demo-modal');

  const showDemoModal = () => {
    if (!demoModal) return;
    demoModal.hidden = false;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => demoModal.classList.add('is-visible'));
    });
    document.body.style.overflow = 'hidden';
  };

  const hideDemoModal = () => {
    if (!demoModal) return;
    demoModal.classList.remove('is-visible');
    document.body.style.overflow = '';
    setTimeout(() => { demoModal.hidden = true; }, 360);
  };

  if (demoModal) {
    // Закрытие: backdrop, крестик, кнопка "Понятно"
    demoModal.querySelectorAll('[data-close]').forEach(el => {
      el.addEventListener('click', hideDemoModal);
    });
    // Esc
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && demoModal.classList.contains('is-visible')) {
        hideDemoModal();
      }
    });
  }



  // ---- FAQ-аккордеон
  // Несколько вопросов могут быть открыты одновременно (так удобнее читать).
  // Анимация высоты через scrollHeight → max-height с фоллбэком в "auto"
  // после завершения анимации, чтобы контент не обрезался на длинных ответах.
  const faqTriggers = document.querySelectorAll('.faq-trigger');

  faqTriggers.forEach(trigger => {
    const panel = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!panel) return;

    trigger.addEventListener('click', () => {
      const isOpen = trigger.getAttribute('aria-expanded') === 'true';

      if (isOpen) {
        // Закрываем: фиксируем текущую высоту → 0
        panel.style.maxHeight = panel.scrollHeight + 'px';
        // форсируем reflow, чтобы анимация запустилась с конкретного значения
        panel.offsetHeight;
        panel.style.maxHeight = '0px';
        trigger.setAttribute('aria-expanded', 'false');
      } else {
        // Открываем: ставим scrollHeight, после транзишена снимаем ограничение,
        // чтобы контент перестроился, если изменится размер окна.
        panel.style.maxHeight = panel.scrollHeight + 'px';
        trigger.setAttribute('aria-expanded', 'true');

        const onEnd = (e) => {
          if (e.propertyName !== 'max-height') return;
          panel.style.maxHeight = 'none';
          panel.removeEventListener('transitionend', onEnd);
        };
        panel.addEventListener('transitionend', onEnd);
      }
    });
  });


  // ---- Cookie banner
  // Показываем при первом визите, скрываем после клика "Понятно".
  // Согласие храним в localStorage — баннер больше не появится.
  // Только технические cookies — поэтому отказ не предусмотрен (нечего отключать).
  const banner = document.getElementById('cookie-banner');
  const acceptBtn = document.getElementById('cookie-banner-accept');
  const COOKIE_CONSENT_KEY = 'tk_cookie_consent_v1';

  if (banner && acceptBtn) {
    const hasConsent = (() => {
      try {
        return localStorage.getItem(COOKIE_CONSENT_KEY) === 'accepted';
      } catch (e) {
        // localStorage может быть недоступен (приватный режим, отказ от cookies на уровне браузера)
        return false;
      }
    })();

    if (!hasConsent) {
      banner.hidden = false;
      // Чуть задерживаем show чтобы баннер красиво въехал
      requestAnimationFrame(() => {
        requestAnimationFrame(() => banner.classList.add('is-visible'));
      });
    }

    acceptBtn.addEventListener('click', () => {
      try {
        localStorage.setItem(COOKIE_CONSENT_KEY, 'accepted');
      } catch (e) { /* приватный режим — баннер не сохранится, появится снова */ }

      banner.classList.remove('is-visible');
      setTimeout(() => { banner.hidden = true; }, 360);
    });
  }

})();
