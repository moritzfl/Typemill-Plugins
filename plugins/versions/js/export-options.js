const TypemillExportOptionsMixin = {
    data() {
        return {
            fullExportDialogOpen: false,
            fullExportOptionsLoading: false,
            fullExportInProgress: false,
            fullExportMediaFolders: [],
            fullExportMediaFolderSizes: {},
            fullExportSelectedMedia: [],
            fullExportIncludeRecycleBin: true,
        };
    },

    methods: {
        openFullExportDialog() {
            var self = this;
            self.fullExportDialogOpen = true;
            self.fullExportOptionsLoading = true;

            tmaxios.get('/api/v1/versions/export/options')
                .then(function (response) {
                    var payload = response.data || {};
                    self.fullExportMediaFolders = payload.media_folders || [];
                    self.fullExportMediaFolderSizes = payload.media_folder_sizes || {};
                    var defaults = payload.defaults || {};
                    self.fullExportSelectedMedia = Array.isArray(defaults.media_folders)
                        ? defaults.media_folders.slice()
                        : self.fullExportMediaFolders.slice();
                    self.fullExportIncludeRecycleBin = defaults.include_recycle_bin !== false;
                })
                .catch(function () {
                    self.showExportOptionsError('versions.msg_export_options_error');
                })
                .finally(function () {
                    self.fullExportOptionsLoading = false;
                });
        },

        closeFullExportDialog() {
            if (this.fullExportInProgress) {
                return;
            }

            this.fullExportDialogOpen = false;
        },

        isExportMediaSelected(folder) {
            return this.fullExportSelectedMedia.indexOf(folder) !== -1;
        },

        toggleExportMediaFolder(folder) {
            var index = this.fullExportSelectedMedia.indexOf(folder);
            if (index === -1) {
                this.fullExportSelectedMedia.push(folder);
            } else {
                this.fullExportSelectedMedia.splice(index, 1);
            }
        },

        selectAllExportMedia() {
            this.fullExportSelectedMedia = this.fullExportMediaFolders.slice();
        },

        clearExportMediaSelection() {
            this.fullExportSelectedMedia = [];
        },

        exportMediaFolderLabel(folder) {
            var key = 'versions.export_media_' + folder;
            var translated = this.$filters.translate(key);
            return translated !== key ? translated : folder;
        },

        exportMediaFolderPath(folder) {
            return 'media/' + folder + '/';
        },

        exportMediaFolderSize(folder) {
            var bytes = this.fullExportMediaFolderSizes[folder];
            if (bytes === undefined || bytes === null) {
                return '';
            }

            return this.formatExportFolderSize(bytes);
        },

        formatExportFolderSize(bytes) {
            if (!bytes) {
                return '0 B';
            }

            if (bytes < 1024) {
                return bytes + ' B';
            }

            if (bytes < 1024 * 1024) {
                return (bytes / 1024).toFixed(1) + ' KB';
            }

            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        runFullExportDownload() {
            var self = this;
            self.fullExportInProgress = true;

            tmaxios.get('/api/v1/versions/export', {
                params: {
                    media: self.fullExportSelectedMedia.join(','),
                    include_recycle_bin: self.fullExportIncludeRecycleBin ? '1' : '0',
                },
                responseType: 'blob',
            })
                .then(function (response) {
                    self.triggerFullExportDownload(response, 'versions-export.zip');
                    self.fullExportInProgress = false;
                    self.fullExportDialogOpen = false;
                    if (typeof self.exportDialogOpen !== 'undefined') {
                        self.exportDialogOpen = false;
                    }
                })
                .catch(function (error) {
                    self.fullExportInProgress = false;
                    self.showExportOptionsError(handleErrorMessage(error) || 'versions.msg_export_error');
                });
        },

        triggerFullExportDownload(response, fallbackName) {
            if (typeof this.triggerBlobDownload === 'function') {
                this.triggerBlobDownload(response, fallbackName);
                return;
            }

            if (typeof this.triggerExportDownload === 'function') {
                this.triggerExportDownload(response, fallbackName);
                return;
            }

            var blobUrl = URL.createObjectURL(response.data);
            var link = document.createElement('a');
            link.href = blobUrl;
            var disposition = response.headers['content-disposition'] || '';
            var match = disposition.match(/filename=\"?([^\";]+)\"?/i);
            link.download = (match && match[1]) ? match[1] : fallbackName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () {
                URL.revokeObjectURL(blobUrl);
            }, 1000);
        },

        showExportOptionsError(messageKey) {
            if (typeof this.showMessage === 'function') {
                this.showMessage(messageKey, 'error');
                return;
            }

            if (typeof this.error !== 'undefined') {
                this.error = messageKey;
            }
        },
    },
};

(function () {
    var style = document.createElement('style');
    style.textContent = [
        '.tm-export-modal-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.25rem;padding:0 .9rem;font-size:.8125rem;line-height:1;border:1px solid #d6d3d1;background:#e7e5e4;color:#1c1917;cursor:pointer;transition:background .1s,border-color .1s}',
        '.dark .tm-export-modal-btn{border-color:#57534e;background:#57534e;color:#f5f5f4}',
        '.tm-export-modal-btn:hover{background:#d6d3d1}',
        '.dark .tm-export-modal-btn:hover{background:#44403c}',
        '.tm-export-modal-btn--primary{background:#0d9488;border-color:#0d9488;color:#fff}',
        '.tm-export-modal-btn--primary:hover{background:#0f766e;border-color:#0f766e}',
        '.dark .tm-export-modal-btn--primary{background:#14b8a6;border-color:#14b8a6;color:#042f2e}',
        '.tm-export-modal-btn:disabled{opacity:.55;cursor:not-allowed}',
        '.tm-export-media-links{display:inline-flex;align-items:center;gap:1.25rem;flex-shrink:0}',
        '.tm-export-link-btn{border:0;background:transparent;padding:0;font-size:.8125rem;line-height:1.25;color:#0f766e;cursor:pointer;text-decoration:underline;text-underline-offset:2px;white-space:nowrap}',
        '.dark .tm-export-link-btn{color:#5eead4}',
        '.tm-export-link-btn:hover{color:#115e59}',
        '.dark .tm-export-link-btn:hover{color:#99f6e4}',
        '.tm-export-checklist{display:flex;flex-direction:column;gap:.15rem}',
        '.tm-export-check{display:flex;align-items:flex-start;gap:.55rem;padding:.4rem 0;font-size:.875rem;line-height:1.35;color:#44403c;cursor:pointer}',
        '.dark .tm-export-check{color:#e7e5e4}',
        '.tm-export-check__text{display:flex;flex-direction:column;gap:.15rem;min-width:0}',
        '.tm-export-check__label{font-size:.875rem;line-height:1.35}',
        '.tm-export-check__size{margin-left:.45rem;font-size:.8125rem;font-weight:500;color:#78716c;white-space:nowrap}',
        '.dark .tm-export-check__size{color:#a8a29e}',
        '.tm-export-check__path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;line-height:1.3;color:#78716c}',
        '.dark .tm-export-check__path{color:#a8a29e}',
        '.tm-export-check input[type=checkbox]{width:1rem;height:1rem;margin:.15rem 0 0;flex-shrink:0;accent-color:#0d9488;cursor:pointer}',
        '.dark .tm-export-check input[type=checkbox]{accent-color:#14b8a6}',
    ].join('');
    document.head.appendChild(style);
})();
