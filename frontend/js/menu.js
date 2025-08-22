// Dynamically loads the shared navigation menu into pages and ensures icon support.
// Override fetch globally to bypass browser caching.
const originalFetch = window.fetch;
window.fetch = (input, init = {}) => {
  init = init || {};
  if (!init.cache) {
    init.cache = 'no-store';
  }
  return originalFetch(input, init);
};

document.addEventListener('DOMContentLoaded', () => {
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
        icon.classList.add('text-indigo-600');
      }
    });
  };

  // Apply 20% opacity to all page elements
  document.documentElement.style.opacity = '0.9';
  applyIconColor();
  // Apply consistent hover styling across the site
  const hoverStyle = document.createElement('style');
  hoverStyle.textContent = `
    a { transition: color 0.2s ease; }
    a:hover { color: #4f46e5; }
    button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
    button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
  `;
  document.head.appendChild(hoverStyle);

  // Ensure every page uses the shared SVG favicon
  if (!document.querySelector('link[rel="icon"]')) {
    const iconSvg = document.createElement('link');
    iconSvg.rel = 'icon';
    iconSvg.type = 'image/svg+xml';
    iconSvg.href = '/favicon.svg';
    iconSvg.sizes = 'any';
    document.head.appendChild(iconSvg);
  }

  // Load Font Awesome so pages can display contextual icons
  if (!document.getElementById('fa-css')) {
    const fa = document.createElement('link');
    fa.id = 'fa-css';
    fa.rel = 'stylesheet';
    fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css';
    document.head.appendChild(fa);
  }

  const menu = document.getElementById('menu');
  if (menu) {
    // Add responsive classes so the navigation can toggle on small screens
    menu.classList.add(
      'hidden',
      'md:block',
      'fixed',
      'top-16',
      'bottom-0',
      'left-0',
      'overflow-y-auto',
      'z-40'
    );

    // Load site fonts and apply them to headings, body text and accents
    if (!document.getElementById('app-fonts')) {
      const fontLink = document.createElement('link');
      fontLink.id = 'app-fonts';
      fontLink.rel = 'stylesheet';
      fontLink.href = 'https://fonts.googleapis.com/css2?family=Roboto:wght@700&family=Inter:wght@400&family=Source+Sans+Pro:wght@300&display=swap';
      document.head.appendChild(fontLink);
    }
    const fontStyle = document.createElement('style');
    fontStyle.textContent = `
      body { font-family: 'Inter', sans-serif; font-weight: 400; }
      h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; font-weight: 700; }
      button, .accent { font-family: 'Source Sans Pro', sans-serif; font-weight: 300; }
    `;
    document.head.appendChild(fontStyle);

    fetch('menu.html')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
        applyIconColor(menu);
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
            crumb.className = 'uppercase text-indigo-900 text-[0.6rem] mb-1';
            heading.before(crumb);
          }
        }
        // Display counter for untagged transactions in menu
        fetch('../php_backend/public/untagged_transactions.php')
          .then(r => r.json())
          .then(rows => {
            const total = rows.reduce((sum, row) => sum + Number(row.count || 0), 0);
            if (total > 10) {
              const counter = menu.querySelector('#missing-tags-count');
              if (counter) {
                counter.textContent = total;
                counter.classList.remove('hidden');
              }
            }
          })
          .catch(err => console.error('Untagged count load failed', err));
      })
      .catch(err => console.error('Menu load failed', err));
  }

  // Load the top bar with site name, search and latest statement link
  fetch('topbar.html')
    .then(resp => resp.text())
    .then(html => {
      document.body.insertAdjacentHTML('afterbegin', html);
      applyIconColor();

      const content = document.querySelector('body > div.flex');
      if (content) {

        content.classList.add('pt-16', 'h-screen', 'overflow-hidden');
        const main = content.querySelector('main');
        if (main) {
          main.classList.add('h-full', 'overflow-y-auto', 'md:ml-64');
        }

      }

      const toggle = document.getElementById('menu-toggle');
      if (toggle) {
        toggle.addEventListener('click', () => {
          if (menu) menu.classList.toggle('hidden');
        });
      }

      const latestLink = document.getElementById('latest-statement-link');
      const latestText = document.getElementById('latest-statement-text');
      if (latestLink && latestText) {
        fetch('../php_backend/public/transaction_months.php')
          .then(r => r.json())
          .then(months => {
            if (months.length > 0) {
              const { year, month } = months[0];
              const names = [
                'January','February','March','April','May','June',
                'July','August','September','October','November','December'
              ];
              latestLink.href = `monthly_statement.html?year=${year}&month=${month}`;
              latestText.textContent = `Latest Statement: ${names[month - 1]} ${year}`;
            }
          })
          .catch(err => console.error('Latest statement load failed', err));
      }

      const releaseEl = document.getElementById('release-number');
      if (releaseEl) {
        fetch('../php_backend/public/version.php')
          .then(r => r.json())
          .then(v => {
            const version = v.version || 'unknown';
            releaseEl.textContent = `v${version}`;
          })
          .catch(() => {
            releaseEl.textContent = 'v?';
          });
      }

      const userEl = document.getElementById('current-user');
      const iconEl = document.getElementById('user-icon');
      if (userEl) {
        fetch('../php_backend/public/current_user.php')
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
    })
    .catch(err => console.error('Top bar load failed', err));

  // Apply Tailwind card styling to all sections or wrap main content in a card
  document.querySelectorAll('main').forEach(main => {
    const sections = main.querySelectorAll('section');
    if (sections.length > 0) {
      sections.forEach(section => {
        section.classList.add('bg-white', 'p-6', 'rounded', 'shadow');
      });
    } else {
      const wrapper = document.createElement('section');
      wrapper.className = 'bg-white p-6 rounded shadow';
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

  const hintScript = document.createElement('script');
  hintScript.src = 'js/keyboard_hints.js';
  document.body.appendChild(hintScript);

  const logoutScript = document.createElement('script');
  logoutScript.src = 'js/auto_logout.js';
  document.body.appendChild(logoutScript);
});
