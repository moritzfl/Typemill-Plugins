const filesStyle = document.createElement('style');
filesStyle.textContent = `
.tm-toast-enter-active,.tm-toast-leave-active{transition:opacity .25s ease,transform .25s ease}
.tm-toast-enter-from,.tm-toast-leave-to{opacity:0;transform:translateX(-50%) translateY(0.75rem)}
.tm-toast-enter-to,.tm-toast-leave-from{opacity:1;transform:translateX(-50%) translateY(0)}
.tm-files{margin-bottom:1.5rem}
.tm-files__page-header{margin-bottom:1.25rem}
.tm-files__page-header h1{margin-bottom:.35rem}
.tm-files-panel--drag{outline:2px solid #14b8a6;outline-offset:2px}
.tm-files-inset{padding-left:1.25rem;padding-right:1.25rem}
.tm-files-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;min-height:3.25rem;padding:.75rem 1.25rem;border-bottom:1px solid #e7e5e4;background:#fafaf9}
.dark .tm-files-toolbar{border-color:#44403c;background:#1c1917}
.tm-files-toolbar__actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;flex-shrink:0}
.tm-files-subtoolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem 1.5rem;padding:.75rem 1.25rem;border-bottom:1px solid #f5f5f4;background:#fff}
.dark .tm-files-subtoolbar{border-color:#292524;background:#292524}
.tm-files-search{position:relative;flex:1 1 14rem;min-width:12rem;max-width:26rem}
.tm-files-search input{width:100%;height:2.375rem;padding:0 .875rem 0 2.25rem;font-size:.875rem;line-height:1.25;border:1px solid #d6d3d1;background:#f5f5f4;color:#1c1917}
.tm-files-search input:focus{outline:none;border-color:#14b8a6}
.dark .tm-files-search input{border-color:#57534e;background:#44403c;color:#fafaf9}
.dark .tm-files-search input:focus{border-color:#2dd4bf}
.tm-files-search__icon{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:#a8a29e;pointer-events:none}
.tm-files-meta{font-size:.8125rem;color:#78716c;white-space:nowrap;flex-shrink:0;padding-left:.5rem}
.tm-files-banner{margin:1rem 1.25rem 0;padding:.65rem 1rem;font-size:.875rem;line-height:1.4}
.tm-files-transfers{margin:1rem 1.25rem 0;border:1px solid #e7e5e4;background:#fafaf9}
.dark .tm-files-transfers{border-color:#44403c;background:rgba(28,25,23,.55)}
.tm-files-transfers__head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem 1rem;border-bottom:1px solid #e7e5e4}
.dark .tm-files-transfers__head{border-color:#44403c}
.tm-files-transfers__list{margin:0;padding:0;list-style:none}
.tm-files-transfers__item{padding:.55rem 1rem;border-bottom:1px solid #f5f5f4}
.tm-files-transfers__item:last-child{border-bottom:0}
.dark .tm-files-transfers__item{border-color:#292524}
.tm-files-transfers__row{display:flex;align-items:center;gap:.75rem;font-size:.875rem;line-height:1.35}
.tm-files-content{padding:.5rem 0 1rem}
.tm-files-panel{overflow:visible}
.tm-files-drop-overlay{position:absolute;inset:0;z-index:30;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.35rem;text-align:center;padding:2rem 1.5rem;background:rgba(204,251,241,.92);pointer-events:none}
.dark .tm-files-drop-overlay{background:rgba(19,78,74,.92)}
.tm-files-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.25rem;padding:0 .9rem;font-size:.8125rem;line-height:1;border:1px solid #d6d3d1;background:#e7e5e4;color:#1c1917;cursor:pointer;transition:background .1s,border-color .1s}
.dark .tm-files-btn{border-color:#57534e;background:#57534e;color:#f5f5f4}
.tm-files-btn:hover{background:#d6d3d1}
.dark .tm-files-btn:hover{background:#44403c}
.tm-files-btn--primary{background:#0d9488;border-color:#0d9488;color:#fff}
.tm-files-btn--primary:hover{background:#0f766e;border-color:#0f766e}
.dark .tm-files-btn--primary{background:#14b8a6;border-color:#14b8a6;color:#042f2e}
.tm-files-btn--danger{background:#be123c;border-color:#be123c;color:#fff}
.tm-files-btn--danger:hover{background:#9f1239}
.tm-files-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:2.375rem;height:2.375rem;padding:0;border:1px solid transparent;background:transparent;color:#57534e;cursor:pointer;border-radius:2px;flex-shrink:0}
.tm-files-icon-btn:hover{background:#e7e5e4;border-color:#d6d3d1}
.dark .tm-files-icon-btn{color:#a8a29e}
.dark .tm-files-icon-btn:hover{background:#44403c;border-color:#57534e}
.tm-files-breadcrumbs{display:flex;flex-wrap:wrap;align-items:center;gap:.15rem .35rem;min-width:0;flex:1 1 12rem;font-size:.875rem;line-height:1.35}
.tm-files-crumb{padding:.35rem .5rem;border:0;background:transparent;color:#57534e;cursor:pointer;border-radius:2px}
.tm-files-crumb:hover{color:#0d9488;background:#f5f5f4}
.dark .tm-files-crumb{color:#a8a29e}
.dark .tm-files-crumb:hover{color:#2dd4bf;background:#292524}
.tm-files-crumb--current{font-weight:600;color:#1c1917;cursor:default;padding:.35rem .5rem}
.dark .tm-files-crumb--current{color:#fafaf9}
.tm-files-crumb-sep{width:1rem;height:1rem;color:#a8a29e;flex-shrink:0}
.tm-files-loading,.tm-files-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;padding:3.5rem 1.5rem;text-align:center;color:#78716c}
.tm-files-spinner{width:2rem;height:2rem;border:2px solid #d6d3d1;border-top-color:#0d9488;border-radius:50%;animation:tm-files-spin .7s linear infinite}
@keyframes tm-files-spin{to{transform:rotate(360deg)}}
.tm-files-menu{position:absolute;top:calc(100% + .25rem);right:0;z-index:20;min-width:11rem;padding:.35rem 0;background:#fff;border:1px solid #d6d3d1;box-shadow:0 8px 24px rgba(0,0,0,.12);text-align:left}
.dark .tm-files-menu{background:#44403c;border-color:#57534e}
.tm-files-menu button{display:block;width:100%;padding:.5rem 1rem;border:0;background:transparent;color:#1c1917;font-size:.8125rem;line-height:1.35;text-align:left;cursor:pointer}
.dark .tm-files-menu button{color:#fafaf9}
.tm-files-menu button:hover{background:#f5f5f4}
.dark .tm-files-menu button:hover{background:#57534e}
.tm-files-menu__danger{color:#be123c!important}
.tm-files-table-wrap{padding:0 1.25rem}
.tm-files-table{width:100%;min-width:40rem;border-collapse:separate;border-spacing:0}
.tm-files-table thead th{position:sticky;top:0;z-index:1;padding:.7rem 1rem;font-size:.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#78716c;background:#f5f5f4;border-bottom:1px solid #e7e5e4;white-space:nowrap}
.tm-files-table thead th:first-child{padding-left:0}
.tm-files-table thead th.tm-files-col--num{text-align:right}
.tm-files-table thead th.tm-files-col--actions{width:3.25rem;padding-right:0;text-align:center}
.dark .tm-files-table thead th{background:#1c1917;color:#a8a29e;border-color:#44403c}
.tm-files-row td{padding:.8rem 1rem;font-size:.875rem;line-height:1.35;border-bottom:1px solid #f5f5f4;vertical-align:middle}
.tm-files-row td:first-child{padding-left:0}
.tm-files-row td.tm-files-col--num{text-align:right;color:#78716c;white-space:nowrap}
.tm-files-row td.tm-files-col--actions{width:3.25rem;padding-right:0;text-align:center}
.dark .tm-files-row td{border-color:#292524}
.dark .tm-files-row td.tm-files-col--num{color:#a8a29e}
.tm-files-row--clickable{cursor:pointer}
.tm-files-row--clickable:hover td{background:#fafaf9}
.dark .tm-files-row--clickable:hover td{background:#1c1917}
.tm-files-name-cell{display:inline-flex;align-items:center;gap:.75rem;min-width:0;max-width:100%}
.tm-files-name-cell__label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tm-files-list-icon{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;flex-shrink:0;border-radius:4px;color:#78716c;background:#e7e5e4}
.tm-files-list-icon--folder{background:#ffedd5;color:#d97706}
.tm-files-list-icon--up{background:transparent;color:inherit}
.tm-files-list-icon--image{background:#dbeafe;color:#2563eb}
.tm-files-list-icon--video{background:#ede9fe;color:#7c3aed}
.tm-files-list-icon--audio{background:#fce7f3;color:#db2777}
.tm-files-list-icon--archive{background:#ffedd5;color:#b45309}
.tm-files-list-icon--document{background:#fee2e2;color:#dc2626}
.tm-files-list-icon--code{background:#d1fae5;color:#059669}
.tm-files-list-icon--ext{background:#e7e5e4;color:#44403c;font-size:.625rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase}
.tm-files-toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);z-index:9999;display:flex;align-items:center;gap:.65rem;max-width:36rem;padding:.75rem 1rem;background:#1c1917;color:#fff;font-size:.8rem;font-family:ui-monospace,monospace;border-radius:.5rem;box-shadow:0 10px 25px rgba(0,0,0,.3);word-break:break-all;pointer-events:none}
`;
document.head.appendChild(filesStyle);

if (typeof previewModalTemplate !== 'undefined' && filesTemplate.indexOf('<!--TYPEMILL_PREVIEW-->') !== -1) {
    var filesAppTemplate = filesTemplate.replace('<!--TYPEMILL_PREVIEW-->', previewModalTemplate);
} else {
    var filesAppTemplate = filesTemplate;
}

const filesPreviewMixins = typeof TypemillPreviewMixin !== 'undefined' ? [TypemillPreviewMixin] : [];

const FILE_ICON_PATHS = {
    generic: 'M14,2H6a2,2 0 0,0-2,2v16a2,2 0 0,0 2,2h12a2,2 0 0,0 2-2V8l-6-6zm-1 2l5 5h-5V4z',
    image: 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z',
    video: 'M17 10.5V7a1 1 0 0,0-1-1H4a1 1 0 0,0-1 1v10a1 1 0 0,0 1 1h12a1 1 0 0,0 1-1v-3.5l4 4v-11l-4 4z',
    audio: 'M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z',
    archive: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z',
    document: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 2l5 5h-5V4zm-2 10H8v-2h3v2zm0-4H8V8h3v2z',
    code: 'M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z',
};

const FILE_KIND_MAP = {
    image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'],
    video: ['mp4', 'webm', 'mov', 'avi', 'mkv'],
    audio: ['mp3', 'wav', 'ogg', 'flac', 'm4a'],
    archive: ['zip', 'gz', 'tar', 'rar', '7z', 'bz2'],
    document: ['pdf', 'doc', 'docx', 'txt', 'md', 'csv', 'rtf', 'odt'],
    code: ['js', 'ts', 'jsx', 'tsx', 'php', 'html', 'htm', 'css', 'scss', 'json', 'xml', 'yaml', 'yml', 'py', 'rb', 'go', 'java', 'c', 'cpp', 'h'],
};

const app = Vue.createApp({
    template: filesAppTemplate,
    mixins: filesPreviewMixins,

            data() {
                return {
                    currentPath:  '',
                    parentPath:   null,
                    breadcrumbs:  [],
                    folders:      [],
                    files:        [],
                    loading:      true,
                    previewAvailable: typeof TypemillPreviewMixin !== 'undefined',
                    isDragging:   false,
                    dragDepth:    0,
                    uploadQueue:  [],
                    message:      '',
                    messageClass: '',
                    searchQuery:  '',
                    toast:        '',
                    deleteTarget: null,
                    errorTarget:  null,
                    showNewFolder: false,
                    newFolderName: '',
                    openMenuKey:  null,
                    baseUrl:      data.urlinfo.baseurl || '',
                };
            },

            computed: {
                filteredFolders() {
                    if (!this.searchQuery.trim()) return this.folders;
                    const q = this.searchQuery.toLowerCase();
                    return this.folders.filter(f => f.name.toLowerCase().includes(q));
                },
                filteredFiles() {
                    if (!this.searchQuery.trim()) return this.files;
                    const q = this.searchQuery.toLowerCase();
                    return this.files.filter(f => f.name.toLowerCase().includes(q));
                },
                filteredEntryCount() {
                    return this.filteredFolders.length + this.filteredFiles.length;
                },
                showEmptyState() {
                    return !this.loading && !this.currentPath && this.filteredEntryCount === 0 && !this.searchQuery.trim();
                },
                showFolderEmpty() {
                    return !this.loading && !!this.currentPath && this.filteredEntryCount === 0 && !this.searchQuery.trim();
                },
                showNoResults() {
                    return !this.loading && this.filteredEntryCount === 0 && !!this.searchQuery.trim();
                },
            },

            watch: {
                showNewFolder(open) {
                    if (!open) {
                        return;
                    }
                    var self = this;
                    this.$nextTick(function() {
                        if (self.$refs.newFolderInput) {
                            self.$refs.newFolderInput.focus();
                        }
                    });
                },
            },

            mounted() {
                this.loadBrowse();
                this._onWindowDragEnd = this.resetDragState.bind(this);
                window.addEventListener('dragend', this._onWindowDragEnd);
                window.addEventListener('drop', this._onWindowDragEnd);
                this._onDocumentClick = this.closeActionMenu.bind(this);
                document.addEventListener('click', this._onDocumentClick);
            },

            unmounted() {
                if (this._onWindowDragEnd) {
                    window.removeEventListener('dragend', this._onWindowDragEnd);
                    window.removeEventListener('drop', this._onWindowDragEnd);
                }
                if (this._onDocumentClick) {
                    document.removeEventListener('click', this._onDocumentClick);
                }
            },

            methods: {
                loadBrowse() {
                    this.loading = true;
                    this.closeActionMenu();
                    var self = this;
                    tmaxios.get('/api/v1/files/browse', { params: { path: self.currentPath } })
                        .then(function(response) {
                            var data = response.data || {};
                            self.currentPath = data.path || '';
                            self.parentPath = data.parent;
                            self.breadcrumbs = data.breadcrumbs || [];
                            self.folders = data.folders || [];
                            self.files = data.files || [];
                            self.loading = false;
                        })
                        .catch(function() {
                            self.showMessage('files.msg_load_error', 'error');
                            self.loading = false;
                        });
                },

                navigateTo(path) {
                    this.currentPath = path || '';
                    this.searchQuery = '';
                    this.loadBrowse();
                },

                goUp() {
                    if (this.parentPath === null) {
                        return;
                    }
                    this.navigateTo(this.parentPath);
                },

                crumbLabel(crumb) {
                    if (!crumb.path && crumb.name === 'files') {
                        return this.$filters.translate('files.breadcrumb_root');
                    }
                    return crumb.name;
                },

                fileExtension(name) {
                    var parts = (name || '').split('.');
                    if (parts.length < 2) {
                        return 'file';
                    }
                    var ext = parts.pop().toLowerCase();
                    return ext.length > 4 ? ext.slice(0, 4) : ext;
                },

                fileIconKind(name) {
                    var ext = this.fileExtension(name);
                    if (ext === 'file') {
                        return 'generic';
                    }
                    var kind;
                    for (kind in FILE_KIND_MAP) {
                        if (FILE_KIND_MAP[kind].indexOf(ext) !== -1) {
                            return kind;
                        }
                    }
                    return 'ext';
                },

                fileIconPath(name) {
                    var kind = this.fileIconKind(name);
                    if (kind === 'ext') {
                        return FILE_ICON_PATHS.generic;
                    }
                    return FILE_ICON_PATHS[kind] || FILE_ICON_PATHS.generic;
                },

                toggleActionMenu(key) {
                    this.openMenuKey = this.openMenuKey === key ? null : key;
                },

                closeActionMenu() {
                    this.openMenuKey = null;
                },

                canPreviewFile(file) {
                    return this.previewAvailable && !!(file && file.previewable);
                },

                openFileEntry(file) {
                    if (!this.canPreviewFile(file)) {
                        return;
                    }

                    if (typeof this.openFilePreview === 'function') {
                        this.openFilePreview(file);
                    }
                },

                runMenuAction(action, entry, type) {
                    this.closeActionMenu();
                    if (type === 'folder') {
                        if (action === 'open') {
                            this.navigateTo(entry.path);
                        } else if (action === 'zip') {
                            this.downloadFolderZip(entry);
                        } else if (action === 'copyPath') {
                            this.copyLink(entry, 'internal');
                        } else if (action === 'copyUrl') {
                            this.copyLink(entry, 'external');
                        } else if (action === 'delete') {
                            this.confirmDelete({ type: 'folder', name: entry.name, path: entry.path });
                        }
                        return;
                    }
                    if (action === 'download') {
                        this.downloadFile(entry);
                    } else if (action === 'preview') {
                        if (typeof this.openFilePreview === 'function') {
                            this.openFilePreview(entry);
                        }
                    } else if (action === 'copyPath') {
                        this.copyLink(entry, 'internal');
                    } else if (action === 'copyUrl') {
                        this.copyLink(entry, 'external');
                    } else if (action === 'delete') {
                        this.confirmDelete({ type: 'file', name: entry.name, path: entry.path });
                    }
                },

                submitNewFolder() {
                    var name = (this.newFolderName || '').trim();
                    if (!name) {
                        this.showMessage('files.msg_folder_invalid', 'error');
                        return;
                    }

                    var self = this;
                    tmaxios.post('/api/v1/files/folder', {
                        path: this.currentPath,
                        name: name,
                    })
                    .then(function() {
                        self.showNewFolder = false;
                        self.newFolderName = '';
                        self.showMessage('files.msg_folder_created', 'success');
                        self.loadBrowse();
                    })
                    .catch(function(error) {
                        var msg = error.response?.data?.message || 'files.msg_folder_create_error';
                        self.showMessage(msg, 'error');
                    });
                },

                openFilePicker() {
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.click();
                    }
                },

                handleFileSelect(event) {
                    this.uploadFiles(Array.from(event.target.files));
                    event.target.value = '';
                },

                resetDragState() {
                    this.dragDepth = 0;
                    this.isDragging = false;
                },

                isExternalFileDrag(event) {
                    var dt = event.dataTransfer;
                    if (!dt || !dt.types) {
                        return false;
                    }
                    var types = Array.from(dt.types);
                    return types.indexOf('Files') !== -1;
                },

                onDragEnter(event) {
                    if (!this.isExternalFileDrag(event)) {
                        return;
                    }
                    event.preventDefault();
                    this.dragDepth += 1;
                    this.isDragging = true;
                },

                onDragLeave(event) {
                    if (!this.isExternalFileDrag(event)) {
                        return;
                    }
                    event.preventDefault();
                    this.dragDepth -= 1;
                    if (this.dragDepth <= 0) {
                        this.dragDepth = 0;
                        this.isDragging = false;
                    }
                },

                onDragOver(event) {
                    if (!this.isExternalFileDrag(event)) {
                        return;
                    }
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'copy';
                },

                onDrop(event) {
                    event.preventDefault();
                    this.dragDepth = 0;
                    this.isDragging = false;
                    if (!this.isExternalFileDrag(event)) {
                        return;
                    }
                    var dropped = Array.from(event.dataTransfer.files);
                    if (dropped.length > 0) {
                        this.uploadFiles(dropped);
                    }
                },

                parseIniSize(value) {
                    if (value === null || value === undefined || value === '') return 0;
                    var str = String(value).trim();
                    var num = parseFloat(str);
                    if (isNaN(num)) return 0;
                    var unit = str.slice(-1).toUpperCase();
                    var multipliers = { 'K': 1024, 'M': 1024*1024, 'G': 1024*1024*1024, 'T': 1024*1024*1024*1024 };
                    if (multipliers[unit]) {
                        return Math.floor(num * multipliers[unit]);
                    }
                    return Math.floor(num * 1024 * 1024);
                },

                getUploadError(file) {
                    if (!file.size || file.size === 0) {
                        return { key: 'files.msg_file_empty', limit: '' };
                    }

                    const config = (typeof filesConfig !== 'undefined') ? filesConfig : {};
                    const typemillMax = config.maxFileUploads ? this.parseIniSize(config.maxFileUploads) : null;

                    if (typemillMax && file.size > typemillMax) {
                        return { key: 'files.msg_too_large', limit: this.formatSize(typemillMax) };
                    }
                    return null;
                },

                shouldChunkUpload(file) {
                    const config = (typeof filesConfig !== 'undefined') ? filesConfig : {};
                    const postMax = this.parseIniSize(config.postMaxSize);
                    if (postMax && (file.size * 1.37 + 100) > postMax) {
                        return true;
                    }
                    return false;
                },

                uploadFiles(fileList) {
                    const queue = fileList.map(f => {
                        const err = this.getUploadError(f);
                        return {
                            name: f.name,
                            file: f,
                            status: err ? 'error' : 'queued',
                            error: err ? err.key : '',
                            errorLimit: err ? err.limit : '',
                            progress: ''
                        };
                    });
                    this.uploadQueue = queue;

                    const hasUploads = queue.some(function(item) { return item.status !== 'error'; });
                    if (hasUploads) {
                        this.processQueue(0);
                    }
                },

                clearUploadQueue() {
                    var hasErrors = this.uploadQueue.some(function(i) { return i.status === 'error'; });
                    if (hasErrors) {
                        return;
                    }
                    this.uploadQueue = [];
                },

                processQueue(index) {
                    if (index >= this.uploadQueue.length) {
                        var hasSuccess = this.uploadQueue.some(function(i) { return i.status === 'done'; });
                        if (hasSuccess) {
                            this.loadBrowse();
                        }
                        var self = this;
                        setTimeout(function() { self.clearUploadQueue(); }, 4000);
                        return;
                    }

                    var self = this;
                    var item = this.uploadQueue[index];

                    if (item.status === 'error') {
                        self.processQueue(index + 1);
                        return;
                    }

                    item.status = 'uploading';

                    if (self.shouldChunkUpload(item.file)) {
                        self.uploadChunked(item, function(success) {
                            item.status = success ? 'done' : 'error';
                            self.processQueue(index + 1);
                        });
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        tmaxios.post('/api/v1/files/upload', {
                            file: e.target.result,
                            name: item.name,
                            path: self.currentPath,
                        })
                        .then(function() {
                            item.status = 'done';
                            self.processQueue(index + 1);
                        })
                        .catch(function(error) {
                            self.applyUploadError(item, error);
                            self.processQueue(index + 1);
                        });
                    };
                    reader.onerror = function() {
                        item.status = 'error';
                        item.error = 'files.msg_upload_failed';
                        self.processQueue(index + 1);
                    };
                    reader.readAsDataURL(item.file);
                },

                applyUploadError(item, error) {
                    item.status = 'error';
                    var msg = error.response?.data?.message;
                    if (!msg && error.response?.status === 413) {
                        msg = 'files.msg_php_upload_limit';
                    } else if (!msg && (error.response?.status >= 500 || !error.response)) {
                        msg = 'files.msg_php_server_error';
                    } else if (!msg) {
                        msg = 'files.msg_upload_failed';
                    }
                    item.error = msg;
                    var cfg = (typeof filesConfig !== 'undefined') ? filesConfig : {};
                    if (!item.errorLimit) {
                        if (msg === 'files.msg_too_large' && cfg.maxFileUploads) {
                            item.errorLimit = this.formatSize(this.parseIniSize(cfg.maxFileUploads));
                        } else if (msg === 'files.msg_php_upload_limit' && cfg.uploadMaxFilesize) {
                            item.errorLimit = this.formatSize(this.parseIniSize(cfg.uploadMaxFilesize));
                        } else if (msg === 'files.msg_php_post_limit' && cfg.postMaxSize) {
                            item.errorLimit = this.formatSize(this.parseIniSize(cfg.postMaxSize));
                        }
                    }
                },

                uploadChunked(item, callback) {
                    var CHUNK_SIZE = 1024 * 1024;
                    var file = item.file;
                    var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                    var uploadId = 'chunk_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
                    var self = this;

                    function readChunk(idx) {
                        if (idx >= totalChunks) {
                            tmaxios.post('/api/v1/files/finalize', {
                                uploadId: uploadId,
                                filename: item.name,
                                total: totalChunks,
                                path: self.currentPath,
                            })
                            .then(function() {
                                item.progress = '';
                                callback(true);
                            })
                            .catch(function(error) {
                                self.applyUploadError(item, error);
                                item.progress = '';
                                callback(false);
                            });
                            return;
                        }

                        var start = idx * CHUNK_SIZE;
                        var end = Math.min(start + CHUNK_SIZE, file.size);
                        var blob = file.slice(start, end);

                        var reader = new FileReader();
                        reader.onload = function(e) {
                            var dataUrl = e.target.result;
                            var base64 = dataUrl.split(',')[1];
                            item.progress = (idx + 1) + '/' + totalChunks;

                            tmaxios.post('/api/v1/files/chunk', {
                                uploadId: uploadId,
                                index: idx,
                                total: totalChunks,
                                data: base64
                            })
                            .then(function() {
                                readChunk(idx + 1);
                            })
                            .catch(function(error) {
                                self.applyUploadError(item, error);
                                item.progress = '';
                                callback(false);
                            });
                        };
                        reader.onerror = function() {
                            item.error = 'files.msg_upload_failed';
                            item.progress = '';
                            callback(false);
                        };
                        reader.readAsDataURL(blob);
                    }

                    readChunk(0);
                },

                confirmDelete(target) {
                    this.deleteTarget = target;
                },

                deleteEntry(forceDelete) {
                    if (!this.deleteTarget) return;
                    var self   = this;
                    var target = this.deleteTarget;

                    tmaxios.delete('/api/v1/files/entry', {
                        data: {
                            path: target.path,
                            force_delete: forceDelete || false,
                        }
                    })
                    .then(function() {
                        self.deleteTarget = null;
                        self.showMessage('files.msg_deleted', 'success');
                        self.loadBrowse();
                    })
                    .catch(function(error) {
                        if (error.response && error.response.status === 409 && error.response.data && error.response.data.too_large) {
                            if (window.confirm(error.response.data.message)) {
                                self.deleteEntry(true);
                                return;
                            }
                        } else {
                            self.showMessage('files.msg_delete_error', 'error');
                        }
                        self.deleteTarget = null;
                    });
                },

                internalLink(file) {
                    return 'media/files/' + (file.path || file.name);
                },

                externalLink(file) {
                    return this.baseUrl + '/' + this.internalLink(file);
                },

                copyLink(file, type) {
                    var link = type === 'external' ? this.externalLink(file) : this.internalLink(file);
                    var self = this;

                    var done = function() {
                        self.toast = link;
                        clearTimeout(self._toastTimer);
                        self._toastTimer = setTimeout(function() { self.toast = ''; }, 3000);
                    };

                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(link).then(done);
                    } else {
                        var el = document.createElement('textarea');
                        el.value = link;
                        document.body.appendChild(el);
                        el.select();
                        document.execCommand('copy');
                        document.body.removeChild(el);
                        done();
                    }
                },

                downloadFile(file) {
                    var self = this;
                    tmaxios.get('/api/v1/files/download', {
                        params: { path: file.path },
                        responseType: 'blob',
                    })
                    .then(function(response) {
                        self.saveBlob(response.data, file.name);
                    })
                    .catch(function() {
                        self.showMessage('files.msg_download_error', 'error');
                    });
                },

                downloadFolderZip(folder) {
                    var self = this;
                    tmaxios.get('/api/v1/files/download-zip', {
                        params: { path: folder.path },
                        responseType: 'blob',
                    })
                    .then(function(response) {
                        var name = folder.name + '.zip';
                        var disposition = response.headers && response.headers['content-disposition'];
                        if (disposition) {
                            var match = disposition.match(/filename="([^"]+)"/);
                            if (match) {
                                name = match[1];
                            }
                        }
                        self.saveBlob(response.data, name);
                    })
                    .catch(function(error) {
                        var msg = error.response?.data?.message;
                        self.showMessage(msg || 'files.msg_zip_error', 'error');
                    });
                },

                saveBlob(blob, filename) {
                    var url = window.URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);
                },

                formatSize(bytes) {
                    if (!bytes) return '0 B';
                    if (bytes < 1024)        return bytes + ' B';
                    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                },

                formatDate(timestamp) {
                    if (!timestamp) return '';
                    var d = new Date(timestamp * 1000);
                    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                },

                showMessage(text, type) {
                    this.message      = text;
                    this.messageClass = type === 'error' ? 'bg-rose-500' : 'bg-teal-500';
                    var self = this;
                    setTimeout(function() { self.message = ''; }, 4000);
                },
            },
        });
