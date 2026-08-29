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
    sidebarLink.href = resolveFrontendAsset('sidebar.css?v=20260825-sitewide-ipad-shell');
    document.head.appendChild(sidebarLink);
  }


  if (!document.getElementById('theme-professional-css')) {
    const themeLink = document.createElement('link');
    themeLink.id = 'theme-professional-css';
    themeLink.rel = 'stylesheet';
    themeLink.href = resolveFrontendAsset('css/theme-professional.css?v=20260824-paper-density');
    document.head.appendChild(themeLink);
  }

  if (!document.getElementById('interface-preferences-css')) {
    const preferencesLink = document.createElement('link');
    preferencesLink.id = 'interface-preferences-css';
    preferencesLink.rel = 'stylesheet';
    preferencesLink.href = resolveFrontendAsset('css/interface-preferences.css?v=20260829-expanded-branding');
    document.head.appendChild(preferencesLink);
  }

  if (!document.getElementById('hero-density-css')) {
    const heroDensityLink = document.createElement('link');
    heroDensityLink.id = 'hero-density-css';
    heroDensityLink.rel = 'stylesheet';
    heroDensityLink.href = resolveFrontendAsset('css/hero-density.css?v=20260829-financial-briefs');
    document.head.appendChild(heroDensityLink);
  }

  const hasSpecialistPageDesign = document.body.matches([
    '.landing-page',
    '.instant-page',
    '.project-page',
    '.transaction-page',
    '.budget-page',
    '.yearly-page',
    '.forecast-page'
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
    typographyLink.href = resolveFrontendAsset('typography.css?v=20260824-table-hierarchy');
    document.head.appendChild(typographyLink);
  }

  const PROFESSIONAL_THEME_KEY = 'professionalThemeEnabled';
  const savedProfessionalTheme = localStorage.getItem(PROFESSIONAL_THEME_KEY);
  const professionalThemeEnabled = savedProfessionalTheme === 'true';
  document.body.classList.toggle('theme-professional', professionalThemeEnabled);

  let backdropStrength = 'balanced';
  const applyAppearancePreferences = (preferences = {}) => {
    const density = ['compact', 'comfortable', 'roomy'].includes(preferences.interface_density)
      ? preferences.interface_density : 'comfortable';
    const corners = ['soft', 'balanced', 'square'].includes(preferences.corner_style)
      ? preferences.corner_style : 'soft';
    const motion = preferences.motion_preference === 'reduced' ? 'reduced' : 'standard';
    const accentBar = ['hairline', 'small', 'medium', 'large'].includes(preferences.accent_bar_size)
      ? preferences.accent_bar_size : 'medium';
    const pageHeader = ['small', 'medium', 'large'].includes(preferences.page_header_size)
      ? preferences.page_header_size : 'medium';
    backdropStrength = ['calm', 'balanced', 'vivid'].includes(preferences.backdrop_strength)
      ? preferences.backdrop_strength : 'balanced';

    ['compact', 'comfortable', 'roomy'].forEach(value => document.body.classList.remove(`ui-density-${value}`));
    ['soft', 'balanced', 'square'].forEach(value => document.body.classList.remove(`ui-corners-${value}`));
    ['hairline', 'small', 'medium', 'large'].forEach(value => {
      document.body.classList.remove(`ui-accent-bar-${value}`, `ui-page-header-${value}`);
    });
    document.body.classList.remove('ui-motion-reduced');
    document.body.classList.add(
      `ui-density-${density}`,
      `ui-corners-${corners}`,
      `ui-accent-bar-${accentBar}`,
      `ui-page-header-${pageHeader}`
    );
    document.body.classList.toggle('ui-motion-reduced', motion === 'reduced');
    document.documentElement.dataset.interfaceDensity = density;
    document.documentElement.dataset.cornerStyle = corners;
    document.documentElement.dataset.backdropStrength = backdropStrength;
    document.documentElement.dataset.motionPreference = motion;
    document.documentElement.dataset.accentBarSize = accentBar;
    document.documentElement.dataset.pageHeaderSize = pageHeader;

    const usePaper = savedProfessionalTheme === null
      ? preferences.surface_style === 'paper'
      : savedProfessionalTheme === 'true';
    document.body.classList.toggle('theme-professional', usePaper);
    const themeToggle = document.getElementById('professional-theme-toggle');
    if (themeToggle) themeToggle.checked = usePaper;
  };
  window.applyAppearancePreferences = applyAppearancePreferences;

  document.body.classList.add('pt-4');
  let colorScheme = 'indigo';
  let brandPrimary = '#4f46e5';
  let brandSecondary = '#4338ca';
  let siteName = 'Finance Manager';
  const tailwindColorSchemes = new Set(['blue', 'green', 'red', 'purple', 'teal', 'orange', 'slate', 'emerald', 'cyan', 'rose', 'amber']);

  const hoverStyle = document.createElement('style');
  document.head.appendChild(hoverStyle);

  const applyColorScheme = (root = document) => {
    if (colorScheme !== 'indigo' && tailwindColorSchemes.has(colorScheme)) {
      root.querySelectorAll('*').forEach(el => {
        el.classList.forEach(c => {
          if (c.includes('indigo')) {
            el.classList.remove(c);
            el.classList.add(c.replace('indigo', colorScheme));
          }
        });
      });
    }
    const colors = { 600: brandPrimary, 700: brandSecondary };
    const cssRoot = document.documentElement;
    const hexToRgb = hex => {
      const clean = String(hex || '').replace('#', '');
      if (!/^[0-9a-f]{6}$/i.test(clean)) return '79, 70, 229';
      return `${parseInt(clean.slice(0, 2), 16)}, ${parseInt(clean.slice(2, 4), 16)}, ${parseInt(clean.slice(4, 6), 16)}`;
    };
    const primaryRgb = hexToRgb(colors[600]);
    const secondaryRgb = hexToRgb(colors[700]);
    const washAlpha = { calm: .08, balanced: .16, vivid: .25 }[backdropStrength] || .16;
    const brandGradient = `linear-gradient(145deg, rgba(${primaryRgb}, ${washAlpha}) 0%, rgba(${secondaryRgb}, ${washAlpha * .72}) 48%, rgba(255, 255, 255, .98) 100%)`;
    cssRoot.style.setProperty('--brand-color-600', colors[600]);
    cssRoot.style.setProperty('--brand-color-700', colors[700]);
    cssRoot.style.setProperty('--brand-color-rgb', primaryRgb);
    cssRoot.style.setProperty('--site-brand', colors[600]);
    cssRoot.style.setProperty('--site-brand-dark', colors[700]);
    cssRoot.style.setProperty('--site-brand-secondary', colors[700]);
    cssRoot.style.setProperty('--page-title-color', colors[700]);
    cssRoot.style.setProperty('--brand-gradient', brandGradient);
    hoverStyle.textContent = `
      a { transition: color 0.2s ease; }
      a:hover { color: ${colors[600]}; }
      button { transition: color 0.2s ease, background-color 0.2s ease, border-color 0.2s ease; }
      .site-brand-icon,.site-brand-breadcrumb { color:${colors[600]}!important; }
      .site-brand-active { border-color:${colors[600]}!important; background-color:rgba(${primaryRgb},.09)!important; }
      .text-indigo-600,.text-indigo-700,.text-indigo-900 { color:${colors[600]}!important; }
      .border-indigo-600 { border-color:${colors[600]}!important; }
      .bg-indigo-50 { background-color:rgba(${primaryRgb},.09)!important; }
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
        icon.classList.add('site-brand-icon');
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
    s.src = resolveFrontendAsset('js/fonts.js?v=20260829-expanded-fonts');
    s.onload = cb;
    document.head.appendChild(s);
  }

  fetchNoCache(`${apiBase}/brand_settings.php`)
    .then(r => r.json())
    .then(f => {
      siteName = f.site_name || siteName;
      colorScheme = f.color_scheme || colorScheme;
      brandPrimary = f.brand_color || brandPrimary;
      brandSecondary = f.brand_color_dark || brandSecondary;
      applyAppearancePreferences(f);
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
      applyAppearancePreferences();
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
      'to-indigo-100/30'
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
            button.addEventListener('click', () => {
              const shouldExpand = button.getAttribute('aria-expanded') !== 'true';
              if (shouldExpand) {
                menu.querySelectorAll('.site-menu-group').forEach(otherSection => {
                  if (otherSection !== section) setSectionExpanded(otherSection, false);
                });
              }
              setSectionExpanded(section, shouldExpand);
            });
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
            'font-medium',
            'text-gray-900',
            'site-brand-active'
          );
          const activeIcon = link.querySelector('i');
          if (activeIcon) {
            activeIcon.classList.remove('text-slate-400');
            activeIcon.classList.add('site-brand-icon');
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
              crumb.className = 'page-breadcrumb site-brand-breadcrumb';
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

  const main = document.querySelector('body > div > main');
  const content = main?.parentElement;
  if (content && content.parentElement === document.body) {
    // Prevent the document itself from scrolling so only the main content
    // panel owns vertical scrolling. This avoids double right-edge scrollbars.
    document.documentElement.classList.add('app-shell-root');
    document.body.classList.add('m-0', 'h-screen', 'overflow-hidden', 'app-shell-body');

    // Ensure wrapper always uses column layout on small screens with a
    // sidebar on larger displays so the menu and utility bar position
    // consistently across pages.
    content.classList.add('flex', 'flex-col', 'md:flex-row', 'min-h-screen', 'h-screen', 'overflow-hidden', 'app-shell');
    main.classList.add('flex-1', 'min-w-0', 'h-full', 'overflow-y-auto', 'md:ml-64', 'pt-16', 'md:pt-0', 'app-shell-main');
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
