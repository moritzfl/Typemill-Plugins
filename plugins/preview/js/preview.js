const TypemillPreviewMixin = {
    data() {
        return {
            previewAvailable: true,
            previewDetail: null,
            previewLoading: false,
            previewMode: 'rendered',
            previewMediaUrl: '',
            previewMediaMime: '',
            previewEntry: null,
        };
    },

    methods: {
        closePreview() {
            this.previewLoading = false;
            this.previewDetail = null;
            this.previewEntry = null;
            this.previewMode = 'rendered';
            this.revokePreviewMediaUrl();
        },

        revokePreviewMediaUrl() {
            if (this.previewMediaUrl) {
                URL.revokeObjectURL(this.previewMediaUrl);
                this.previewMediaUrl = '';
            }
            this.previewMediaMime = '';
        },

        previewHasRendered(version) {
            return !!(version && version.rendered_html);
        },

        previewShowsTextToggle() {
            if (!this.previewDetail) {
                return false;
            }

            var kind = this.previewDetail.preview_kind || 'page';
            return kind === 'page' || kind === 'text';
        },

        previewFolderFiles() {
            return (this.previewDetail && this.previewDetail.preview_files) || [];
        },

        formatPreviewFileSize(size) {
            if (size === null || size === undefined || size === '') {
                return '—';
            }

            var bytes = Number(size);
            if (!Number.isFinite(bytes) || bytes < 0) {
                return '—';
            }
            if (bytes < 1024) {
                return bytes + ' B';
            }
            if (bytes < 1024 * 1024) {
                return (bytes / 1024).toFixed(1) + ' KB';
            }

            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        previewMediaKind() {
            return (this.previewDetail && this.previewDetail.preview_kind) || '';
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

        previewSubtitle(version) {
            if (!version) {
                return '';
            }

            return version.preview_subtitle || '';
        },

        isMediaPreviewKind(kind) {
            return kind === 'image' || kind === 'audio' || kind === 'video' || kind === 'pdf';
        },

        loadMediaPreview(requestConfig) {
            var self = this;
            self.previewMode = 'media';

            return tmaxios.get(requestConfig.url, {
                params: requestConfig.params || {},
                responseType: 'blob',
            }).then(function (mediaResponse) {
                self.previewMediaMime = (self.previewDetail && self.previewDetail.preview_mime)
                    || mediaResponse.headers['content-type']
                    || 'application/octet-stream';
                self.previewMediaUrl = URL.createObjectURL(mediaResponse.data);
            });
        },

        openTrashPreview(entry) {
            var self = this;
            self.previewLoading = true;
            self.previewDetail = null;
            self.previewEntry = entry;
            self.revokePreviewMediaUrl();

            tmaxios.get('/api/v1/versions/trash/version', {
                params: {
                    record_id: entry.record_id,
                    record_type: entry.record_type || 'page',
                    version_id: entry.version_id,
                },
            })
            .then(function (response) {
                var version = response.data.version || null;
                if (!version) {
                    self.previewDetail = null;
                    return;
                }

                self.previewDetail = Object.assign({}, version, {
                    title: version.title || entry.title || '',
                    preview_subtitle: self.buildTrashPreviewSubtitle(version),
                });

                var kind = self.previewDetail.preview_kind || 'page';
                if (kind === 'folder') {
                    self.previewMode = 'folder';
                    return;
                }
                if (self.isMediaPreviewKind(kind)) {
                    return self.loadMediaPreview({
                        url: '/api/v1/versions/trash/preview',
                        params: {
                            record_id: entry.record_id,
                            record_type: entry.record_type || 'asset',
                            version_id: entry.version_id,
                        },
                    });
                }

                self.previewMode = self.previewHasRendered(self.previewDetail) ? 'rendered' : 'text';
            })
            .catch(function (error) {
                self.showPreviewError(error);
            })
            .finally(function () {
                self.previewLoading = false;
            });
        },

        buildTrashPreviewSubtitle(version) {
            var parts = [];
            if (version.user_label) {
                parts.push(version.user_label);
            }
            if (version.created_at && typeof this.formatDate === 'function') {
                parts.push(this.formatDate(version.created_at));
            }

            return parts.join(' | ');
        },

        openFilePreview(file) {
            var self = this;
            self.previewLoading = true;
            self.previewDetail = null;
            self.previewEntry = file;
            self.revokePreviewMediaUrl();

            tmaxios.get('/api/v1/preview/file/meta', {
                params: { path: file.path },
            })
            .then(function (response) {
                var preview = response.data.preview || null;
                if (!preview || !preview.previewable) {
                    self.previewDetail = null;
                    self.showPreviewError(null, 'preview.msg_not_available');
                    return;
                }

                self.previewDetail = Object.assign({}, preview, {
                    title: preview.title || file.name || '',
                    preview_subtitle: file.path || '',
                });

                var kind = self.previewDetail.preview_kind || 'text';
                if (self.isMediaPreviewKind(kind)) {
                    return self.loadMediaPreview({
                        url: '/api/v1/preview/file/stream',
                        params: { path: file.path },
                    });
                }

                self.previewMode = self.previewHasRendered(self.previewDetail) ? 'rendered' : 'text';
            })
            .catch(function (error) {
                self.showPreviewError(error);
            })
            .finally(function () {
                self.previewLoading = false;
            });
        },

        showPreviewError(error, fallbackKey) {
            var message = fallbackKey || 'preview.msg_not_available';
            if (typeof handleErrorMessage === 'function' && error) {
                message = handleErrorMessage(error) || message;
            }
            if (typeof this.showMessage === 'function') {
                this.showMessage(message, 'error');
            }
        },
    },
};
