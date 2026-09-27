const app = Vue.createApp({
    template: designPanelTemplate,
    data: () => ({ state: {}, values: {}, original: {}, dirty: false, busy: false, message: '', path: '/', width: '100%', previewUrl: '', timer: null, sequence: 0, base: tmaxios.defaults.baseURL.replace(/\/$/, '') }),
    computed: { groups() { return [...new Set((this.state.fields || []).map(field => field.group))]; } },
    mounted() {
        this.load();
        window.addEventListener('message', this.navigate);
        window.addEventListener('beforeunload', this.unload);
    },
    beforeUnmount() { clearTimeout(this.timer); window.removeEventListener('message', this.navigate); window.removeEventListener('beforeunload', this.unload); },
    methods: {
        async load() {
            try {
                this.state = (await tmaxios.get('/api/v1/designpanel/state')).data;
                this.values = structuredClone(Vue.toRaw(this.state.values));
                for (const field of this.state.fields) {
                    if (this.values[field.key] == null || this.values[field.key] === '') {
                        if (field.type === 'checkboxlist') this.values[field.key] = [];
                        else if (field.type === 'checkbox') this.values[field.key] = false;
                    }
                }
                this.original = structuredClone(Vue.toRaw(this.values));
                this.preview();
            } catch (error) { this.fail(error); }
        },
        changed() {
            this.dirty = JSON.stringify(this.values) !== JSON.stringify(this.original);
            clearTimeout(this.timer); this.timer = setTimeout(() => this.preview(), 450);
        },
        reset() { this.values = structuredClone(Vue.toRaw(this.original)); this.dirty = false; this.preview(); },
        preset(key) { if (key) { Object.assign(this.values, this.state.presets[key].settings); this.changed(); } },
        async preview() {
            const sequence = ++this.sequence;
            try {
                const response = await tmaxios.post('/api/v1/designpanel/preview', { theme: this.state.theme, values: this.values, path: this.path });
                if (sequence === this.sequence) { this.previewUrl = response.data.url; this.message = ''; }
            } catch (error) { if (sequence === this.sequence) this.fail(error); }
        },
        async save() {
            this.busy = true;
            const submitted = structuredClone(Vue.toRaw(this.values));
            try {
                const response = await tmaxios.post('/api/v1/designpanel/save', { theme: this.state.theme, revision: this.state.revision, values: submitted });
                this.state.revision = response.data.revision;
                this.original = submitted;
                this.dirty = JSON.stringify(this.values) !== JSON.stringify(this.original);
                await this.preview(); this.message = response.data.message;
            } catch (error) { this.fail(error); }
            finally { this.busy = false; }
        },
        navigate(event) {
            if (event.origin !== location.origin || event.source !== this.$refs.preview?.contentWindow || event.data?.type !== 'designpanel:navigate') return;
            const url = new URL(event.data.url, this.base + '/');
            if (url.origin !== location.origin || !url.href.startsWith(this.base + '/')) return;
            const path = url.pathname.slice(new URL(this.base).pathname.replace(/\/$/, '').length) || '/';
            if (path.startsWith('/tm/') || path.startsWith('/api/') || path.startsWith('/media/')) return;
            this.path = path; this.preview();
        },
        unload(event) { if (this.dirty) { event.preventDefault(); event.returnValue = ''; } },
        fail(error) { this.message = error.response?.data?.message || error.message; },
    },
});
