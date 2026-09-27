(function () {
    if (typeof bloxeditor === 'undefined') return;
    const blank = () => ({ title: '', text: '', src: '', alt: '', caption: '', url: '', label: '', x: 50, y: 50 });
    const defaults = () => ({ version: 1, type: 'columns', title: '', text: '', label: '', url: '', columns: 3, items: [blank(), blank()], folder: '', tag: '', limit: 6, layout: 'cards' });
    bloxeditor.component('siteblock-component', {
        props: ['markdown', 'disabled', 'index'],
        emits: ['updateMarkdownEvent', 'saveBlockEvent'],
        data: () => ({ block: defaults(), error: '', mediaIndex: null }),
        components: { medialib },
        computed: {
            images() { return ['gallery', 'slideshow', 'masonry', 'hero'].includes(this.block.type); },
            items() { return !['cta', 'collection', 'shared'].includes(this.block.type); },
        },
        mounted() {
            if (this.markdown) {
                try {
                    const encoded = this.markdown.match(/data="([A-Za-z0-9_-]+)"/)[1];
                    const bytes = Uint8Array.from(atob(encoded.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
                    const parsed = JSON.parse(new TextDecoder().decode(bytes));
                    if ((parsed.version ?? 1) !== 1 || (parsed.items !== undefined && !Array.isArray(parsed.items))) throw new Error();
                    this.block = { ...defaults(), ...parsed, items: parsed.items || [] };
                } catch { this.error = 'This block cannot be edited. Its original data has been retained.'; }
            }
            this.update();
            eventBus.$on('beforeSave', this.save);
        },
        beforeUnmount() { eventBus.$off('beforeSave', this.save); },
        methods: {
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
                this.mediaIndex = null; this.update();
            },
        },
        template: `<div class="sb-editor">
            <p v-if="error" role="alert">{{ error }}</p>
            <fieldset :disabled="disabled || !!error" @input="update" @change="update">
                <legend>Site layout</legend>
                <label>Layout<select v-model="block.type"><option v-for="(label,type) in {hero:'Hero',cta:'Call to action',columns:'Columns',gallery:'Gallery',slideshow:'Slideshow',masonry:'Masonry',collection:'Page collection',shared:'Shared fields'}" :value="type">{{ label }}</option></select></label>
                <template v-if="block.type !== 'shared'">
                    <label>Heading<input v-model="block.title" maxlength="4000"></label>
                    <label>Text<textarea v-model="block.text" maxlength="4000"></textarea></label>
                    <label v-if="!['hero','cta','slideshow'].includes(block.type)">Columns<select v-model.number="block.columns"><option>2</option><option>3</option><option>4</option></select></label>
                </template>
                <template v-if="['hero','cta'].includes(block.type)">
                    <label>Button label<input v-model="block.label"></label><label>Button link<input v-model="block.url" placeholder="/contact"></label>
                </template>
                <template v-if="block.type === 'collection'">
                    <label>Folder (empty = whole site)<input v-model="block.folder" placeholder="/news"></label>
                    <label>Tag (optional)<input v-model="block.tag"></label>
                    <label>Latest pages<input type="number" min="1" max="24" v-model.number="block.limit"></label>
                    <label>Presentation<select v-model="block.layout"><option value="cards">Cards</option><option value="list">List</option></select></label>
                    <p>Only published, visible, unrestricted pages appear. Set tags in each page's Siteblocks tab.</p>
                </template>
                <p v-if="block.type === 'shared'">Shared contact and footer fields are edited once in Plugins → Site Blocks.</p>
                <template v-if="items">
                    <fieldset v-for="(item, i) in (block.type === 'hero' ? block.items.slice(0,1) : block.items)" :key="i" class="sb-editor__item">
                        <legend>Item {{ i + 1 }}</legend>
                        <template v-if="images || block.type === 'columns'">
                            <label>Image<input v-model="item.src" placeholder="/media/images/photo.jpg"></label>
                            <button type="button" @click="mediaIndex = i">Choose from media</button>
                            <label>Alternative text<input v-model="item.alt"></label>
                            <label>Focal point X<input type="range" min="0" max="100" v-model.number="item.x"></label>
                            <label>Focal point Y<input type="range" min="0" max="100" v-model.number="item.y"></label>
                            <img v-if="item.src" :src="imageUrl(item.src)" :alt="item.alt" class="sb-editor__image" :style="{objectPosition: item.x + '% ' + item.y + '%'}">
                        </template>
                        <label v-if="images">Caption<input v-model="item.caption"></label>
                        <template v-else><label>Title<input v-model="item.title"></label><label>Text<textarea v-model="item.text"></textarea></label><label>Link<input v-model="item.url"></label><label>Link label<input v-model="item.label"></label></template>
                        <div class="sb-editor__actions"><button type="button" :disabled="i === 0" @click="move(i,-1)">Move up</button><button type="button" :disabled="i === block.items.length - 1" @click="move(i,1)">Move down</button><button type="button" @click="block.items.splice(i,1); update()">Remove item</button></div>
                    </fieldset>
                    <button type="button" :disabled="block.items.length >= (block.type === 'hero' ? 1 : 24)" @click="add">Add item</button>
                </template>
            </fieldset>
            <div v-if="mediaIndex !== null" class="sb-editor__media"><button type="button" @click="mediaIndex = null">Close library</button><medialib parentcomponent="images" @addFromMedialibEvent="media"></medialib></div>
        </div>`,
    });
})();
