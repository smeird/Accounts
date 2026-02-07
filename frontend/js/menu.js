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


  if (!document.getElementById('theme-professional-css')) {
    const themeLink = document.createElement('link');
    themeLink.id = 'theme-professional-css';
    themeLink.rel = 'stylesheet';
    themeLink.href = resolveFrontendAsset('css/theme-professional.css');
    document.head.appendChild(themeLink);
  }

  const PROFESSIONAL_THEME_KEY = 'professionalThemeEnabled';
  const professionalThemeEnabled = localStorage.getItem(PROFESSIONAL_THEME_KEY) === 'true';
  document.body.classList.toggle('theme-professional', professionalThemeEnabled);

  document.body.classList.add('pt-4');
  let colorScheme = 'indigo';
  let siteName = 'Finance Manager';
  const colorMap = {
    indigo: {600: '#4f46e5', 700: '#4338ca'},
    blue:   {600: '#2563eb', 700: '#1d4ed8'},
    green:  {600: '#059669', 700: '#047857'},
    red:    {600: '#dc2626', 700: '#b91c1c'},
    purple: {600: '#9333ea', 700: '#7e22ce'},
    teal:   {600: '#0d9488', 700: '#0f766e'},
    orange: {600: '#ea580c', 700: '#c2410c'}
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
      const coloredBg = parentClasses.some(c => c.startsWith('bg-') && c !== 'bg-white');
      const hasColor = Array.from(icon.classList).some(c => c.startsWith('text-'));
      if (coloredBg) {
        icon.classList.forEach(c => { if (c.startsWith('text-')) icon.classList.remove(c); });
        icon.classList.add('text-white');
      } else if (!hasColor) {
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
    s.src = resolveFrontendAsset('js/fonts.js');
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
      const landing = document.getElementById('landing-site-name');
      if (landing) landing.textContent = siteName;
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
      'bg-gradient-to-b',
      'from-white/80',
      'backdrop-blur-xl',
      'border',
      'border-white/40',
      'shadow-2xl',
      `to-${colorScheme}-100/30`
    );
    menu.classList.add(
      'bg-white',
      'border-r',
      'border-slate-200',
      'shadow-sm'
    );

    fetchNoCache(resolveFrontendAsset('menu.php'))
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
        attachSidebarSearchHandler(document);
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
        // Enable collapsible sections with animated height transition
        menu.querySelectorAll('.group').forEach(section => {
          const header = section.querySelector('h3');
          const list = section.querySelector('ul');
          if (header && list) {
            header.addEventListener('click', () => {
              const expanded = list.style.maxHeight && list.style.maxHeight !== '0px';
              list.style.maxHeight = expanded ? '0px' : `${list.scrollHeight}px`;
            });
          }
        });

        const themeWrap = document.createElement('div');
        themeWrap.className = 'mt-4 p-3 rounded-lg border border-slate-200 bg-slate-50';
        themeWrap.innerHTML = `
          <label class="flex items-center justify-between gap-3 text-sm text-slate-700" for="professional-theme-toggle">
            <span class="font-medium">Professional theme</span>
            <input id="professional-theme-toggle" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600" aria-label="Toggle professional theme">
          </label>
        `;
        menu.appendChild(themeWrap);
        const themeToggle = document.getElementById('professional-theme-toggle');
        if (themeToggle) {
          themeToggle.checked = document.body.classList.contains('theme-professional');
          themeToggle.addEventListener('change', () => {
            const enabled = themeToggle.checked;
            document.body.classList.toggle('theme-professional', enabled);
            localStorage.setItem(PROFESSIONAL_THEME_KEY, String(enabled));
          });
        }

        // Hide menu after clicking a link on mobile
        menu.querySelectorAll('a').forEach(a =>
          a.addEventListener('click', () => menu.classList.add('hidden'))
        );

        // Build breadcrumb text underneath the page title
        const current = location.pathname.split('/').pop();
        const link = menu.querySelector(`a[href="${current}"]`);
        if (link) {
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
          const section = link.closest('div')?.querySelector('h3')?.textContent?.trim();
          const page = link.textContent.trim();
          const heading = document.querySelector('main h1');
          if (section && page && heading) {
            const crumb = document.createElement('div');
            crumb.textContent = `${section} / ${page}`;
            crumb.className = `page-breadcrumb text-${colorScheme}-900`;
            heading.insertAdjacentElement('afterend', crumb);
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
    // Ensure wrapper always uses column layout on small screens with a
    // sidebar on larger displays so the menu and utility bar position
    // consistently across pages.
    content.classList.add('flex', 'flex-col', 'md:flex-row', 'min-h-screen', 'h-screen', 'overflow-hidden');
    const main = content.querySelector('main');
    if (main) {
      main.classList.add('flex-1', 'min-w-0', 'h-full', 'overflow-y-auto', 'md:ml-64', 'pt-16', 'md:pt-0');
    }
  }

  const toggle = document.createElement('button');
  toggle.id = 'menu-toggle';
  toggle.className = `fixed top-2 left-2 md:top-8 md:left-12 z-50 md:hidden bg-gradient-to-r from-white/80 to-${colorScheme}-100/40 backdrop-blur border border-white/40 text-${colorScheme}-700 p-2 rounded-xl shadow-lg transition-all hover:from-white/90 hover:to-${colorScheme}-100/60 hover:shadow-2xl focus:outline-none focus:ring-2 focus:ring-${colorScheme}-200`;
  toggle.innerHTML = '<i class="fas fa-bars"></i>';
  toggle.addEventListener('click', () => {
    if (menu) menu.classList.toggle('hidden');
  });
  document.body.appendChild(toggle);

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
      const wrapper = document.createElement('section');
      wrapper.className = 'cards';
      while (main.firstChild) {
        wrapper.appendChild(main.firstChild);
      }
      main.appendChild(wrapper);
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
