const designPanelText = {
    DP_SCOPE_HELP: 'You are editing the active theme, not an individual page. Settings may affect the whole site, only the homepage or particular page types.',
    DP_SAVE_SCOPE: 'Saving updates the active theme, not the selected page.',
    DP_PREVIEW_SCOPE: 'Page selection changes only the preview, not which settings you edit.',
};

const app = Vue.createApp({
    template: designPanelTemplate,
    data: () => ({ state: {}, values: {}, original: {}, loading: true, busy: false, message: '', error: '',
        path: '/', pathInput: '/', pathError: '', width: '100%', search: '', presetKey: '', pane: 'settings',
        pages: [], pageSearch: '', pagesLoading: false, pagesError: '',
        previewUrl: '', previewBusy: false, previewError: '', previewScroll: null, timer: null, frameTimer: null, sequence: 0, frameSequence: 0,
        base: tmaxios.defaults.baseURL.replace(/\/$/, '') }),
    computed: {
        dirty() { return JSON.stringify(this.values) !== JSON.stringify(this.original); },
        selectedPreset() { return this.state.presets?.[this.presetKey]; },
        matchingPages() {
            const query = this.pageSearch.trim().toLocaleLowerCase();
            return this.pages.filter(page => (page.title + ' ' + page.path).toLocaleLowerCase().includes(query));
        },
        groups() {
            const query = this.search.trim().toLocaleLowerCase();
            const fields = (this.state.fields || []).filter(field =>
                [this.label(field), this.t(field.group || 'General'), this.t(field.description), this.t(field.checkboxlabel)]
                    .some(text => text.toLocaleLowerCase().includes(query)));
            return [...new Set(fields.map(field => field.group))].map(name => ({ name, fields: fields.filter(field => field.group === name) }));
        },
    },
    watch: {
        values: { deep: true, handler() { if (!this.loading) this.changed(); } },
    },
    mounted() {
        this.load();
        window.addEventListener('message', this.navigate);
        window.addEventListener('beforeunload', this.unload);
    },
    beforeUnmount() {
        clearTimeout(this.timer); clearTimeout(this.frameTimer);
        window.removeEventListener('message', this.navigate); window.removeEventListener('beforeunload', this.unload);
    },
    methods: {
        t(value) {
            const translated = this.$filters.translate(value || '');
            return translated === value && Object.hasOwn(designPanelText, value) ? designPanelText[value] : translated;
        },
        label(field) { return this.t(field.key === 'typeScale' ? 'Text size (%)' : field.label || field.key); },
        colorValue(value) {
            if (/^#[a-f0-9]{6}$/i.test(value || '')) return value;
            if (/^#[a-f0-9]{3}$/i.test(value || '')) return '#' + [...value.slice(1)].map(char => char + char).join('');
            return '#000000';
        },
        async load() {
            this.loading = true; this.error = '';
            try {
                this.state = (await tmaxios.get('/api/v1/designpanel/state', { timeout: 15000 })).data;
                this.values = structuredClone(Vue.toRaw(this.state.values));
                for (const field of this.state.fields) {
                    if (this.values[field.key] == null || this.values[field.key] === '') {
                        if (field.type === 'checkboxlist') this.values[field.key] = [];
                        else if (field.type === 'checkbox') this.values[field.key] = false;
                    }
                }
                this.original = structuredClone(Vue.toRaw(this.values));
                await this.$nextTick();
                this.preview();
                if (new URLSearchParams(location.search).get('section') === 'footer') this.showFooter();
            } catch (error) { this.error = this.failure(error); }
            finally { this.loading = false; }
        },
        changed() {
            // Invalidate in-flight responses immediately, not after the debounce.
            this.sequence++; this.message = '';
            clearTimeout(this.timer); clearTimeout(this.frameTimer);
            this.previewBusy = true;
            this.timer = setTimeout(() => this.preview(), 450);
        },
        async reset() {
            this.values = structuredClone(Vue.toRaw(this.original)); this.error = ''; this.presetKey = '';
            await this.$nextTick();
            this.preview();
        },
        preset() {
            if (!this.selectedPreset || this.busy) return;
            Object.assign(this.values, this.selectedPreset.settings);
        },
        async showFooter() {
            this.search = ''; this.pane = 'settings';
            await this.$nextTick();
            const fields = [...this.$refs.fields.querySelectorAll('[data-field]')].filter(node => /footer|copyright/i.test(node.dataset.field));
            for (const field of fields) field.closest('details').open = true;
            fields[0]?.querySelector('input,textarea,select')?.focus();
        },
        async choosePage() {
            this.pageSearch = '';
            this.$refs.pages.showModal();
            await this.loadPages();
        },
        async loadPages() {
            this.pagesLoading = true; this.pagesError = '';
            try { this.pages = (await tmaxios.get('/api/v1/designpanel/pages', { timeout: 15000 })).data.pages; }
            catch { this.pagesError = this.t('Could not load pages.'); }
            finally { this.pagesLoading = false; }
        },
        selectPage(page) { this.pathInput = page.path; this.go(); this.$refs.pages.close(); },
        go() {
            const path = this.pathInput.trim();
            if (!/^\/(?!\/|tm(?:\/|$)|api(?:\/|$)|media(?:\/|$))[a-zA-Z0-9/_%-]*$/.test(path) || path.includes('..')) {
                this.pathError = this.t('Use a local page path, for example /about.'); return;
            }
            this.pathError = ''; this.path = path; this.pathInput = path; this.preview();
        },
        async valid(report = false) {
            // Search only hides controls; it must never hide a validation failure.
            const search = this.search;
            if (report && search) { this.search = ''; await this.$nextTick(); }
            const invalid = this.$refs.fields?.querySelector(':invalid:not(fieldset)');
            if (!invalid) { if (report) this.search = search; return true; }
            if (report) {
                this.pane = 'settings'; await this.$nextTick();
                invalid.closest('details').open = true; invalid.reportValidity();
            }
            return false;
        },
        async preview() {
            clearTimeout(this.timer); clearTimeout(this.frameTimer);
            const sequence = ++this.sequence;
            if (!await this.valid()) {
                this.previewBusy = false; this.previewError = this.t('Check the highlighted setting before previewing.'); return;
            }
            this.previewBusy = true; this.previewError = '';
            try {
                const response = await tmaxios.post('/api/v1/designpanel/preview', { theme: this.state.theme, values: this.values, path: this.path }, { timeout: 15000 });
                if (sequence !== this.sequence) return;
                const frame = this.$refs.preview;
                this.previewScroll = null;
                if (frame?.contentDocument && new URL(this.previewUrl).pathname === new URL(response.data.url).pathname) {
                    const scroller = frame.contentDocument.querySelector('.content-scroll') || frame.contentDocument.scrollingElement;
                    if (scroller) this.previewScroll = { top: scroller.scrollTop, left: scroller.scrollLeft };
                }
                this.previewUrl = response.data.url;
                this.frameSequence = sequence;
                this.frameTimer = setTimeout(() => {
                    this.previewBusy = false; this.previewError = this.t('The preview did not load. Try again.');
                }, 15000);
            } catch (error) {
                if (sequence === this.sequence) { this.previewBusy = false; this.previewError = this.failure(error); }
            }
        },
        previewLoaded() {
            const frame = this.$refs.preview;
            try {
                if (this.frameSequence !== this.sequence || frame.contentWindow.location.href !== this.previewUrl) return;
                clearTimeout(this.frameTimer); this.previewBusy = false;
                if (this.previewScroll) {
                    const scroller = frame.contentDocument.querySelector('.content-scroll') || frame.contentDocument.scrollingElement;
                    scroller.scrollTo({ ...this.previewScroll, behavior: 'instant' });
                }
                if (!frame.contentDocument.querySelector('script[src*="/designpanel/assets/preview.js"]')) {
                    this.previewError = this.t('This page cannot be previewed. Check the path or try another page.');
                }
            } catch { clearTimeout(this.frameTimer); this.previewBusy = false; this.previewError = this.t('The preview did not load. Try again.'); }
        },
        async save() {
            if (this.busy || !this.dirty) return;
            if (!await this.valid(true) || this.busy) return;
            this.busy = true; this.error = ''; this.message = '';
            clearTimeout(this.timer); ++this.sequence;
            const submitted = structuredClone(Vue.toRaw(this.values));
            try {
                const response = await tmaxios.post('/api/v1/designpanel/save', { theme: this.state.theme, revision: this.state.revision, values: submitted }, { timeout: 15000 });
                this.state.revision = response.data.revision;
                this.original = submitted;
                this.message = this.t('Design saved. Your changes are live.');
                await this.preview();
            } catch (error) { this.error = this.failure(error); }
            finally { this.busy = false; }
        },
        navigate(event) {
            if (event.origin !== location.origin || event.source !== this.$refs.preview?.contentWindow || event.data?.type !== 'designpanel:navigate') return;
            let url;
            try { url = new URL(event.data.url, this.base + '/'); } catch { return; }
            if (url.origin !== location.origin || !url.href.startsWith(this.base + '/')) return;
            const path = url.pathname.slice(new URL(this.base).pathname.replace(/\/$/, '').length) || '/';
            if (/^\/(tm|api|media)(\/|$)/.test(path)) return;
            this.pathInput = path; this.go();
        },
        unload(event) { if (this.dirty) { event.preventDefault(); event.returnValue = ''; } },
        failure(error) { return this.t(error.response?.data?.message || 'Could not connect. Please try again.'); },
    },
});
