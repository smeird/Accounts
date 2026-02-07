(function () {
  const HEADER_SELECTOR = 'header.page-header';

  function createTextElement(tagName, className, text) {
    if (!text) {
      return null;
    }
    const element = document.createElement(tagName);
    element.className = className;
    element.textContent = text;
    return element;
  }

  function toActionsNode(actions) {
    if (!actions) {
      return null;
    }

    const actionsWrap = document.createElement('div');
    actionsWrap.className = 'page-header-actions';

    if (typeof actions === 'string') {
      actionsWrap.innerHTML = actions;
      return actionsWrap;
    }

    if (actions instanceof HTMLElement) {
      actionsWrap.appendChild(actions);
      return actionsWrap;
    }

    return null;
  }

  function getExistingOptions(main) {
    const existingHeader = main.querySelector(':scope > ' + HEADER_SELECTOR);
    const existingTitle = existingHeader ? existingHeader.querySelector('.page-title') : null;
    const existingBreadcrumb = existingHeader ? existingHeader.querySelector('.page-breadcrumb') : null;
    const existingSubtitle = existingHeader ? existingHeader.querySelector('.page-subtitle') : null;

    return {
      title: main.dataset.pageHeaderTitle || (existingTitle ? existingTitle.textContent.trim() : ''),
      breadcrumb: main.dataset.pageHeaderBreadcrumb || (existingBreadcrumb ? existingBreadcrumb.textContent.trim() : ''),
      subtitle: main.dataset.pageHeaderSubtitle || (existingSubtitle ? existingSubtitle.textContent.trim() : ''),
      actions: null
    };
  }

  function renderPageHeader(main, options) {
    if (!main) {
      return null;
    }

    const merged = Object.assign({}, getExistingOptions(main), options || {});
    if (!merged.title) {
      throw new Error('page_header: "title" is required');
    }

    const header = document.createElement('header');
    header.className = 'page-header';

    const content = document.createElement('div');
    content.className = 'w-full text-left';

    const titleEl = createTextElement('h1', 'page-title', merged.title);
    const breadcrumbEl = createTextElement('div', 'page-breadcrumb', merged.breadcrumb);
    const subtitleEl = createTextElement('p', 'page-subtitle', merged.subtitle);

    if (titleEl) {
      content.appendChild(titleEl);
    }
    if (breadcrumbEl) {
      content.appendChild(breadcrumbEl);
    }
    if (subtitleEl) {
      content.appendChild(subtitleEl);
    }

    header.appendChild(content);

    const actionsNode = toActionsNode(merged.actions);
    if (actionsNode) {
      header.appendChild(actionsNode);
    }

    const existingHeader = main.querySelector(':scope > ' + HEADER_SELECTOR);
    if (existingHeader) {
      existingHeader.replaceWith(header);
    } else {
      main.insertBefore(header, main.firstChild);
    }

    main.__pageHeaderState = merged;
    return header;
  }

  function updatePageHeader(main, partialOptions) {
    const previous = main && main.__pageHeaderState ? main.__pageHeaderState : {};
    return renderPageHeader(main, Object.assign({}, previous, partialOptions || {}));
  }

  window.renderPageHeader = renderPageHeader;
  window.updatePageHeader = updatePageHeader;
})();
