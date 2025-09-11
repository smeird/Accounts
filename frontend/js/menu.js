// Dynamically loads the shared navigation menu into pages and ensures icon support.
// Helper to bypass browser caching when needed.
function fetchNoCache(input, init = {}) {
  init = init || {};
  init.cache = 'no-store';
  return window.fetch(input, init);
}
window.fetchNoCache = fetchNoCache;

  if (!document.getElementById('cards-css')) {
    const cardLink = document.createElement('link');
    cardLink.id = 'cards-css';
    cardLink.rel = 'stylesheet';
    cardLink.href = 'cards.css';
    document.head.appendChild(cardLink);
  }

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
      button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
      button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
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
    root.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]), select, textarea').forEach(el => {
      if (!el.classList.contains('styled-input')) {
        el.classList.add('styled-input', 'p-2', 'border', 'rounded', 'bg-white', 'border-gray-400');
      }
    });
  };

  // Apply 20% opacity to all page elements
  document.documentElement.style.opacity = '0.9';

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
    s.src = 'js/fonts.js';
    s.onload = cb;
    document.head.appendChild(s);
  }

  fetchNoCache('../php_backend/public/brand_settings.php')
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

    fetchNoCache('menu.php')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
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
          fetchNoCache('../php_backend/public/current_user.php')
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
        // Hide menu after clicking a link on mobile
        menu.querySelectorAll('a').forEach(a =>
          a.addEventListener('click', () => menu.classList.add('hidden'))
        );

        // Build breadcrumb text above the page title
        const current = location.pathname.split('/').pop();
        const link = menu.querySelector(`a[href="${current}"]`);
        if (link) {
          const section = link.closest('div')?.querySelector('h3')?.textContent?.trim();
          const page = link.textContent.trim();
          const heading = document.querySelector('main h1');
          if (section && page && heading) {
            const crumb = document.createElement('div');
            crumb.textContent = `${section} / ${page}`.toUpperCase();
            crumb.className = `uppercase text-${colorScheme}-900 text-[0.6rem] mb-1`;
            heading.before(crumb);
          }
        }
        // Display counter for untagged transactions in menu
        fetchNoCache('../php_backend/public/untagged_count.php')
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
          fetchNoCache('../php_backend/public/version.php')
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
  toggle.className = 'fixed top-4 left-4 z-50 md:hidden bg-indigo-600 hover:bg-indigo-700 text-white p-3 rounded-full shadow';
  toggle.innerHTML = '<i class="fas fa-bars"></i>';
  toggle.addEventListener('click', () => {
    if (menu) menu.classList.toggle('hidden');
  });
  document.body.appendChild(toggle);

  const utility = document.createElement('div');
  utility.id = 'utility-bar';

  utility.className = 'fixed top-4 right-8 md:top-8 md:right-12 bg-white rounded-full border border-indigo-600 p-2 flex items-center space-x-4 z-50 transition-shadow hover:shadow-lg';

  utility.innerHTML = `
    <a id="latest-statement-link" href="monthly_statement.html" class="hidden md:flex items-center">
      <i class="fas fa-file-invoice h-4 w-4"></i>
    </a>
  `;
  document.body.appendChild(utility);
  const latestLink = document.getElementById('latest-statement-link');
  if (latestLink) {
    fetchNoCache('../php_backend/public/transaction_months.php')
      .then(r => r.json())
      .then(months => {
        if (months.length > 0) {
          const { year, month } = months[0];
          const names = [
            'January','February','March','April','May','June',
            'July','August','September','October','November','December'
          ];
          latestLink.href = `monthly_statement.html?year=${year}&month=${month}`;
        }
      })
      .catch(err => console.error('Latest statement load failed', err));
  }

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
  helpScript.src = 'js/page_help.js';
  document.body.appendChild(helpScript);

  const logoutScript = document.createElement('script');
  logoutScript.src = 'js/auto_logout.js';
  document.body.appendChild(logoutScript);

  const tooltipScript = document.createElement('script');
  tooltipScript.src = 'js/tooltips.js';
  document.body.appendChild(tooltipScript);

  const fullscreenScript = document.createElement('script');
  fullscreenScript.src = 'js/chart_fullscreen.js';
  document.body.appendChild(fullscreenScript);
