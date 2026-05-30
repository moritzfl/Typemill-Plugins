const trashStyle = document.createElement('style');
trashStyle.textContent = `
.tm-trash{margin-bottom:1.5rem}
.tm-trash__page-header{margin-bottom:1.25rem}
.tm-trash__page-header h1{margin-bottom:.35rem}
.tm-trash-panel{overflow:visible}
.tm-trash-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem 1rem;min-height:3.25rem;padding:.75rem 1.25rem;border-bottom:1px solid #e7e5e4;background:#fafaf9}
.dark .tm-trash-toolbar{border-color:#44403c;background:#1c1917}
.tm-trash-toolbar__primary{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;flex-shrink:0}
.tm-trash-toolbar__actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;flex-shrink:0}
.tm-trash-subtoolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem 1.5rem;padding:.75rem 1.25rem;border-bottom:1px solid #f5f5f4;background:#fff}
.dark .tm-trash-subtoolbar{border-color:#292524;background:#292524}
.tm-trash-search{position:relative;flex:1 1 14rem;min-width:12rem;max-width:26rem}
.tm-trash-search input{width:100%;height:2.375rem;padding:0 .875rem 0 2.25rem;font-size:.875rem;line-height:1.25;border:1px solid #d6d3d1;background:#f5f5f4;color:#1c1917}
.tm-trash-search input:focus{outline:none;border-color:#14b8a6}
.dark .tm-trash-search input{border-color:#57534e;background:#44403c;color:#fafaf9}
.dark .tm-trash-search input:focus{border-color:#2dd4bf}
.tm-trash-search__icon{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:#a8a29e;pointer-events:none}
.tm-trash-meta{font-size:.8125rem;color:#78716c;white-space:nowrap;flex-shrink:0;padding-left:.5rem}
.tm-trash-banner{margin:1rem 1.25rem 0;padding:.65rem 1rem;font-size:.875rem;line-height:1.4}
.tm-trash-content{padding:.5rem 0 1rem}
.tm-trash-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.25rem;padding:0 .9rem;font-size:.8125rem;line-height:1;border:1px solid #d6d3d1;background:#e7e5e4;color:#1c1917;cursor:pointer;transition:background .1s,border-color .1s}
.dark .tm-trash-btn{border-color:#57534e;background:#57534e;color:#f5f5f4}
.tm-trash-btn:hover{background:#d6d3d1}
.dark .tm-trash-btn:hover{background:#44403c}
.tm-trash-btn--primary{background:#0d9488;border-color:#0d9488;color:#fff}
.tm-trash-btn--primary:hover{background:#0f766e;border-color:#0f766e}
.dark .tm-trash-btn--primary{background:#14b8a6;border-color:#14b8a6;color:#042f2e}
.tm-trash-btn--danger{background:#be123c;border-color:#be123c;color:#fff}
.tm-trash-btn--danger:hover{background:#9f1239}
.tm-trash-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:2.375rem;height:2.375rem;padding:0;border:1px solid transparent;background:transparent;color:#57534e;cursor:pointer;border-radius:2px;flex-shrink:0}
.tm-trash-icon-btn:hover{background:#e7e5e4;border-color:#d6d3d1}
.dark .tm-trash-icon-btn{color:#a8a29e}
.dark .tm-trash-icon-btn:hover{background:#44403c;border-color:#57534e}
.tm-trash-loading,.tm-trash-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;padding:3.5rem 1.5rem;text-align:center;color:#78716c}
.tm-trash-spinner{width:2rem;height:2rem;border:2px solid #d6d3d1;border-top-color:#0d9488;border-radius:50%;animation:tm-trash-spin .7s linear infinite}
@keyframes tm-trash-spin{to{transform:rotate(360deg)}}
.tm-trash-menu{position:absolute;top:calc(100% + .25rem);right:0;z-index:20;min-width:11rem;padding:.35rem 0;background:#fff;border:1px solid #d6d3d1;box-shadow:0 8px 24px rgba(0,0,0,.12);text-align:left}
.dark .tm-trash-menu{background:#44403c;border-color:#57534e}
.tm-trash-menu button{display:block;width:100%;padding:.5rem 1rem;border:0;background:transparent;color:#1c1917;font-size:.8125rem;line-height:1.35;text-align:left;cursor:pointer}
.dark .tm-trash-menu button{color:#fafaf9}
.tm-trash-menu button:hover{background:#f5f5f4}
.dark .tm-trash-menu button:hover{background:#57534e}
.tm-trash-menu__danger{color:#be123c!important}
.tm-trash-table-wrap{padding:0 1.25rem}
.tm-trash-table{width:100%;min-width:36rem;border-collapse:separate;border-spacing:0}
.tm-trash-table thead th{position:sticky;top:0;z-index:1;padding:.7rem 1rem;font-size:.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#78716c;background:#f5f5f4;border-bottom:1px solid #e7e5e4;white-space:nowrap}
.tm-trash-table thead th:first-child{padding-left:0}
.tm-trash-table thead th.tm-trash-col--actions{width:3.25rem;padding-right:0;text-align:center}
.dark .tm-trash-table thead th{background:#1c1917;color:#a8a29e;border-color:#44403c}
.tm-trash-row td{padding:.8rem 1rem;font-size:.875rem;line-height:1.35;border-bottom:1px solid #f5f5f4;vertical-align:top}
.tm-trash-row td:first-child{padding-left:0}
.tm-trash-row td.tm-trash-col--meta{color:#78716c;white-space:nowrap}
.tm-trash-row td.tm-trash-col--actions{width:3.25rem;padding-right:0;text-align:center;vertical-align:middle}
.dark .tm-trash-row td{border-color:#292524}
.dark .tm-trash-row td.tm-trash-col--meta{color:#a8a29e}
.tm-trash-row:hover td{background:#fafaf9}
.dark .tm-trash-row:hover td{background:#1c1917}
.tm-trash-name-cell{display:flex;align-items:flex-start;gap:.75rem;min-width:0;max-width:100%}
.tm-trash-name-cell__body{display:flex;flex-direction:column;gap:.2rem;min-width:0}
.tm-trash-name-cell__label{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tm-trash-name-cell__type{font-size:.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#a8a29e}
.tm-trash-name-cell__url{font-size:.75rem;color:#78716c;word-break:break-all;line-height:1.3}
.dark .tm-trash-name-cell__url{color:#a8a29e}
.tm-trash-list-icon{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;flex-shrink:0;border-radius:4px;color:#78716c;background:#e7e5e4}
.tm-trash-list-icon--page{background:#fee2e2;color:#dc2626}
.tm-trash-list-icon--folder{background:#ffedd5;color:#d97706}
.tm-trash-list-icon--file{background:#e7e5e4;color:#44403c}
.tm-trash-list-icon--image{background:#dbeafe;color:#2563eb}
`;
document.head.appendChild(trashStyle);

if (typeof previewModalTemplate !== 'undefined' && versionsSystemTemplate.indexOf('<!--TYPEMILL_PREVIEW-->') !== -1) {
    var versionsSystemAppTemplate = versionsSystemTemplate.replace('<!--TYPEMILL_PREVIEW-->', previewModalTemplate);
} else {
    var versionsSystemAppTemplate = versionsSystemTemplate;
}

const trashPreviewMixins = typeof TypemillPreviewMixin !== 'undefined' ? [TypemillPreviewMixin] : [];

const TRASH_ICON_PATHS = {
    page: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 2l5 5h-5V4zm-2 10H8v-2h3v2zm0-4H8V8h3v2z',
    folder: 'M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 2l5 5h-5V4z',
    image: 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z',
};

const app = Vue.createApp({
    template: versionsSystemAppTemplate,
    mixins: trashPreviewMixins,

    data() {
        return {
            loading: true,
            trash: [],
            searchQuery: '',
            message: '',
            messageClass: '',
            previewAvailable: typeof TypemillPreviewMixin !== 'undefined',
            restoreTarget: null,
            restoreConflict: false,
            deleteTarget: null,
            confirmEmpty: false,
            openMenuKey: null,
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
        },

        filteredEntryCount() {
            return this.filteredTrash.length;
        },

        showTrashEmpty() {
            return !this.loading && this.trash.length === 0;
        },

        showNoResults() {
            return !this.loading
                && this.trash.length > 0
                && !!this.searchQuery.trim()
                && this.filteredTrash.length === 0;
        },
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

        entryMenuKey(entry) {
            return (entry.record_type || 'page') + ':' + entry.record_id + ':' + entry.version_id;
        },

        entryIconPath(entry) {
            var kind = this.entryKind(entry);
            return TRASH_ICON_PATHS[kind] || TRASH_ICON_PATHS.file;
        },

        entryIconClass(entry) {
            var kind = this.entryKind(entry);
            return 'tm-trash-list-icon--' + kind;
        },

        entryKind(entry) {
            if (entry.entry_kind) {
                return entry.entry_kind;
            }

            if (entry.record_type === 'asset') {
                return (entry.asset_type || '') === 'image' ? 'image' : 'file';
            }

            return (entry.item_type || '') === 'folder' ? 'folder' : 'page';
        },

        entryTypeLabel(entry) {
            var kind = this.entryKind(entry);
            var key = 'versions.type_' + kind;
            var translated = this.$filters.translate(key);
            if (translated !== key) {
                return translated;
            }

            return kind.toUpperCase();
        },

        toggleActionMenu(key) {
            this.openMenuKey = this.openMenuKey === key ? null : key;
        },

        closeActionMenu() {
            this.openMenuKey = null;
        },

        runMenuAction(action, entry) {
            this.closeActionMenu();

            if (action === 'preview') {
                if (typeof this.openTrashPreview === 'function') {
                    this.openTrashPreview(entry);
                }
                return;
            }
            if (action === 'download') {
                this.downloadEntry(entry);
                return;
            }
            if (action === 'restore') {
                this.prepareRestore(entry);
                return;
            }
            if (action === 'delete') {
                this.prepareDelete(entry);
            }
        },

        prepareRestore(entry) {
            this.restoreTarget = entry;
            this.restoreConflict = false;
        },

        exportHistory() {
            var self = this;

            tmaxios.get('/api/v1/versions/export', { responseType: 'blob' })
                .then(function (response) {
                    self.triggerBlobDownload(response, 'versions-export.zip');
                })
                .catch(function (error) {
                    self.showMessage(handleErrorMessage(error) || 'versions.msg_export_error', 'error');
                });
        },

        triggerBlobDownload(response, fallbackName) {
            var blobUrl = URL.createObjectURL(response.data);
            var link = document.createElement('a');
            link.href = blobUrl;
            link.download = this.downloadFilename(response.headers['content-disposition'], { title: fallbackName });
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () {
                URL.revokeObjectURL(blobUrl);
            }, 1000);
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
                self.triggerBlobDownload(response, entry.title || 'trash-entry');
            })
            .catch(function (error) {
                self.showMessage(handleErrorMessage(error) || 'versions.msg_download_error', 'error');
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
        },
    }
});
