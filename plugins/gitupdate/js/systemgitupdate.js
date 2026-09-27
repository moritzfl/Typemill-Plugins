const gitupdateStyle = document.createElement('style');
gitupdateStyle.textContent = `
.tm-gu{margin-bottom:1.5rem}
.tm-gu__page-header{margin-bottom:1.25rem}
.tm-gu__page-header h1{margin-bottom:.35rem}
.tm-gu-panel{margin-bottom:1.25rem;padding:1.25rem}
.tm-gu-banner{margin-bottom:1rem;padding:.65rem 1rem;font-size:.875rem;line-height:1.4}
.tm-gu-banner.is-ok{background:#0d9488}
.tm-gu-banner.is-error{background:#e11d48}
.tm-gu-head{margin-bottom:.75rem}
.tm-gu-label{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#78716c}
.dark .tm-gu-label{color:#a8a29e}
.tm-gu-sha{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.25rem}
.tm-gu-date{margin-left:.6rem;font-size:.95rem;font-weight:600}
.tm-gu-note{font-size:.875rem;color:#78716c;margin:.5rem 0}
.dark .tm-gu-note{color:#a8a29e}
.tm-gu-note--ok{color:#0d9488;font-weight:600}
.tm-gu-note--warn{color:#b45309}
.tm-gu-note--error{color:#e11d48;font-weight:600}
.tm-gu-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin:1rem 0}
.tm-gu-btn{display:inline-flex;align-items:center;justify-content:center;min-height:2.25rem;padding:0 .9rem;font-size:.8125rem;line-height:1;border:1px solid #d6d3d1;background:#e7e5e4;color:#1c1917;cursor:pointer}
.dark .tm-gu-btn{border-color:#57534e;background:#57534e;color:#f5f5f4}
.tm-gu-btn:disabled{opacity:.5;cursor:not-allowed}
.tm-gu-btn--primary{border-color:#14b8a6;background:#14b8a6;color:#fff}
.tm-gu-btn--small{min-height:1.9rem;padding:0 .6rem;font-size:.75rem}
.tm-gu-group{margin-top:1.15rem}
.tm-gu-group__title{margin:0 0 .15rem;font-size:.75rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#78716c}
.dark .tm-gu-group__title{color:#a8a29e}
.tm-gu-list{margin:0;padding:0;list-style:none}
.tm-gu-item{padding:.45rem 0;border-bottom:1px solid #e7e5e4}
.dark .tm-gu-item{border-color:#44403c}
.tm-gu-item--behind{margin:.35rem 0;padding:.6rem .75rem;background:#fafaf9;border:1px solid #e7e5e4}
.dark .tm-gu-item--behind{background:#1c1917;border-color:#44403c}
.tm-gu-item__row{display:flex;align-items:baseline;gap:.6rem}
.tm-gu-item__name{font-weight:700}
.tm-gu-item__slug{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;color:#a8a29e}
.tm-gu-item__side{margin-left:auto;flex-shrink:0}
.tm-gu-item__diff{margin:.35rem 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8125rem;color:#57534e}
.dark .tm-gu-item__diff{color:#d6d3d1}
.tm-gu-flag{font-size:.75rem}
.tm-gu-flag--ok{color:#0d9488}
.tm-gu-overlay{position:fixed;inset:0;background:rgba(68,64,60,.9);display:flex;align-items:center;justify-content:center;z-index:60}
.tm-gu-dialog{border:1px solid #14b8a6;padding:1.5rem 2rem;max-width:34rem;width:90%}
.tm-gu-dialog__title{font-size:1.1rem;font-weight:700;margin-bottom:.5rem}
.tm-gu-dialog__text{font-size:.875rem;margin-bottom:1.25rem}
.tm-gu-dialog__actions{display:flex;justify-content:flex-end;gap:.5rem}
`;
document.head.appendChild(gitupdateStyle);

const app = Vue.createApp({
    template: gitupdateTemplate,
    data() {
        return {
            loading: true,
            busy: false,
            message: '',
            messageClass: '',
            confirmAll: false,
            confirmItem: null,
            status: { items: [], absent: [], head: null, can_update: false, blocked: false, error: null },
        };
    },
    computed: {
        pending() {
            return (this.status.items || []).filter((item) => item.update_available).length;
        },
        groups() {
            const labels = { plugin: 'gitupdate.plugins', theme: 'gitupdate.themes' };
            return ['plugin', 'theme'].map((kind) => ({
                kind,
                label: this.$filters.translate(labels[kind]),
                items: (this.status.items || [])
                    .filter((item) => item.kind === kind)
                    .slice()
                    .sort((a, b) => Number(b.update_available) - Number(a.update_available)
                        || String(a.name).localeCompare(String(b.name))),
            })).filter((group) => group.items.length);
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        load() {
            this.loading = true;
            tmaxios.get('/api/v1/gitupdate/status').then((response) => {
                this.status = response.data;
                this.loading = false;
            }).catch((error) => {
                this.loading = false;
                this.fail(error);
            });
        },
        ask(item) {
            this.confirmItem = item;
        },
        runConfirmed() {
            const item = this.confirmItem;
            const all = this.confirmAll;
            this.confirmAll = false;
            this.confirmItem = null;
            this.busy = true;
            this.message = '';
            const body = all ? { all: true } : { kind: item.kind, slug: item.slug };
            tmaxios.post('/api/v1/gitupdate/run', body, { timeout: 600000 }).then((response) => {
                const data = response.data || {};
                this.busy = false;
                this.message = data.message || '';
                this.messageClass = 'is-ok';
                if (data.reload) {
                    window.setTimeout(() => window.location.reload(), 600);
                    return;
                }
                this.load();
            }).catch((error) => {
                this.busy = false;
                this.fail(error);
                this.load();
            });
        },
        stamp(sha, date) {
            if (!sha) return this.$filters.translate('gitupdate.never');
            const formatted = this.formatDate(date);
            return formatted ? sha + ' · ' + formatted : sha + ' · ' + this.$filters.translate('gitupdate.unknown_date');
        },
        formatDate(iso) {
            if (!iso) return '';
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) return '';
            return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        },
        fail(error) {
            const data = error && error.response ? error.response.data : null;
            this.message = (data && data.message) || (error && error.message) || 'The update failed.';
            this.messageClass = 'is-error';
        },
    },
});
