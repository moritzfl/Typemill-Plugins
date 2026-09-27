(function () {
    if (typeof bloxeditor === 'undefined') return;
    const blank = () => ({ title: '', text: '', src: '', alt: '', caption: '', url: '', label: '', x: 50, y: 50 });
    const defaults = () => ({ version: 1, type: 'columns', title: '', text: '', label: '', url: '', columns: 3, items: [blank(), blank()], folder: '', tag: '', limit: 6, layout: 'cards' });
    const layouts = {
        hero: ['Hero', 'A prominent heading, text, one image and an optional button. Only the first image is shown; other items are kept when you switch layouts.'],
        cta: ['Call to action', 'A heading, short message and button that invite readers to take the next step.'],
        columns: ['Columns', 'Two to four columns of cards. Each card can have an image, text and a link. Cards stack on small screens.'],
        gallery: ['Gallery', 'A regular grid of cropped images. Readers can open each image at full size.'],
        slideshow: ['Slideshow', 'A horizontal strip of images with previous and next controls. It does not play automatically.'],
        masonry: ['Masonry', 'An image wall with natural image proportions instead of crops of equal height.'],
        collection: ['Page collection', 'An automatically updated list of published pages. Choose a folder and optional tag to narrow the list.'],
        shared: ['Shared footer', 'Reuse the shared footer columns on this page. Edit them once in Site Blocks settings to update every place they appear.'],
    };
    bloxeditor.component('siteblock-component', {
        props: ['markdown', 'disabled', 'index'],
        emits: ['updateMarkdownEvent', 'saveBlockEvent'],
        data: () => ({ block: defaults(), error: '', mediaIndex: null, layouts }),
        components: { medialib },
        computed: {
            images() { return ['gallery', 'slideshow', 'masonry', 'hero'].includes(this.block.type); },
            items() { return !['cta', 'collection', 'shared'].includes(this.block.type); },
            canConfigure() { return typeof siteBlocksCanConfigure !== 'undefined' && siteBlocksCanConfigure; },
            settingsUrl() { return tmaxios.defaults.baseURL.replace(/\/$/, '') + '/tm/plugins#siteblocks-footer'; },
        },
        mounted() {
            if (this.markdown) {
                try {
                    const encoded = this.markdown.match(/data="([A-Za-z0-9_-]+)"/)[1];
                    const bytes = Uint8Array.from(atob(encoded.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
                    const parsed = JSON.parse(new TextDecoder().decode(bytes));
                    if ((parsed.version ?? 1) !== 1 || !Object.hasOwn(layouts, parsed.type) || (parsed.items !== undefined && !Array.isArray(parsed.items))) throw new Error();
                    this.block = { ...defaults(), ...parsed, items: parsed.items || [] };
                } catch { this.error = 'This block cannot be edited. Its original data has been retained.'; }
            }
            this.update();
            eventBus.$on('beforeSave', this.save);
        },
        beforeUnmount() { eventBus.$off('beforeSave', this.save); },
        methods: {
            t(value) { return this.$filters.translate(value); },
            imageUrl(src) {
                const base = tmaxios.defaults.baseURL.replace(/\/$/, '');
                return src.startsWith('media/') ? base + '/' + src : (src.startsWith('/') ? base + src : src);
            },
            update() {
                if (this.error) return;
                const bytes = new TextEncoder().encode(JSON.stringify(this.block));
                const encoded = btoa(Array.from(bytes, b => String.fromCharCode(b)).join('')).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                this.$emit('updateMarkdownEvent', '[:siteblock data="' + encoded + '" :]');
            },
            save() { if (!this.error) { this.update(); this.$emit('saveBlockEvent'); } },
            add() { this.block.items.push(blank()); this.update(); },
            move(index, delta) {
                const [item] = this.block.items.splice(index, 1);
                this.block.items.splice(index + delta, 0, item); this.update();
            },
            media(value) {
                if (typeof value === 'string' && this.mediaIndex !== null) this.block.items[this.mediaIndex].src = value;
                this.$refs.media?.close(); this.mediaIndex = null; this.update();
            },
            async openMedia(index) {
                this.mediaIndex = index;
                await this.$nextTick();
                this.$refs.media.showModal();
            },
        },
        template: `<div class="sb-editor">
            <p v-if="error" role="alert">{{ t(error) }}</p>
            <fieldset :disabled="disabled || !!error" @input="update" @change="update">
                <legend>{{ t('Site layout') }}</legend>
                <p class="sb-editor__workflow">{{ t('Save this block to the page draft. Publish the page to make it public.') }}</p>
                <label>{{ t('Layout') }}<select v-model="block.type" :aria-describedby="'sb-guide-' + index"><option v-for="(layout,type) in layouts" :value="type">{{ t(layout[0]) }}</option></select></label>
                <p class="sb-editor__guide" :id="'sb-guide-' + index">{{ t(layouts[block.type]?.[1] || '') }}</p>
                <template v-if="block.type !== 'shared'">
                    <label>{{ t('Heading') }}<input v-model="block.title" maxlength="4000"></label>
                    <label>{{ t('Text') }}<textarea v-model="block.text" maxlength="4000" :aria-describedby="'sb-text-help-' + index"></textarea></label>
                    <p class="sb-editor__hint" :id="'sb-text-help-' + index">{{ t('Plain text with line breaks. Use a text block for Markdown formatting.') }}</p>
                    <label v-if="!['hero','cta','slideshow'].includes(block.type)">{{ t('Columns') }}<select v-model.number="block.columns"><option>2</option><option>3</option><option>4</option></select></label>
                </template>
                <template v-if="['hero','cta'].includes(block.type)">
                    <label>{{ t('Button label') }}<input v-model="block.label"></label><label>{{ t('Button link') }}<input v-model="block.url" placeholder="/contact"></label>
                </template>
                <template v-if="block.type === 'collection'">
                    <label>{{ t('Folder (empty = whole site)') }}<input v-model="block.folder" placeholder="/news"></label>
                    <label>{{ t('Tag (optional)') }}<input v-model="block.tag"></label>
                    <label>{{ t('Latest pages') }}<input type="number" min="1" max="24" v-model.number="block.limit"></label>
                    <label>{{ t('Presentation') }}<select v-model="block.layout"><option value="cards">{{ t('Cards') }}</option><option value="list">{{ t('List') }}</option></select></label>
                    <p class="sb-editor__hint">{{ t('Only published, visible, unrestricted pages appear. Set collection tags in the Siteblocks tab of each page.') }}</p>
                </template>
                <div v-if="block.type === 'shared'" class="sb-editor__shared">
                    <p>{{ t('These columns also appear in the site footer, in addition to any theme footer content.') }}</p>
                    <a v-if="canConfigure" :href="settingsUrl" target="_blank" rel="noopener">{{ t('Edit shared footer') }} ↗<span class="sb-editor__sr-only">{{ t('(opens in a new tab)') }}</span></a>
                    <p v-else>{{ t('Ask a site administrator to edit the shared footer in Plugins → Site Blocks.') }}</p>
                </div>
                <template v-if="items">
                    <fieldset v-for="(item, i) in (block.type === 'hero' ? block.items.slice(0,1) : block.items)" :key="i" class="sb-editor__item">
                        <legend>{{ t('Item') }} {{ i + 1 }}</legend>
                        <template v-if="images || block.type === 'columns'">
                            <label>{{ t('Image') }}<input v-model="item.src" placeholder="/media/images/photo.jpg"></label>
                            <button type="button" @click="openMedia(i)">{{ t('Choose from media') }}</button>
                            <label>{{ t('Alternative text') }}<input v-model="item.alt"></label>
                            <label>{{ t('Focal point X') }}<input type="range" min="0" max="100" v-model.number="item.x"></label>
                            <label>{{ t('Focal point Y') }}<input type="range" min="0" max="100" v-model.number="item.y"></label>
                            <img v-if="item.src" :src="imageUrl(item.src)" :alt="item.alt" class="sb-editor__image" :style="{objectPosition: item.x + '% ' + item.y + '%'}">
                        </template>
                        <label v-if="images">{{ t('Caption') }}<input v-model="item.caption"></label>
                        <template v-else><label>{{ t('Title') }}<input v-model="item.title"></label><label>{{ t('Text') }}<textarea v-model="item.text"></textarea></label><label>{{ t('Link') }}<input v-model="item.url"></label><label>{{ t('Link label') }}<input v-model="item.label"></label></template>
                        <div class="sb-editor__actions"><button type="button" :disabled="i === 0" @click="move(i,-1)">{{ t('Move up') }}</button><button type="button" :disabled="i === block.items.length - 1" @click="move(i,1)">{{ t('Move down') }}</button><button type="button" @click="block.items.splice(i,1); update()">{{ t('Remove item') }}</button></div>
                    </fieldset>
                    <button type="button" :disabled="block.items.length >= (block.type === 'hero' ? 1 : 24)" @click="add">{{ t('Add item') }}</button>
                </template>
            </fieldset>
            <dialog v-if="mediaIndex !== null" ref="media" class="sb-editor__media" :aria-label="t('Choose from media')" @close="mediaIndex = null" @keydown.esc.stop.prevent="$refs.media.close()"><button type="button" @click="$refs.media.close()">{{ t('Close library') }}</button><medialib parentcomponent="images" @addFromMedialibEvent="media"></medialib></dialog>
        </div>`,
    });
})();
