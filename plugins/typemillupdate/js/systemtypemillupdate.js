const typemillupdateStyle = document.createElement('style');
typemillupdateStyle.textContent = `
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
.tm-cu-file{font-size:.8125rem}
.tm-cu-summary{display:flex;flex-direction:column;gap:.3rem;margin-bottom:1rem;font-size:.85rem}
.tm-cu-summary li{display:flex;justify-content:space-between;gap:1rem;border-bottom:1px solid #e7e5e4;padding-bottom:.25rem}
.dark .tm-cu-summary li{border-color:#44403c}
`;
document.head.appendChild(typemillupdateStyle);

const app = Vue.createApp({
    template: typemillupdateTemplate,

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
            uploadProgress: null,
            uploadResult: null,
        };
    },

    mounted() {
        this.load();
    },

    methods: {
        load() {
            this.loading = true;
            tmaxios.get('/api/v1/typemillupdate/status')
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
            this.performInstall({});
        },

        installUploaded() {
            const archive = this.uploadResult ? this.uploadResult.archive : null;
            this.uploadResult = null;

            if (archive) {
                this.performInstall({ archive: archive });
            }
        },

        performInstall(payload) {
            this.busy = true;
            this.log = [];
            this.message = '';

            tmaxios.post('/api/v1/typemillupdate/run', payload, { timeout: 600000 })
                .then((response) => {
                    this.log = response.data.log || [];
                    this.showMessage(
                        (this.messageText(response.data) + ' '
                            + this.$filters.translate('typemillupdate.reload')).trim(),
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

        onArchiveChosen(event) {
            const file = event.target.files && event.target.files[0];
            if (file) {
                this.uploadArchive(file);
            }
        },

        /**
         * PHP's default upload_max_filesize is 2 MB, which is smaller than a
         * release archive, so the file is sliced and posted as base64 in
         * ordinary JSON requests instead of as a form upload.
         */
        uploadArchive(file) {
            const chunkSize = 512 * 1024;
            const total = Math.max(1, Math.ceil(file.size / chunkSize));
            const uploadId = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

            this.busy = true;
            this.message = '';
            this.uploadProgress = 0;

            const sendChunk = (index) => {
                if (index >= total) {
                    return Promise.resolve();
                }

                return this.readAsBase64(file.slice(index * chunkSize, (index + 1) * chunkSize))
                    .then((data) => tmaxios.post('/api/v1/typemillupdate/upload/chunk', {
                        uploadId: uploadId,
                        index: index,
                        total: total,
                        data: data,
                    }))
                    .then(() => {
                        this.uploadProgress = Math.round(((index + 1) / total) * 100);
                        return sendChunk(index + 1);
                    });
            };

            sendChunk(0)
                .then(() => tmaxios.post('/api/v1/typemillupdate/upload/finalize', {
                    uploadId: uploadId,
                    total: total,
                }))
                .then((response) => {
                    this.uploadResult = response.data;
                })
                .catch((error) => {
                    this.showMessage(this.errorText(error, 'The upload failed.'), 'error');
                })
                .then(() => {
                    this.busy = false;
                    this.uploadProgress = null;
                    if (this.$refs.archiveInput) {
                        this.$refs.archiveInput.value = '';
                    }
                });
        },

        readAsBase64(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();

                reader.onload = () => {
                    const result = String(reader.result);
                    const comma = result.indexOf(',');
                    resolve(comma === -1 ? result : result.slice(comma + 1));
                };
                reader.onerror = () => reject(new Error('Could not read the file.'));

                reader.readAsDataURL(blob);
            });
        },

        runRollback() {
            const backup = this.restoreTarget;
            this.restoreTarget = null;
            if (!backup) {
                return;
            }

            this.busy = true;
            tmaxios.post('/api/v1/typemillupdate/rollback', { backup: backup.name }, { timeout: 600000 })
                .then((response) => {
                    this.showMessage(
                        (this.messageText(response.data) + ' '
                            + this.$filters.translate('typemillupdate.reload')).trim(),
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
            tmaxios.delete('/api/v1/typemillupdate/backup', { data: { backup: backup.name } })
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

        /**
         * Text of an environment check in the admin language.
         *
         * The translator resolves a key to a string but cannot fill in values,
         * so the paths and sizes travel beside the key and are substituted
         * here. A key with no entry yet resolves to itself, in which case the
         * English sentence the backend already produced is used instead.
         */
        checkText(check) {
            if (!check.label) {
                return check.detail;
            }

            const translated = this.$filters.translate(check.label);
            if (!translated || translated === check.label) {
                return check.detail;
            }

            return Object.keys(check.params || {}).reduce(
                (text, name) => text.split('{' + name + '}').join(check.params[name]),
                translated
            );
        },

        /**
         * Text of an API message in the admin language.
         *
         * A message that carries a value travels as a key plus the values,
         * because the translator resolves a key but cannot fill placeholders.
         * A plain message is looked up by its English sentence, which is how
         * the admin translator derives its keys, and falls back to that
         * sentence when the language file has no entry for it.
         */
        messageText(payload) {
            if (!payload) {
                return '';
            }

            if (payload.message_key) {
                const translated = this.$filters.translate(payload.message_key);
                if (translated && translated !== payload.message_key) {
                    return Object.keys(payload.message_params || {}).reduce(
                        (text, name) => text.split('{' + name + '}').join(payload.message_params[name]),
                        translated
                    );
                }
            }

            return payload.message ? this.$filters.translate(payload.message) : '';
        },

        errorText(error, fallback) {
            const fromServer = error && error.response ? this.messageText(error.response.data) : '';

            return fromServer || this.$filters.translate(fallback);
        },

        showMessage(text, type) {
            this.message = text;
            this.messageClass = type === 'error' ? 'bg-rose-500' : 'bg-teal-500';
        },
    },
});
