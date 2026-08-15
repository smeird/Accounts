// Dynamically loads the shared navigation menu into pages and ensures icon support.
// Helper to bypass browser caching when needed.
function fetchNoCache(input, init = {}) {
  init = init || {};
  init.cache = 'no-store';
  return window.fetch(input, init);
}
window.fetchNoCache = fetchNoCache;

const apiBase = document.body?.dataset?.apiBase || '../php_backend/public';
const frontendBase = document.body?.dataset?.menuBase || (window.location.pathname.includes('/frontend/') ? '' : 'frontend/');
const resolveFrontendAsset = path => `${frontendBase}${path}`;

const attachSidebarSearchHandler = (root = document) => {
  const sidebarSearchForm = root.getElementById('sidebar-search-form');
  if (!sidebarSearchForm || sidebarSearchForm.dataset.bound === 'true') return;

  sidebarSearchForm.dataset.bound = 'true';
  sidebarSearchForm.addEventListener('submit', e => {
    e.preventDefault();
    const term = root.getElementById('sidebar-search')?.value.trim();
    if (term) {
      window.location.href = `${resolveFrontendAsset('search.html')}?value=${encodeURIComponent(term)}`;
    }
  });
};

  if (!document.getElementById('cards-css')) {
    const cardLink = document.createElement('link');
    cardLink.id = 'cards-css';
    cardLink.rel = 'stylesheet';
    cardLink.href = resolveFrontendAsset('cards.css');
    document.head.appendChild(cardLink);
  }

  if (!document.getElementById('sidebar-css')) {
    const sidebarLink = document.createElement('link');
    sidebarLink.id = 'sidebar-css';
    sidebarLink.rel = 'stylesheet';
    sidebarLink.href = resolveFrontendAsset('sidebar.css?v=20260815-sidebar-refresh');
    document.head.appendChild(sidebarLink);
  }


  if (!document.getElementById('theme-professional-css')) {
    const themeLink = document.createElement('link');
    themeLink.id = 'theme-professional-css';
    themeLink.rel = 'stylesheet';
    themeLink.href = resolveFrontendAsset('css/theme-professional.css');
    document.head.appendChild(themeLink);
  }

  const hasSpecialistPageDesign = document.body.matches([
    '.landing-page',
    '.instant-page',
    '.project-page',
    '.transaction-page',
    '.budget-page',
    '.yearly-page'
  ].join(','));

  if (!hasSpecialistPageDesign) {
    document.body.classList.add('site-system-page');
    if (!document.getElementById('site-system-css')) {
      const siteSystemLink = document.createElement('link');
      siteSystemLink.id = 'site-system-css';
      siteSystemLink.rel = 'stylesheet';
      siteSystemLink.href = resolveFrontendAsset('site_system.css');
      document.head.appendChild(siteSystemLink);
    }
  }

  // Load the shared type scale after page-specific styles so operational text
  // remains readable across specialist dashboards and legacy pages alike.
  if (!document.getElementById('site-typography-css')) {
    const typographyLink = document.createElement('link');
    typographyLink.id = 'site-typography-css';
    typographyLink.rel = 'stylesheet';
    typographyLink.href = resolveFrontendAsset('typography.css?v=20260811-readable-scale');
    document.head.appendChild(typographyLink);
  }

  const PROFESSIONAL_THEME_KEY = 'professionalThemeEnabled';
  const professionalThemeEnabled = localStorage.getItem(PROFESSIONAL_THEME_KEY) === 'true';
  document.body.classList.toggle('theme-professional', professionalThemeEnabled);

  document.body.classList.add('pt-4');
  let colorScheme = 'indigo';
  let siteName = 'Finance Manager';
  const colorMap = {
    indigo: {600: '#4f46e5', 700: '#4338ca', gradient: 'linear-gradient(160deg, rgba(79, 70, 229, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    blue:   {600: '#2563eb', 700: '#1d4ed8', gradient: 'linear-gradient(160deg, rgba(37, 99, 235, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    green:  {600: '#059669', 700: '#047857', gradient: 'linear-gradient(160deg, rgba(5, 150, 105, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    red:    {600: '#dc2626', 700: '#b91c1c', gradient: 'linear-gradient(160deg, rgba(220, 38, 38, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    purple: {600: '#9333ea', 700: '#7e22ce', gradient: 'linear-gradient(160deg, rgba(147, 51, 234, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    teal:   {600: '#0d9488', 700: '#0f766e', gradient: 'linear-gradient(160deg, rgba(13, 148, 136, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    orange: {600: '#ea580c', 700: '#c2410c', gradient: 'linear-gradient(160deg, rgba(234, 88, 12, 0.16) 0%, rgba(255, 255, 255, 0.96) 72%, #ffffff 100%)'},
    sunset: {600: '#f97316', 700: '#ec4899', gradient: 'linear-gradient(135deg, rgba(249, 115, 22, 0.16) 0%, rgba(236, 72, 153, 0.12) 52%, rgba(255, 255, 255, 0.98) 100%)'},
    ocean: {600: '#0891b2', 700: '#2563eb', gradient: 'linear-gradient(135deg, rgba(8, 145, 178, 0.16) 0%, rgba(37, 99, 235, 0.12) 52%, rgba(255, 255, 255, 0.98) 100%)'},
    'violet-rose': {600: '#8b5cf6', 700: '#e11d48', gradient: 'linear-gradient(135deg, rgba(139, 92, 246, 0.16) 0%, rgba(225, 29, 72, 0.12) 52%, rgba(255, 255, 255, 0.98) 100%)'}
  };

  const hoverStyle = document.createElement('style');
  document.head.appendChild(hoverStyle);

  const applyColorScheme = (root = document) => {
    if (colorScheme !== 'indigo') {
      root.querySelectorAll('*').forEach(el => {
        el.classList.forEach(c => {
          if (c.includes('indigo')) {
            el.classList.remove(c);
            el.classList.add(c.replace('indigo', colorScheme));
          }
        });
      });
    }
    const colors = colorMap[colorScheme] || colorMap.indigo;
    const cssRoot = document.documentElement;
    cssRoot.style.setProperty('--brand-color-600', colors[600]);
    cssRoot.style.setProperty('--brand-color-700', colors[700]);
    cssRoot.style.setProperty('--page-title-color', colors[700]);
    cssRoot.style.setProperty('--brand-gradient', colors.gradient || `linear-gradient(135deg, ${colors[600]} 0%, ${colors[700]} 100%)`);
    hoverStyle.textContent = `
      a { transition: color 0.2s ease; }
      a:hover { color: ${colors[600]}; }
      button { transition: color 0.2s ease, background-color 0.2s ease, border-color 0.2s ease; }
    `;
  };

  const applyIconColor = (root = document) => {
    root.querySelectorAll('i').forEach(icon => {
      if (icon.closest('header')) return;
      const parent = icon.closest('button, a');
      const parentClasses = parent ? Array.from(parent.classList) : [];
      const hasExplicitParentText = parentClasses.some(c => c.startsWith('text-'));
      const darkBg = parentClasses.some(c => /^(bg-(?:black|gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-[5-9]00)$/.test(c));
      const hasColor = Array.from(icon.classList).some(c => c.startsWith('text-'));
      if (darkBg && !hasExplicitParentText) {
        icon.classList.forEach(c => { if (c.startsWith('text-')) icon.classList.remove(c); });
        icon.classList.add('text-white');
      } else if (!hasColor && !hasExplicitParentText) {
        icon.classList.add(`text-${colorScheme}-600`);
      }
    });
  };

  const styleInputs = (root = document) => {
    root.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]):not(.unstyled), select:not(.unstyled), textarea:not(.unstyled)').forEach(el => {
      if (!el.classList.contains('styled-input')) {
        el.classList.add('styled-input', 'p-2', 'border', 'rounded', 'border-gray-400');
      }
    });
  };

  // Copy aria-labels to data-tooltip attributes for custom tooltips
  const applyAriaTooltips = (root = document) => {
    root.querySelectorAll('[aria-label]').forEach(el => {
      if (!el.getAttribute('data-tooltip')) {
        el.setAttribute('data-tooltip', el.getAttribute('aria-label'));
      }
    });
  };
  applyAriaTooltips();
  styleInputs();
  const ariaObserver = new MutationObserver(mutations => {
    for (const m of mutations) {
      m.addedNodes.forEach(node => {
        if (node.nodeType === 1) {
          if (node.hasAttribute && node.hasAttribute('aria-label') && !node.getAttribute('data-tooltip')) {
            node.setAttribute('data-tooltip', node.getAttribute('aria-label'));
          }
          if (node.querySelectorAll) {
            applyAriaTooltips(node);
            styleInputs(node);
          }
        }
      });
    }
  });
  ariaObserver.observe(document.body, {childList: true, subtree: true});

  function loadFontsModule(cb) {
    if (window.applyFonts) { cb(); return; }
    const s = document.createElement('script');
    s.src = resolveFrontendAsset('js/fonts.js?v=20260811-font-weights');
    s.onload = cb;
    document.head.appendChild(s);
  }

  fetchNoCache(`${apiBase}/brand_settings.php`)
    .then(r => r.json())
    .then(f => {
      siteName = f.site_name || siteName;
      colorScheme = f.color_scheme || colorScheme;
      loadFontsModule(() => applyFonts(f));
      document.title = document.title.replace('Finance Manager', siteName);
      document.querySelectorAll('#landing-site-name, [data-landing-site-name]').forEach(el => {
        el.textContent = siteName;
      });
      applyColorScheme();
      applyIconColor();
      document.querySelectorAll('#site-title').forEach(el => el.textContent = siteName);
      document.querySelectorAll('img[alt="Finance Manager logo"]').forEach(img => {
        img.alt = `${siteName} logo`;
      });
    })
    .catch(err => {
      console.error('Brand load failed', err);
      applyColorScheme();
      applyIconColor();
    });

  // Ensure every page uses the shared PNG favicon
  if (!document.querySelector('link[rel="icon"]')) {
    const iconSvg = document.createElement('link');
    iconSvg.rel = 'icon';
    iconSvg.type = 'image/png';
    iconSvg.href = '/favicon.png';
    iconSvg.sizes = 'any';
    document.head.appendChild(iconSvg);
  }


  const menu = document.getElementById('menu');
  const menuBackdrop = document.createElement('button');
  menuBackdrop.type = 'button';
  menuBackdrop.className = 'site-menu-backdrop';
  menuBackdrop.setAttribute('aria-label', 'Close navigation');
  menuBackdrop.hidden = true;

  const toggle = document.createElement('button');
  toggle.id = 'menu-toggle';
  toggle.type = 'button';
  toggle.className = 'site-menu-toggle';
  toggle.setAttribute('aria-controls', 'menu');
  toggle.setAttribute('aria-expanded', 'false');
  toggle.setAttribute('aria-label', 'Open navigation');
  toggle.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i>';

  const setMobileMenuOpen = (open, returnFocus = false) => {
    if (!menu) return;
    menu.classList.toggle('is-open', open);
    document.body.classList.toggle('site-menu-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Navigation open' : 'Open navigation');
    menuBackdrop.hidden = !open;
    if (open) {
      window.setTimeout(() => menu.querySelector('#sidebar-search')?.focus(), 180);
    } else if (returnFocus) {
      toggle.focus();
    }
  };

  toggle.addEventListener('click', () => setMobileMenuOpen(!menu?.classList.contains('is-open')));
  menuBackdrop.addEventListener('click', () => setMobileMenuOpen(false, true));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu?.classList.contains('is-open')) {
      setMobileMenuOpen(false, true);
    }
  });
  window.matchMedia('(min-width: 768px)').addEventListener('change', event => {
    if (event.matches) setMobileMenuOpen(false);
  });
  document.body.append(menuBackdrop, toggle);

  if (menu) {
    // Add responsive classes so the navigation can toggle on small screens
    menu.classList.add(
      'flex',
      'flex-col',
      'fixed',
      'top-0',
      'bottom-0',
      'left-0',
      'overflow-y-auto',
      'z-40'
    );
    menu.classList.remove(
      'hidden',
      'md:flex',
      'md:flex-col',
      'w-64',
      'p-6',
      'overflow-y-auto',
      'bg-gradient-to-b',
      'from-white/80',
      'backdrop-blur-xl',
      'border',
      'border-white/40',
      'shadow-2xl',
      `to-${colorScheme}-100/30`
    );
    menu.classList.add('menu-surface', 'site-menu');

    fetchNoCache(resolveFrontendAsset('menu.php'))
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
        attachSidebarSearchHandler(document);
        const brand = menu.firstElementChild;
        brand?.classList.add('site-menu-brand');
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'site-menu-close';
        closeButton.setAttribute('aria-label', 'Close navigation');
        closeButton.innerHTML = '<i class="fas fa-xmark" aria-hidden="true"></i>';
        closeButton.addEventListener('click', () => setMobileMenuOpen(false, true));
        brand?.appendChild(closeButton);
        menu.querySelector('#sidebar-search-form')?.classList.add('site-menu-search');
        menu.querySelector(':scope > .space-y-4')?.classList.add('site-menu-nav');
        menu.querySelector('#user-info')?.classList.add('site-menu-user');
        if (frontendBase === 'frontend/') {
          menu.querySelectorAll('a[href]').forEach(linkEl => {
            const href = linkEl.getAttribute('href') || '';
            if (!href || href.startsWith('http://') || href.startsWith('https://') || href.startsWith('#') || href.startsWith('/')) return;
            if (href.startsWith('../')) {
              linkEl.setAttribute('href', href.replace(/^\.\.\//, ''));
            } else {
              linkEl.setAttribute('href', resolveFrontendAsset(href));
            }
          });
        }
        const titleEl = menu.querySelector('#site-title');
        if (titleEl) titleEl.textContent = siteName;
        menu.querySelectorAll('img[alt="Finance Manager logo"]').forEach(img => {
          img.alt = `${siteName} logo`;
        });
        applyColorScheme(menu);
        applyIconColor(menu);
        const userEl = menu.querySelector('#current-user');
        const iconEl = menu.querySelector('#user-icon');
        if (userEl) {
          fetchNoCache(`${apiBase}/current_user.php`)
            .then(r => (r.ok ? r.json() : Promise.reject()))
            .then(u => {
              userEl.textContent = u.username || 'Guest';
              if (u.has2fa && iconEl) {
                iconEl.classList.remove('fa-user');
                iconEl.classList.add('fa-user-shield');
              }
            })
            .catch(() => {
              userEl.textContent = 'Guest';
            });
        }
        const setSectionExpanded = (section, expanded) => {
          const button = section.querySelector('.site-menu-group-toggle');
          const list = section.querySelector('.site-menu-list');
          if (!button || !list) return;
          button.setAttribute('aria-expanded', String(expanded));
          section.classList.toggle('is-expanded', expanded);
          list.style.maxHeight = expanded ? `${list.scrollHeight}px` : '0px';
        };

        // Turn the legacy headings into accessible disclosure controls.
        menu.querySelectorAll('.group').forEach(section => {
          section.classList.add('site-menu-group');
          const heading = section.querySelector('h3');
          const list = section.querySelector('ul');
          if (heading && list) {
            const button = document.createElement('button');
            const listId = `site-menu-section-${Array.from(menu.querySelectorAll('.group')).indexOf(section) + 1}`;
            button.type = 'button';
            button.className = 'site-menu-group-toggle';
            button.setAttribute('aria-controls', listId);
            button.setAttribute('aria-expanded', 'false');
            button.innerHTML = `<span>${heading.textContent.trim()}</span><i class="fas fa-chevron-down" aria-hidden="true"></i>`;
            heading.replaceWith(button);
            list.id = listId;
            list.classList.add('site-menu-list');
            list.style.maxHeight = '0px';
            button.addEventListener('click', () => setSectionExpanded(section, button.getAttribute('aria-expanded') !== 'true'));
          }
        });

        menu.querySelectorAll('a[href]').forEach(linkEl => linkEl.classList.add('site-menu-link'));

        const themeWrap = document.createElement('div');
        themeWrap.className = 'site-menu-theme';
        themeWrap.innerHTML = `
          <label for="professional-theme-toggle">
            <span><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Professional theme</span>
            <input id="professional-theme-toggle" type="checkbox" aria-label="Toggle professional theme">
          </label>
        `;
        const userInfo = menu.querySelector('#user-info');
        if (userInfo) userInfo.before(themeWrap);
        else menu.appendChild(themeWrap);
        const themeToggle = document.getElementById('professional-theme-toggle');
        if (themeToggle) {
          themeToggle.checked = document.body.classList.contains('theme-professional');
          themeToggle.addEventListener('change', () => {
            const enabled = themeToggle.checked;
            document.body.classList.toggle('theme-professional', enabled);
            localStorage.setItem(PROFESSIONAL_THEME_KEY, String(enabled));
            document.dispatchEvent(new CustomEvent('theme-changed', { detail: { professional: enabled } }));
          });
        }

        // Close the drawer after choosing a destination on mobile.
        menu.querySelectorAll('a').forEach(a =>
          a.addEventListener('click', () => {
            if (window.matchMedia('(max-width: 767px)').matches) setMobileMenuOpen(false);
          })
        );

        // Build breadcrumb text underneath the page title
        const current = location.pathname.split('/').pop();
        const link = menu.querySelector(`a[href="${current}"]`);
        if (link) {
          link.classList.add('is-active');
          link.setAttribute('aria-current', 'page');
          link.classList.add(
            'border-l-2',
            `border-${colorScheme}-600`,
            'font-medium',
            'text-gray-900',
            `bg-${colorScheme}-50`
          );
          const activeIcon = link.querySelector('i');
          if (activeIcon) {
            activeIcon.classList.remove('text-slate-400');
            activeIcon.classList.add(`text-${colorScheme}-600`);
          }
          const activeSection = link.closest('.site-menu-group');
          if (activeSection) setSectionExpanded(activeSection, true);
          const section = activeSection?.querySelector('.site-menu-group-toggle span')?.textContent?.trim();
          const page = link.textContent.trim();
          const main = document.querySelector('main.ops-main');
          const breadcrumb = section && page ? `${section} / ${page}` : '';
          if (breadcrumb && main && typeof window.updatePageHeader === 'function') {
            window.updatePageHeader(main, { breadcrumb: breadcrumb });
          } else {
            const heading = document.querySelector('main h1');
            if (breadcrumb && heading) {
              const crumb = document.createElement('div');
              crumb.textContent = breadcrumb;
              crumb.className = `page-breadcrumb text-${colorScheme}-900`;
              heading.insertAdjacentElement('afterend', crumb);
            }
          }
        }
        // Display counter for untagged transactions in menu
        fetchNoCache(`${apiBase}/untagged_count.php`)
          .then(r => r.json())
          .then(data => {
            const total = Number(data.count || 0);
            if (total > 10) {
              const counter = menu.querySelector('#missing-tags-count');
              if (counter) {
                counter.textContent = total;
                counter.classList.remove('hidden');
              }
            }
          })
          .catch(err => console.error('Untagged count load failed', err));

        const releaseEls = document.querySelectorAll('#release-number');
        if (releaseEls.length > 0) {
          fetchNoCache(`${apiBase}/version.php`)
            .then(r => r.json())
            .then(v => {
              const version = v.version || 'unknown';
              const behind = v.behind;
              releaseEls.forEach(el => {
                let text = `v${version}`;
                if (typeof behind === 'number') {
                  text += ` (${behind} behind)`;
                }
                el.textContent = text;
              });
            })
            .catch(() => {
              releaseEls.forEach(el => {
                el.textContent = 'v?';
              });
            });
        }
      })
      .catch(err => console.error('Menu load failed', err));
  }

  const content = document.querySelector('body > div.flex');
  if (content) {
    // Prevent the document itself from scrolling so only the main content
    // panel owns vertical scrolling. This avoids double right-edge scrollbars.
    document.body.classList.add('m-0', 'h-screen', 'overflow-hidden');

    // Ensure wrapper always uses column layout on small screens with a
    // sidebar on larger displays so the menu and utility bar position
    // consistently across pages.
    content.classList.add('flex', 'flex-col', 'md:flex-row', 'min-h-screen', 'h-screen', 'overflow-hidden');
    const main = content.querySelector('main');
    if (main) {
      main.classList.add('flex-1', 'min-w-0', 'h-full', 'overflow-y-auto', 'md:ml-64', 'pt-16', 'md:pt-0');
    }
  }

  attachSidebarSearchHandler(document);

  // Apply Tailwind card styling to all sections or wrap main content in a card
  document.querySelectorAll('main').forEach(main => {
    // Only style direct child sections and allow explicit opt-out via data-no-card
    const sections = main.querySelectorAll(':scope > section');
    if (sections.length > 0) {
      sections.forEach(section => {
        if (!section.dataset.noCard) {
          section.classList.add('cards');
        }
      });
    } else {
      const pageHeader = main.querySelector(':scope > header.page-header');
      const wrapper = document.createElement('section');
      wrapper.className = 'cards';

      const nodesToWrap = [];
      main.childNodes.forEach(node => {
        if (node !== pageHeader) {
          nodesToWrap.push(node);
        }
      });

      nodesToWrap.forEach(node => {
        wrapper.appendChild(node);
      });

      if (wrapper.childNodes.length > 0) {
        if (pageHeader) {
          pageHeader.insertAdjacentElement('afterend', wrapper);
        } else {
          main.appendChild(wrapper);
        }
      }
    }
  });

  // Load page help overlay on every page
  const helpScript = document.createElement('script');
  helpScript.src = resolveFrontendAsset('js/page_help.js');
  document.body.appendChild(helpScript);

  const logoutScript = document.createElement('script');
  logoutScript.src = resolveFrontendAsset('js/auto_logout.js');
  document.body.appendChild(logoutScript);

  const tooltipScript = document.createElement('script');
  tooltipScript.src = resolveFrontendAsset('js/tooltips.js');
  document.body.appendChild(tooltipScript);

  const fullscreenScript = document.createElement('script');
  fullscreenScript.src = resolveFrontendAsset('js/chart_fullscreen.js');
  document.body.appendChild(fullscreenScript);

  if (document.body.classList.contains('site-system-page')) {
    const siteSystemScript = document.createElement('script');
    siteSystemScript.src = resolveFrontendAsset('js/site_system.js');
    document.body.appendChild(siteSystemScript);
  }
