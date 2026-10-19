// Shared notification system for updates, completed actions, warnings and errors.
(function (root) {
    'use strict';

    const toneDetails = {
        success: { title: 'Done', icon: 'fa-check', duration: 4200 },
        error: { title: 'Couldn\'t complete', icon: 'fa-triangle-exclamation', duration: 7000 },
        warning: { title: 'Please check', icon: 'fa-exclamation', duration: 6000 },
        info: { title: 'Update', icon: 'fa-info', duration: 5000 },
        loading: { title: 'Working', icon: 'fa-arrows-rotate', duration: 0 }
    };

    function normaliseTone(type) {
        const value = String(type || 'success').toLowerCase();
        if (value === 'danger' || value === 'failure') return 'error';
        if (value === 'warn') return 'warning';
        if (value === 'progress' || value === 'working') return 'loading';
        return Object.prototype.hasOwnProperty.call(toneDetails, value) ? value : 'success';
    }

    function inferTone(message, requestedType, typeWasProvided) {
        if (typeWasProvided) return normaliseTone(requestedType);
        const text = String(message || '').toLowerCase();
        if (/\b(started|starting|running|processing|preparing|generating)\b/.test(text)) return 'info';
        return 'success';
    }

    function durationForTone(type) {
        return toneDetails[normaliseTone(type)].duration;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { normaliseTone, inferTone, durationForTone };
    }

    if (!root || !root.document) return;

    const document = root.document;
    let notificationSequence = 0;

    function loadStyles() {
        if (document.getElementById('site-notification-css')) return;
        const link = document.createElement('link');
        link.id = 'site-notification-css';
        link.rel = 'stylesheet';
        link.href = 'notification.css?v=20261019-inline-notices';
        document.head.appendChild(link);
    }

    function notificationStack() {
        let stack = document.getElementById('notification-stack');
        if (stack) return stack;

        stack = document.createElement('div');
        stack.id = 'notification-stack';
        stack.className = 'notification-stack';
        stack.setAttribute('aria-label', 'Notifications');
        // A sibling of main reserves real space, even when the workspace is scrolled.
        // Pages without the application shell use the same notices in document flow.
        const main = document.querySelector('main');
        if (main) main.before(stack);
        else document.body.prepend(stack);
        return stack;
    }

    function removeToast(toast) {
        if (!toast || toast.dataset.closing === 'true') return;
        toast.dataset.closing = 'true';
        toast.classList.add('is-leaving');

        const slot = toast.parentElement?.parentElement;
        if (!slot?.classList.contains('notification-slot')) {
            toast.remove();
            return;
        }
        slot.classList.remove('is-open');
        const finish = () => slot.remove();
        slot.addEventListener('transitionend', event => {
            if (event.target === slot && event.propertyName === 'grid-template-rows') finish();
        });
        root.setTimeout(finish, 350);
    }

    function iconNode(iconClass) {
        const iconWrap = document.createElement('span');
        const icon = document.createElement('i');
        iconWrap.className = 'site-notification__icon';
        iconWrap.setAttribute('aria-hidden', 'true');
        icon.className = `fas ${iconClass}`;
        iconWrap.appendChild(icon);
        return iconWrap;
    }

    function showMessage(message, type) {
        loadStyles();
        const typeWasProvided = arguments.length > 1;
        const tone = inferTone(message, type, typeWasProvided);
        const details = toneDetails[tone];
        const stack = notificationStack();
        const slot = document.createElement('div');
        const inner = document.createElement('div');
        slot.className = 'notification-slot';
        inner.className = 'notification-slot__inner';
        const toast = document.createElement('section');
        const copy = document.createElement('div');
        const title = document.createElement('strong');
        const body = document.createElement('p');
        const close = document.createElement('button');
        const closeIcon = document.createElement('i');
        const duration = durationForTone(tone);

        notificationSequence += 1;
        toast.id = `site-notification-${notificationSequence}`;
        toast.className = `site-notification site-notification--${tone}`;
        toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');

        copy.className = 'site-notification__copy';
        title.className = 'site-notification__title';
        title.textContent = details.title;
        body.className = 'site-notification__message';
        body.textContent = String(message || 'Update complete.');
        copy.append(title, body);

        close.type = 'button';
        close.className = 'site-notification__close';
        close.setAttribute('aria-label', 'Dismiss notification');
        closeIcon.className = 'fas fa-xmark';
        closeIcon.setAttribute('aria-hidden', 'true');
        close.appendChild(closeIcon);
        close.addEventListener('click', () => removeToast(toast));

        toast.append(iconNode(details.icon), copy, close);
        inner.appendChild(toast);
        slot.appendChild(inner);
        stack.appendChild(slot);
        // Establish the collapsed size before opening so following content moves smoothly.
        void slot.offsetHeight;
        slot.classList.add('is-open');

        while (stack.children.length > 3) stack.firstElementChild.remove();
        if (duration > 0) root.setTimeout(() => removeToast(toast), duration);

        return toast;
    }

    loadStyles();
    root.showMessage = showMessage;
    root.dismissNotification = removeToast;
})(typeof window !== 'undefined' ? window : null);
