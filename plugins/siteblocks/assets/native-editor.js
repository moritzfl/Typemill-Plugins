/** The installed Blox app owns interactions; this adapter owns its document boundary. */
app.component('library-native-editor', {
    props: ['content', 'source', 'entry', 'title', 'scope'],
    emits: ['saved', 'busy', 'dirty', 'error'],
    template: '<div class="sbl-native" @input.capture="captureEdit"><div id="initial-content" hidden></div><div ref="editor" id="editor"></div></div>',
    mounted() {
        const owner = this;
        // Core listeners include anonymous callbacks. Dispose them with their Vue owner.
        const bus = new eventBus.constructor();
        const subscribe = bus.$on.bind(bus);
        bus.$on = (name, listener) => {
            subscribe(name, listener);
            if (Vue.getCurrentInstance()) Vue.onBeforeUnmount(() => bus.$off(name, listener));
        };
        bus.$on('unsafedContent', value => this.$emit('dirty', value));
        bus.$on('publishermessage', message => this.$emit('error', sbT(message)));
        bus.$on('publisherclear', () => this.$emit('error', ''));
        const client = Object.create(tmaxios);
        for (const method of ['get', 'post', 'put', 'delete']) {
            client[method] = (url, payload, options) => {
                if (url !== '/api/v1/block' && url !== '/api/v1/block/move') return tmaxios[method](url, payload, options);
                const body = method === 'delete' ? payload.data : payload;
                const operation = url.endsWith('/move') ? 'move' : { post: 'insert', put: 'update', delete: 'delete' }[method];
                return owner.edit(operation, body);
            };
        }
        const editorData = { ...data, content: structuredClone(Vue.toRaw(this.content)), aiconfigured: false,
            settings: { ...data.settings }, urlinfo: { ...data.urlinfo, route: '/tm/siteblocks' } };
        const native = createSiteBlocksNativeEditor(editorData, client, bus);
        const layout = native.component('siteblock-component');
        layout.props = { markdown: String, disabled: Boolean, index: Number, library: { default: true } };
        native.config.globalProperties.$filters = translatefilter;
        native.mixin({
            watch: {
                updatedmarkdown(value) { if (this.edit) bus.$emit('unsafedContent', typeof value === 'string' && value !== this.element.markdown); },
                newblockmarkdown(value) { if (this.componentType) bus.$emit('unsafedContent', typeof value === 'string' && value.trim() !== ''); },
            },
            mounted() {
                if (this.element?.id === 0 && this.index === 0) this.$el.setAttribute('data-sb-title-placeholder', '');
                if (this.$root === this) {
                    // Core's move callback loses `this`; keep the installed interaction and fix its binding locally.
                    this.onEnd = async event => {
                        try { this.content = (await client.put('/api/v1/block/move', { index_old: event.oldIndex, index_new: event.newIndex })).data.content; }
                        catch { this.content = structuredClone(Vue.toRaw(owner.content)); }
                    };
                }
            },
            beforeUnmount() {
                if (this.onMouseup) window.removeEventListener('mouseup', this.onMouseup);
                if (this.onMousedown) window.removeEventListener('mousedown', this.onMousedown);
            },
        });
        this.nativeApp = Vue.markRaw(native);
        native.mount(this.$refs.editor);
    },
    beforeUnmount() { this.nativeApp?.unmount(); },
    methods: {
        // Table cells emit Markdown on blur; protect their text before focus leaves them.
        captureEdit(event) { if (event.target.isContentEditable) this.$emit('dirty', true); },
        async edit(operation, body) {
            this.$emit('busy', true);
            try {
                const response = await tmaxios.post('/api/v1/siteblocks/edit', {
                    scope: this.scope, id: this.entry.id, revision: this.entry.revision, title: this.title,
                    source: this.source, operation, block_id: operation === 'move' ? body.index_old : body.block_id,
                    index_new: body.index_new, markdown: body.markdown,
                }, { timeout: 15000 });
                this.$emit('saved', response.data);
                return response;
            } catch (error) {
                const message = sbT(error.response?.data?.message || 'SB_CONNECTION');
                this.$emit('error', message);
                // Native handlers display the same translated, actionable message.
                if (error.response?.data) error.response.data.message = message;
                throw error;
            } finally { this.$emit('busy', false); }
        },
    },
});
