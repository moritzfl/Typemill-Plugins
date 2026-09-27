(function () {
    let dialog;
    const de = document.documentElement.lang.startsWith('de');
    document.querySelectorAll('.sb--slideshow').forEach(block => {
        const track = block.querySelector('.sb__items');
        const slides = [...track.children];
        if (slides.length < 2) return;
        let index = 0;
        const controls = document.createElement('div'); controls.className = 'sb__controls';
        const previous = document.createElement('button'); previous.type = 'button'; previous.textContent = de ? 'Zurück' : 'Previous';
        const next = document.createElement('button'); next.type = 'button'; next.textContent = de ? 'Weiter' : 'Next';
        const status = document.createElement('span'); status.setAttribute('aria-live', 'polite');
        const update = () => {
            const left = track.getBoundingClientRect().left;
            index = slides.reduce((best, slide, i) => Math.abs(slide.getBoundingClientRect().left - left) < Math.abs(slides[best].getBoundingClientRect().left - left) ? i : best, 0);
            previous.disabled = index === 0; next.disabled = index === slides.length - 1;
            status.textContent = (index + 1) + ' / ' + slides.length;
        };
        const move = delta => slides[Math.max(0, Math.min(slides.length - 1, index + delta))].scrollIntoView({ block: 'nearest', inline: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        previous.onclick = () => move(-1); next.onclick = () => move(1);
        track.addEventListener('scroll', update, { passive: true });
        controls.append(previous, status, next); block.append(controls); update();
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-sb-image]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const links = [...link.closest('.sb').querySelectorAll('[data-sb-image]')];
        let index = links.indexOf(link);
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'sb-lightbox';
            dialog.setAttribute('aria-label', document.documentElement.lang.startsWith('de') ? 'Bildergalerie' : 'Image gallery');
            document.body.append(dialog);
        }
        dialog.replaceChildren();
        const controls = document.createElement('div'); controls.className = 'sb-lightbox__controls';
        const image = document.createElement('img');
        const caption = document.createElement('p'); caption.setAttribute('aria-live', 'polite');
        const show = delta => {
            index = (index + delta + links.length) % links.length;
            image.src = links[index].href;
            image.alt = links[index].querySelector('img').alt;
            caption.textContent = (index + 1) + ' / ' + links.length + ' — ' + (links[index].closest('figure').querySelector('figcaption')?.textContent || image.alt);
        };
        for (const [label, action] of [[de ? 'Zurück' : 'Previous', () => show(-1)], [de ? 'Schließen' : 'Close', () => dialog.close()], [de ? 'Weiter' : 'Next', () => show(1)]]) {
            const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.onclick = action; controls.append(button);
        }
        dialog.append(controls, image, caption);
        dialog.onkeydown = event => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); show(event.key === 'ArrowLeft' ? -1 : 1); }
        };
        dialog.onclose = () => link.focus();
        show(0); dialog.showModal(); controls.children[1].focus();
    });
})();
