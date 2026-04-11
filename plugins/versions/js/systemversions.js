const previewStyle = document.createElement('style');
previewStyle.textContent = `
    .tm-versions-preview-rendered{max-height:60vh;overflow:auto;padding:1.25rem;background:#f5f5f4;color:#1c1917}
    .dark .tm-versions-preview-rendered{background:#1c1917;color:#e7e5e4}
    .tm-versions-preview-rendered h1,.tm-versions-preview-rendered h2,.tm-versions-preview-rendered h3,.tm-versions-preview-rendered h4,.tm-versions-preview-rendered h5,.tm-versions-preview-rendered h6{margin:0 0 .9rem;font-weight:700;line-height:1.2}
    .tm-versions-preview-rendered h1{font-size:2rem}
    .tm-versions-preview-rendered h2{font-size:1.6rem}
    .tm-versions-preview-rendered h3{font-size:1.3rem}
    .tm-versions-preview-rendered p,.tm-versions-preview-rendered ul,.tm-versions-preview-rendered ol,.tm-versions-preview-rendered blockquote,.tm-versions-preview-rendered pre,.tm-versions-preview-rendered table{margin:0 0 1rem}
    .tm-versions-preview-rendered ul,.tm-versions-preview-rendered ol{padding-left:1.5rem}
    .tm-versions-preview-rendered blockquote{padding-left:1rem;border-left:4px solid #14b8a6;opacity:.9}
    .tm-versions-preview-rendered a{color:#0f766e;text-decoration:underline}
    .dark .tm-versions-preview-rendered a{color:#5eead4}
    .tm-versions-preview-rendered code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em;background:rgba(120,113,108,.15);padding:.1rem .3rem;border-radius:.25rem}
    .tm-versions-preview-rendered pre{padding:1rem;overflow:auto;background:rgba(28,25,23,.08)}
    .dark .tm-versions-preview-rendered pre{background:rgba(245,245,244,.08)}
    .tm-versions-preview-rendered pre code{background:transparent;padding:0}
    .tm-versions-preview-rendered img,.tm-versions-preview-rendered video,.tm-versions-preview-rendered audio{max-width:100%}
    .tm-versions-preview-rendered table{width:100%;border-collapse:collapse}
    .tm-versions-preview-rendered th,.tm-versions-preview-rendered td{padding:.5rem;border:1px solid rgba(120,113,108,.35)}
`;
document.head.appendChild(previewStyle);

const app = Vue.createApp({
    template: versionsSystemTemplate,

    data() {
        return {
            loading: true,
            trash: [],
            searchQuery: '',
            message: '',
            messageClass: '',
            previewDetail: null,
            previewLoading: false,
            previewMode: 'rendered',
            restoreTarget: null,
            restoreConflict: false,
            deleteTarget: null,
            confirmEmpty: false,
        };
    },

    mounted() {
        this.loadData();
    },

    computed: {
        filteredTrash() {
            if (!this.searchQuery.trim()) {
                return this.trash;
            }

            var query = this.searchQuery.toLowerCase();
            return this.trash.filter(function (entry) {
                return (entry.title || '').toLowerCase().includes(query)
                    || (entry.url || '').toLowerCase().includes(query)
                    || (entry.path || '').toLowerCase().includes(query);
            });
        }
    },

    methods: {
        loadData() {
            var self = this;
            self.loading = true;
            self.message = '';

            tmaxios.get('/api/v1/versions/system')
                .then(function (response) {
                    self.trash = (response.data.trash || []).map(function (entry) {
                        entry.record_id = entry.record_id || entry.pageid;
                        return entry;
                    });
                    self.loading = false;
                })
                .catch(function (error) {
                    self.loading = false;
                    self.showMessage(handleErrorMessage(error) || 'versions.msg_load_error', 'error');
                });
        },

        prepareRestore(entry) {
            this.restoreTarget = entry;
            this.restoreConflict = false;
        },

        downloadEntry(entry) {
            var self = this;

            tmaxios.get('/api/v1/versions/trash/download', {
                params: {
                    record_id: entry.record_id,
                    record_type: entry.record_type || 'page',
                    version_id: entry.version_id
                },
                responseType: 'blob'
            })
            .then(function (response) {
                var blobUrl = URL.createObjectURL(response.data);
                var link = document.createElement('a');
                link.href = blobUrl;
                link.download = self.downloadFilename(response.headers['content-disposition'], entry);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(function () {
                    URL.revokeObjectURL(blobUrl);
                }, 1000);
            })
            .catch(function (error) {
                self.showMessage(handleErrorMessage(error) || 'versions.msg_download_error', 'error');
            });
        },

        openPreview(entry) {
            var self = this;
            self.previewLoading = true;
            self.previewDetail = null;

            tmaxios.get('/api/v1/versions/trash/version', {
                params: {
                    record_id: entry.record_id,
                    record_type: entry.record_type || 'page',
                    version_id: entry.version_id
                }
            })
            .then(function (response) {
                self.previewDetail = response.data.version || null;
                self.previewMode = self.previewHasRendered(self.previewDetail) ? 'rendered' : 'text';
                self.previewLoading = false;
            })
            .catch(function (error) {
                self.previewLoading = false;
                self.showMessage(handleErrorMessage(error) || 'versions.msg_preview_error', 'error');
            });
        },

        restoreEntry() {
            var self = this;
            if (!self.restoreTarget) {
                return;
            }

            tmaxios.post('/api/v1/versions/trash/restore', {
                record_id: self.restoreTarget.record_id,
                record_type: self.restoreTarget.record_type || 'page',
                version_id: self.restoreTarget.version_id,
                force: self.restoreConflict
            })
            .then(function (response) {
                self.restoreTarget = null;
                self.restoreConflict = false;
                self.showMessage(response.data.message || 'versions.msg_page_restored', 'success');
                self.loadData();
            })
            .catch(function (error) {
                if (error.response && error.response.status === 409) {
                    self.restoreConflict = true;
                    return;
                }
                self.restoreTarget = null;
                self.restoreConflict = false;
                self.showMessage(handleErrorMessage(error) || 'versions.msg_page_restore_error', 'error');
            });
        },

        prepareDelete(entry) {
            this.deleteTarget = entry;
        },

        deleteEntry() {
            var self = this;
            if (!self.deleteTarget) {
                return;
            }

            tmaxios.delete('/api/v1/versions/trash/entry', {
                data: {
                    record_id: self.deleteTarget.record_id,
                    record_type: self.deleteTarget.record_type || 'page'
                }
            })
            .then(function (response) {
                self.deleteTarget = null;
                self.showMessage(response.data.message || 'versions.msg_trash_entry_deleted', 'success');
                self.loadData();
            })
            .catch(function (error) {
                self.deleteTarget = null;
                self.showMessage(handleErrorMessage(error) || 'versions.msg_trash_entry_delete_error', 'error');
            });
        },

        emptyTrash() {
            var self = this;

            tmaxios.delete('/api/v1/versions/trash')
                .then(function (response) {
                    self.confirmEmpty = false;
                    self.showMessage(response.data.message || 'versions.msg_trash_emptied', 'success');
                    self.loadData();
                })
                .catch(function (error) {
                    self.confirmEmpty = false;
                    self.showMessage(handleErrorMessage(error) || 'versions.msg_trash_empty_error', 'error');
                });
        },

        formatDate(value) {
            if (!value) {
                return '';
            }

            return new Date(value).toLocaleString();
        },

        closePreview() {
            this.previewLoading = false;
            this.previewDetail = null;
            this.previewMode = 'rendered';
        },

        previewHasRendered(version) {
            return !!(version && version.rendered_html);
        },

        setPreviewMode(mode) {
            if (mode === 'rendered' && !this.previewHasRendered(this.previewDetail)) {
                return;
            }

            this.previewMode = mode;
        },

        previewModeClass(mode) {
            var active = this.previewMode === mode;
            return active
                ? 'bg-teal-500 border-teal-500 text-white'
                : 'bg-stone-200 dark:bg-stone-500 border-stone-300 dark:border-stone-400 text-stone-900 dark:text-stone-200 hover:bg-stone-300 dark:hover:bg-stone-400';
        },

        previewMeta(version) {
            if (!version) {
                return '';
            }

            var parts = [];
            if (version.user_label) {
                parts.push(version.user_label);
            }
            if (version.created_at) {
                parts.push(this.formatDate(version.created_at));
            }

            return parts.join(' | ');
        },

        downloadFilename(contentDisposition, entry) {
            if (contentDisposition) {
                var match = contentDisposition.match(/filename=\"?([^\";]+)\"?/i);
                if (match && match[1]) {
                    return match[1];
                }
            }

            var baseName = (entry.title || 'trash-entry').replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
            return (baseName || 'trash-entry') + '.zip';
        },

        trashMeta(entry) {
            var text = this.$filters.translate('versions.deleted_meta');
            return text
                .replace('%user%', entry.user_label || '')
                .replace('%date%', this.formatDate(entry.deleted_at));
        },

        showMessage(text, type) {
            this.message = text;
            this.messageClass = type === 'error' ? 'bg-rose-500' : 'bg-teal-500';
        }
    }
});
