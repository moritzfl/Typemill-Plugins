const formatConfig = 'bold,italic,link,code';
const app = Vue.createApp({
    template: siteBlocksTemplate,
    components: { medialib },
    data: () => ({ blocks: [], scopes: [], scope: '', permissions: {}, footerSupported: false, placement: { footer: '', revision: '' }, footer: '',
        tab: 'library', search: '', filter: '', selected: null, title: '', markdown: '', original: '', parts: [], active: -1,
        mode: 'visual', busy: false, error: '', message: '', previewHtml: '', previewUrl: '', previewPath: '/',
        mediaOpen: false, history: '', loadSequence: 0, base: tmaxios.defaults.baseURL.replace(/\/$/, '') }),
    computed: {
        dirty() { return this.selected !== null && JSON.stringify([this.title, this.source()]) !== this.original; },
        visible() { return this.blocks.filter(row => row.title.toLocaleLowerCase().includes(this.search.toLocaleLowerCase()) && (!this.filter || this.status(row) === this.filter)); },
        available() { return this.blocks.filter(row => row.published !== null && !row.archived); },
        component() {
            const text = this.parts[this.active] || '';
            if (text.startsWith('[:siteblock ')) return 'siteblock-component';
            if (/^#{1,6} /.test(text)) return 'headline-component';
            if (/^[-*+] /.test(text)) return 'ulist-component';
            if (/^\d+\. /.test(text)) return 'olist-component';
            if (/^> /.test(text)) return 'quote-component';
            return 'markdown-component';
        },
    },
    async mounted() {
        this.scope = new URLSearchParams(location.search).get('scope') || '';
        this.previewPath = this.scope || '/';
        await this.load();
        const id = new URLSearchParams(location.search).get('id');
        if (id && this.blocks.some(row => row.id === id)) await this.open(this.blocks.find(row => row.id === id));
        window.addEventListener('beforeunload', this.unload);
        eventBus.$on('inlineFormat', this.inlineFormat);
    },
    beforeUnmount() { window.removeEventListener('beforeunload', this.unload); eventBus.$off('inlineFormat', this.inlineFormat); },
    methods: {
        t: sbT,
        inlineFormat(markdown) { if (this.active >= 0 && this.permissions.update && !this.busy) this.parts[this.active] = markdown; },
        source() { return this.mode === 'raw' ? this.markdown : this.parts.join('\n\n'); },
        status(row) { return row.archived ? 'Archived' : row.published === null ? 'Draft' : row.draft !== row.published ? 'Unpublished changes' : 'Published'; },
        fail(error) { this.error = this.t(error.response?.data?.message || 'SB_CONNECTION'); },
        async load() {
            const sequence = ++this.loadSequence;
            this.busy = true; this.error = '';
            try {
                const { data: state } = await tmaxios.get('/api/v1/siteblocks/state', { params: { scope: this.scope }, timeout: 15000 });
                if (sequence !== this.loadSequence) return;
                Object.assign(this, state); this.footer = state.placement.footer;
            } catch (error) { this.fail(error); }
            finally { if (sequence === this.loadSequence) this.busy = false; }
        },
        async reload() {
            if (this.dirty && !confirm(this.t('SB_LEAVE'))) return;
            const id = this.selected?.id;
            this.selected = null;
            await this.load();
            if (id && this.blocks.some(row => row.id === id)) await this.open(this.blocks.find(row => row.id === id));
        },
        async changeScope(event) {
            const next = event.target.value;
            if (this.dirty && !confirm(this.t('SB_LEAVE'))) { event.target.value = this.scope; return; }
            this.scope = next; this.selected = null; this.previewPath = next || '/'; await this.load();
        },
        async open(row = null) {
            if (this.dirty && !confirm(this.t('SB_LEAVE'))) return;
            this.selected = row ? structuredClone(Vue.toRaw(row)) : { id: '', draft: '', published: null, revision: '', usage: [], history: [] };
            this.title = row?.title || ''; this.markdown = row?.draft || ''; this.mode = 'raw';
            this.original = JSON.stringify([this.title, this.markdown]); this.active = -1;
            this.previewHtml = ''; this.previewUrl = ''; this.message = ''; this.error = ''; this.history = '';
            await this.setMode('visual');
        },
        closeEditor() {
            if (this.dirty && !confirm(this.t('SB_LEAVE'))) return;
            this.selected = null;
        },
        async setMode(mode) {
            if (mode === this.mode) return;
            if (mode === 'raw') { this.markdown = this.source(); this.mode = mode; this.active = -1; return; }
            this.busy = true;
            try {
                const { data } = await tmaxios.post('/api/v1/siteblocks/parse', { scope: this.scope, markdown: this.markdown }, { timeout: 15000 });
                this.parts = data.parts; this.mode = mode; this.original = JSON.stringify([this.title, this.parts.join('\n\n')]);
                // Preserve dirty state across a representation change.
                if (this.markdown !== (this.selected.draft || '') || this.title !== (this.selected.title || '')) this.original = JSON.stringify([this.selected.title || '', this.selected.draft || '']);
            } catch (error) { this.fail(error); }
            finally { this.busy = false; }
        },
        add(type) {
            const defaults = { paragraph: '', heading: '## ', list: '- ', quote: '> ', layout: '[:siteblock data="' + btoa(JSON.stringify({ version: 1, type: 'columns', items: [] })).replace(/=+$/, '') + '" :]' };
            this.parts.push(defaults[type]); this.active = this.parts.length - 1;
        },
        move(index, delta) { const [part] = this.parts.splice(index, 1); this.parts.splice(index + delta, 0, part); this.active = -1; },
        remove(index) { this.parts.splice(index, 1); this.active = -1; },
        async save() {
            if (this.busy || !this.permissions.update) return false;
            this.busy = true; this.error = ''; this.message = '';
            try {
                const markdown = this.source();
                const { data } = await tmaxios.post('/api/v1/siteblocks/save', { id: this.selected.id, revision: this.selected.revision, scope: this.scope, title: this.title, markdown }, { timeout: 15000 });
                this.selected = { ...data.block, usage: this.selected.usage || [] };
                this.original = JSON.stringify([this.title, markdown]); this.message = this.t('Draft saved');
                await this.load();
                this.selected.usage = this.blocks.find(row => row.id === this.selected.id)?.usage || [];
                return true;
            } catch (error) { this.fail(error); return false; }
            finally { this.busy = false; }
        },
        async action(action) {
            if (this.busy || !this.selected?.id) return;
            if (action === 'delete' && !confirm(this.t('SB_DELETE'))) return;
            if (this.dirty && action !== 'publish' && !confirm(this.t('SB_LEAVE'))) return;
            if (action === 'publish' && this.dirty && !await this.save()) return;
            this.busy = true; this.error = '';
            try {
                const { data } = await tmaxios.post('/api/v1/siteblocks/action', { scope: this.scope, id: this.selected.id, revision: this.selected.revision, action, history: this.history }, { timeout: 15000 });
                this.original = JSON.stringify([this.title, this.source()]);
                if (!data.block) { this.selected = null; await this.load(); }
                else { await this.load(); await this.open(this.blocks.find(row => row.id === data.block.id)); }
                this.message = this.t('Changes saved');
            } catch (error) { this.fail(error); }
            finally { this.busy = false; }
        },
        async duplicate() { const title = this.title + ' — ' + this.t('Copy'); const source = this.source(); this.original = JSON.stringify([this.title, source]); await this.open(); this.title = title; this.markdown = source; this.mode = 'raw'; },
        async preview() {
            this.busy = true; this.error = ''; this.previewUrl = '';
            try {
                const path = this.selected.id ? 'preview' : 'parse';
                const { data } = await tmaxios.post('/api/v1/siteblocks/' + path, { id: this.selected.id, scope: this.scope, markdown: this.source(), path: this.previewPath }, { timeout: 15000 });
                this.previewHtml = data.html; this.previewUrl = data.url || '';
            } catch (error) { this.fail(error); }
            finally { this.busy = false; }
        },
        async place() {
            this.busy = true; this.error = '';
            try { const { data } = await tmaxios.post('/api/v1/siteblocks/placement', { scope: this.scope, footer: this.footer, revision: this.placement.revision }, { timeout: 15000 }); this.placement = data.placement; this.message = this.t('Placement saved'); await this.load(); }
            catch (error) { this.fail(error); } finally { this.busy = false; }
        },
        async exportLibrary() {
            try {
                const { data } = await tmaxios.get('/api/v1/siteblocks/export', { params: { scope: this.scope }, timeout: 15000 });
                const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
                const link = document.createElement('a'); link.href = url; link.download = 'siteblocks.json'; link.click(); URL.revokeObjectURL(url);
            } catch (error) { this.fail(error); }
        },
        async importLibrary(event) {
            const file = event.target.files[0]; if (!file) return;
            this.busy = true; this.error = '';
            try { const bundle = JSON.parse(await file.text()); await tmaxios.post('/api/v1/siteblocks/import', { scope: this.scope, bundle }, { timeout: 30000 }); await this.load(); this.message = this.t('Import completed'); }
            catch (error) { this.error = error instanceof SyntaxError ? this.t('SB_BAD_IMPORT') : this.t(error.response?.data?.message || 'SB_CONNECTION'); }
            finally { this.busy = false; event.target.value = ''; }
        },
        async media() { this.mediaOpen = true; await this.$nextTick(); this.$refs.media.showModal(); },
        selectMedia(value) {
            if (typeof value === 'string') { this.parts.push('![](' + value + ')'); this.active = this.parts.length - 1; }
            this.$refs.media.close();
        },
        unload(event) { if (this.dirty) { event.preventDefault(); event.returnValue = ''; } },
    },
});
// Reuse Typemill's input components without mounting its page-bound editor app.
const bloxeditor = app;
app.mixin({ beforeUnmount() {
    if (this.beforeSave) eventBus.$off('beforeSave', this.beforeSave);
    if (this.onMouseup) window.removeEventListener('mouseup', this.onMouseup);
    if (this.onMousedown) window.removeEventListener('mousedown', this.onMousedown);
} });
app.component('library-preview', {
    props: ['markdown', 'scope'],
    data: () => ({ html: '', error: '', sequence: 0 }),
    mounted() { this.render(); },
    watch: { markdown() { this.render(); } },
    methods: {
        async render() {
            const sequence = ++this.sequence;
            this.error = '';
            try {
                const { data } = await tmaxios.post('/api/v1/siteblocks/parse', { scope: this.scope, markdown: this.markdown }, { timeout: 15000 });
                if (sequence === this.sequence) this.html = data.html;
            } catch { if (sequence === this.sequence) this.error = sbT('SB_CONNECTION'); }
        },
    },
    template: '<div class="sbl__part-preview"><p v-if="error" role="alert">{{ error }}</p><div v-else v-html="html"></div></div>',
});
