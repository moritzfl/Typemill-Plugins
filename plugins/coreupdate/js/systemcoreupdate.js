const coreupdateStyle = document.createElement('style');
coreupdateStyle.textContent = `
.tm-cu{margin-bottom:1.5rem}
.tm-cu__page-header{margin-bottom:1.25rem}
.tm-cu__page-header h1{margin-bottom:.35rem}
.tm-cu-panel{margin-bottom:1.25rem;padding:1.25rem}
.tm-cu-body{display:block}
.tm-cu-heading{font-size:1rem;font-weight:700;margin-bottom:.75rem}
.tm-cu-banner{margin-bottom:1rem;padding:.65rem 1rem;font-size:.875rem;line-height:1.4}
.tm-cu-versions{display:flex;flex-wrap:wrap;gap:2rem;margin-bottom:1rem}
.tm-cu-version{display:flex;flex-direction:column;gap:.15rem}
.tm-cu-version__label{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#78716c}
.dark .tm-cu-version__label{color:#a8a29e}
.tm-cu-version__value{font-size:1.25rem;font-weight:700;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.tm-cu-note{font-size:.875rem;color:#78716c;margin-bottom:.75rem}
.dark .tm-cu-note{color:#a8a29e}
.tm-cu-note--ok{color:#0d9488;font-weight:600}
.tm-cu-note--warn{color:#b45309}
.tm-cu-note--error{color:#e11d48;font-weight:600}
.tm-cu-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}
.tm-cu-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.25rem;padding:0 .9rem;font-size:.8125rem;line-height:1;border:1px solid #d6d3d1;background:#e7e5e4;color:#1c1917;cursor:pointer}
.dark .tm-cu-btn{border-color:#57534e;background:#57534e;color:#f5f5f4}
.tm-cu-btn:hover:not(:disabled){background:#d6d3d1}
.dark .tm-cu-btn:hover:not(:disabled){background:#44403c}
.tm-cu-btn:disabled{opacity:.5;cursor:not-allowed}
.tm-cu-btn--primary{border-color:#14b8a6;background:#14b8a6;color:#fff}
.tm-cu-btn--primary:hover:not(:disabled){background:#0d9488}
.tm-cu-btn--small{min-height:1.9rem;padding:0 .6rem;font-size:.75rem}
.tm-cu-checks{display:flex;flex-direction:column;gap:.5rem}
.tm-cu-check{display:flex;align-items:flex-start;gap:.6rem;font-size:.875rem}
.tm-cu-check__dot{flex-shrink:0;width:.6rem;height:.6rem;margin-top:.35rem;border-radius:999px;background:#a8a29e}
.tm-cu-check__dot.is-ok{background:#14b8a6}
.tm-cu-check__dot.is-warn{background:#f59e0b}
.tm-cu-check__dot.is-error{background:#e11d48}
.tm-cu-backups{display:flex;flex-direction:column;gap:.5rem}
.tm-cu-backup{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;padding:.5rem .75rem;background:#fafaf9;border:1px solid #e7e5e4;font-size:.875rem}
.dark .tm-cu-backup{background:#1c1917;border-color:#44403c}
.tm-cu-backup__version{font-weight:700;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.tm-cu-backup__date{color:#78716c;font-size:.8125rem}
.tm-cu-backup__actions{margin-left:auto;display:flex;gap:.4rem}
.tm-cu-log{display:flex;flex-direction:column;gap:.3rem;font-size:.8125rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#57534e}
.dark .tm-cu-log{color:#d6d3d1}
.tm-cu-overlay{position:fixed;inset:0;background:rgba(68,64,60,.9);display:flex;align-items:center;justify-content:center;z-index:60}
.tm-cu-dialog{border:1px solid #14b8a6;padding:1.5rem 2rem;max-width:34rem;width:90%;box-shadow:0 4px 24px rgba(0,0,0,.25)}
.tm-cu-dialog__title{font-size:1.1rem;font-weight:700;margin-bottom:.5rem}
.tm-cu-dialog__text{font-size:.875rem;margin-bottom:1.25rem}
.tm-cu-dialog__actions{display:flex;justify-content:flex-end;gap:.5rem}
`;
document.head.appendChild(coreupdateStyle);

const app = Vue.createApp({
    template: coreupdateTemplate,

    data() {
        return {
            loading: true,
            busy: false,
            status: {
                installed: null,
                latest: null,
                php_version: '',
                preflight: [],
                backups: [],
                blocked: false,
                update_available: false,
                check_error: null,
            },
            log: [],
            message: '',
            messageClass: '',
            confirmUpdate: false,
            restoreTarget: null,
        };
    },

    mounted() {
        this.load();
    },

    methods: {
        load() {
            this.loading = true;
            tmaxios.get('/api/v1/coreupdate/status')
                .then((response) => {
                    this.status = response.data;
                })
                .catch((error) => {
                    this.showMessage(this.errorText(error, 'Could not load the update status.'), 'error');
                })
                .then(() => {
                    this.loading = false;
                });
        },

        runUpdate() {
            this.confirmUpdate = false;
            this.busy = true;
            this.log = [];
            this.message = '';

            tmaxios.post('/api/v1/coreupdate/run', {}, { timeout: 600000 })
                .then((response) => {
                    this.log = response.data.log || [];
                    this.showMessage(
                        (response.data.message || '') + ' ' + this.$filters.translate('coreupdate.reload'),
                        'success'
                    );
                    this.load();
                })
                .catch((error) => {
                    if (error.response && error.response.data && error.response.data.log) {
                        this.log = error.response.data.log;
                    }
                    this.showMessage(this.errorText(error, 'The update failed.'), 'error');
                    this.load();
                })
                .then(() => {
                    this.busy = false;
                });
        },

        runRollback() {
            const backup = this.restoreTarget;
            this.restoreTarget = null;
            if (!backup) {
                return;
            }

            this.busy = true;
            tmaxios.post('/api/v1/coreupdate/rollback', { backup: backup.name }, { timeout: 600000 })
                .then((response) => {
                    this.showMessage(
                        (response.data.message || '') + ' ' + this.$filters.translate('coreupdate.reload'),
                        'success'
                    );
                    this.load();
                })
                .catch((error) => {
                    this.showMessage(this.errorText(error, 'The rollback failed.'), 'error');
                    this.load();
                })
                .then(() => {
                    this.busy = false;
                });
        },

        removeBackup(backup) {
            this.busy = true;
            tmaxios.delete('/api/v1/coreupdate/backup', { data: { backup: backup.name } })
                .then(() => {
                    this.load();
                })
                .catch((error) => {
                    this.showMessage(this.errorText(error, 'Could not delete the stored version.'), 'error');
                })
                .then(() => {
                    this.busy = false;
                });
        },

        checkClass(check) {
            if (check.ok) {
                return 'is-ok';
            }

            return check.blocking ? 'is-error' : 'is-warn';
        },

        errorText(error, fallback) {
            if (error && error.response && error.response.data && error.response.data.message) {
                return error.response.data.message;
            }

            return fallback;
        },

        showMessage(text, type) {
            this.message = text;
            this.messageClass = type === 'error' ? 'bg-rose-500' : 'bg-teal-500';
        },
    },
});
