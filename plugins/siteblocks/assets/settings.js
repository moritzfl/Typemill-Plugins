/* Deep-link into the native plugin form through its normal Configure control. */
(function () {
    const open = async () => {
        if (location.hash !== '#siteblocks-footer') return;
        const card = document.querySelector('#plugins input[name="siteblocks"]')?.closest('li');
        if (!card) return;
        if (!card.querySelector('form')) card.querySelector('button')?.click();
        await Vue.nextTick();
        card.querySelector('.sb-shared-help')?.scrollIntoView({ block: 'start' });
        card.querySelector('[name="shared_title1"]')?.focus({ preventScroll: true });
    };
    // Paragraph fields render through Typemill's translation-aware form component.
    // Add the optional Designer link only when that plugin is active.
    const help = data.definitions?.siteblocks?.forms?.fields?.shared_footer_help;
    // Core field labels use the translation filter, but fieldset legends do not.
    for (const field of Object.values(data.definitions?.siteblocks?.forms?.fields || {})) {
        if (field.type === 'fieldset') field.legend = translatefilter.translate(field.legend);
    }
    if (help && data.settings?.designpanel?.active) {
        const translated = translatefilter.translate(help.description);
        help.description = translated + ' <a class="underline" href="designpanel?section=footer">'
            + translatefilter.translate('Open theme footer in Design panel') + '</a>';
    }
    window.addEventListener('hashchange', open);
    open();
})();
