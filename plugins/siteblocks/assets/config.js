(function () {
    const original = determiner.shortcode;
    determiner.shortcode = function (block, ...args) {
        if (block.startsWith('[:siteblock-ref ')) return 'siteblock-reference';
        return block.startsWith('[:siteblock ') ? 'siteblock-component' : original(block, ...args);
    };
    activeFormats.siteblock = { label: '▦', title: translatefilter.translate('Site layout'), component: 'siteblock-component' };
    activeFormats.siteblockref = { label: '↗', title: translatefilter.translate('Block from library'), component: 'siteblock-reference' };
})();
