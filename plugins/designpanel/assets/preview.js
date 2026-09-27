document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('data-sb-image') || link.getAttribute('href').startsWith('#')) return;
    event.preventDefault();
    if (link.origin === location.origin) window.parent.postMessage({ type: 'designpanel:navigate', url: link.href }, location.origin);
});
