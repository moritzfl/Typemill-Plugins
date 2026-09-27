(function () {
    const original = determiner.shortcode;
    determiner.shortcode = function (block, ...args) {
        return block.startsWith('[:siteblock ') ? 'siteblock-component' : original(block, ...args);
    };
    activeFormats.siteblock = { label: '▦', title: translatefilter.translate('Site layout'), component: 'siteblock-component' };
})();
