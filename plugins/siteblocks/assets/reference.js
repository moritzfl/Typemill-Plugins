(function () {
    if (typeof bloxeditor === 'undefined') return;
    bloxeditor.component('siteblock-reference', {
        props: ['markdown', 'disabled', 'index'], emits: ['updateMarkdownEvent', 'saveBlockEvent'],
        data: () => ({ rows: [], selected: '', search: '', error: '', loading: true, manage: false, scope: '', copy: false, linked: false }),
        computed: {
            current() { return this.rows.find(row => row.id === this.selected); },
            choices() { return this.rows.filter(row => (!row.archived || row.id === this.selected) && row.title.toLocaleLowerCase().includes(this.search.toLocaleLowerCase())); },
            sourceUrl() { return tmaxios.defaults.baseURL.replace(/\/$/, '') + '/tm/siteblocks?' + new URLSearchParams({ id: this.selected, scope: this.scope }); },
        },
        mounted() {
            this.selected = this.markdown?.match(/id="(sb_[a-f0-9]{24})"/)?.[1] || '';
            this.linked = !!this.selected;
            this.load(); eventBus.$on('beforeSave', this.save);
        },
        beforeUnmount() { eventBus.$off('beforeSave', this.save); },
        methods: {
            t: sbT,
            async load() {
                this.loading = true; this.error = '';
                try {
                    const { data: result } = await tmaxios.get('/api/v1/siteblocks/catalog', { params: { path: data.urlinfo.route }, timeout: 15000 });
                    this.rows = result.blocks; this.manage = result.manage; this.scope = result.scope;
                    if (this.selected && !this.current) this.error = this.t('SB_MISSING_REF');
                } catch (error) { this.error = this.t(error.response?.data?.message || 'SB_CONNECTION'); }
                finally { this.loading = false; }
            },
            choose(id) { this.selected = id; this.error = ''; this.copy = false; this.$emit('updateMarkdownEvent', '[:siteblock-ref id="' + id + '" :]'); },
            insertCopy() {
                if (!this.current) return;
                this.copy = true; this.$emit('updateMarkdownEvent', this.current.markdown);
                this.$emit('saveBlockEvent');
            },
            save() { if (this.current && !this.loading) { if (!this.copy) this.choose(this.selected); this.$emit('saveBlockEvent'); } },
        },
        template: `<div class="sb-editor sb-reference-editor">
            <h3>{{ t('Block from library') }}</h3><p>{{ t('SB_COPY_HELP') }}</p>
            <p v-if="loading" role="status">{{ t('Loading…') }}</p>
            <div v-if="error" role="alert"><p>{{ error }}</p><button type="button" @click="load">{{ t('Reload') }}</button></div>
            <fieldset :disabled="disabled || loading">
                <label>{{ t('Search') }}<input type="search" v-model="search"></label>
                <label>{{ t('Content block') }}<select :value="selected" @change="choose($event.target.value)"><option disabled value="">{{ t('Choose a block') }}</option><option v-for="row in choices" :value="row.id">{{ row.title }}{{ row.archived ? ' · ' + t('Archived') : '' }}</option></select></label>
                <p v-if="!rows.length && !loading">{{ t('No published blocks') }}</p>
                <template v-if="current"><p>{{ t('SB_LINKED_HELP') }}</p><p><strong>{{ current.title }} · {{ t('Linked block') }}</strong></p><div v-html="current.html"></div><div class="sb-editor__actions"><button type="button" @click="save">{{ t('Insert linked') }}</button><button type="button" @click="insertCopy">{{ t(linked ? 'Detach as copy' : 'Insert as copy') }}</button></div></template>
            </fieldset>
            <a v-if="manage" :href="sourceUrl" target="_blank" rel="noopener">{{ t(selected ? 'Edit source / usage' : 'Open library') }} ↗</a>
        </div>`,
    });
})();
