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
  // Apply consistent hover styling across the site
  const hoverStyle = document.createElement('style');
  hoverStyle.textContent = `
    a { transition: color 0.2s ease; }
    a:hover { color: #4f46e5; }
    button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
    button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
  `;
  document.head.appendChild(hoverStyle);

  // Ensure every page uses the shared favicon
  if (!document.querySelector('link[rel="icon"]')) {
    const icon = document.createElement('link');
    icon.rel = 'icon';
    icon.type = 'image/svg+xml';
    icon.href = '../favicon.svg';
    document.head.appendChild(icon);
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

    // Load Font Awesome for menu icons if not already loaded
    if (!document.getElementById('fa-icons')) {
      const link = document.createElement('link');
      link.id = 'fa-icons';
      link.rel = 'stylesheet';
      link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
      document.head.appendChild(link);
    }

    // Load Roboto font for a lighter appearance
    if (!document.getElementById('roboto-font')) {
      const fontLink = document.createElement('link');
      fontLink.id = 'roboto-font';
      fontLink.rel = 'stylesheet';
      fontLink.href = 'https://fonts.googleapis.com/css2?family=Roboto:wght@300;500&display=swap';
      document.head.appendChild(fontLink);
    }
    document.body.style.fontFamily = 'Roboto, sans-serif';
    document.body.style.fontWeight = '300';

    fetch('menu.html')
      .then(resp => resp.text())
      .then(html => {
        menu.innerHTML = html;
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
      })
      .catch(err => console.error('Menu load failed', err));
  }

  // Load the top bar with site name, search and latest statement link
  fetch('topbar.html')
    .then(resp => resp.text())
    .then(html => {
      document.body.insertAdjacentHTML('afterbegin', html);

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
});
