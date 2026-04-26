const toastStyle = document.createElement('style');
toastStyle.textContent = '.tm-toast-enter-active,.tm-toast-leave-active{transition:opacity .25s ease,transform .25s ease}.tm-toast-enter-from,.tm-toast-leave-to{opacity:0;transform:translateX(-50%) translateY(0.75rem)}.tm-toast-enter-to,.tm-toast-leave-from{opacity:1;transform:translateX(-50%) translateY(0)}';
document.head.appendChild(toastStyle);

const app = Vue.createApp({
    template: filesTemplate,

            data() {
                return {
                    files:        [],
                    loading:      true,
                    isDragging:   false,
                    uploadQueue:  [],
                    message:      '',
                    messageClass: '',
                    searchQuery:  '',
                    toast:        '',
                    deleteTarget: null,
                    errorTarget:  null,
                    baseUrl:      data.urlinfo.baseurl || '',
                };
            },

            computed: {
                filteredFiles() {
                    if (!this.searchQuery.trim()) return this.files;
                    const q = this.searchQuery.toLowerCase();
                    return this.files.filter(f => f.name.toLowerCase().includes(q));
                }
            },

            mounted() {
                this.loadFiles();
            },

            methods: {
                loadFiles() {
                    this.loading = true;
                    var self = this;
                    tmaxios.get('/api/v1/files')
                        .then(function(response) {
                            self.files   = response.data.files || [];
                            self.loading = false;
                        })
                        .catch(function() {
                            self.showMessage('files.msg_load_error', 'error');
                            self.loading = false;
                        });
                },

                handleFileSelect(event) {
                    this.uploadFiles(Array.from(event.target.files));
                    event.target.value = '';
                },

                handleDrop(event) {
                    this.isDragging = false;
                    const dropped = Array.from(event.dataTransfer.files);
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
                    // Plain number without unit: treat as MB (Typemill maxfileuploads convention)
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
                    // Use chunked upload if the base64-encoded file would exceed post_max_size.
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
                            this.loadFiles();
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
                            if (success) {
                                item.status = 'done';
                            } else {
                                item.status = 'error';
                            }
                            self.processQueue(index + 1);
                        });
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        tmaxios.post('/api/v1/file', {
                            file: e.target.result,
                            name: item.name,
                            publish: true,
                        })
                        .then(function() {
                            item.status = 'done';
                            self.processQueue(index + 1);
                        })
                        .catch(function(error) {
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
                                    item.errorLimit = self.formatSize(self.parseIniSize(cfg.maxFileUploads));
                                } else if (msg === 'files.msg_php_upload_limit' && cfg.uploadMaxFilesize) {
                                    item.errorLimit = self.formatSize(self.parseIniSize(cfg.uploadMaxFilesize));
                                } else if (msg === 'files.msg_php_post_limit' && cfg.postMaxSize) {
                                    item.errorLimit = self.formatSize(self.parseIniSize(cfg.postMaxSize));
                                }
                            }
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

                uploadChunked(item, callback) {
                    var CHUNK_SIZE = 1024 * 1024; // 1 MB raw chunks
                    var file = item.file;
                    var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                    var uploadId = 'chunk_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
                    var self = this;

                    function readChunk(idx) {
                        if (idx >= totalChunks) {
                            // All chunks sent, finalize
                            tmaxios.post('/api/v1/files/finalize', {
                                uploadId: uploadId,
                                filename: item.name,
                                total: totalChunks
                            })
                            .then(function() {
                                item.progress = '';
                                callback(true);
                            })
                            .catch(function(error) {
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
                                        item.errorLimit = self.formatSize(self.parseIniSize(cfg.maxFileUploads));
                                    } else if (msg === 'files.msg_php_upload_limit' && cfg.uploadMaxFilesize) {
                                        item.errorLimit = self.formatSize(self.parseIniSize(cfg.uploadMaxFilesize));
                                    } else if (msg === 'files.msg_php_post_limit' && cfg.postMaxSize) {
                                        item.errorLimit = self.formatSize(self.parseIniSize(cfg.postMaxSize));
                                    }
                                }
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
                                var msg = error.response?.data?.message;
                                if (!msg && error.response?.status === 413) {
                                    msg = 'files.msg_php_upload_limit';
                                } else if (!msg && (error.response?.status >= 500 || !error.response)) {
                                    msg = 'files.msg_php_server_error';
                                } else if (!msg) {
                                    msg = 'files.msg_upload_failed';
                                }
                                item.error = msg;
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

                confirmDelete(file) {
                    this.deleteTarget = file;
                },

                deleteFile() {
                    if (!this.deleteTarget) return;
                    var self   = this;
                    var target = this.deleteTarget;
                    this.deleteTarget = null;

                    tmaxios.delete('/api/v1/file', {
                        data: { name: target.name }
                    })
                    .then(function() {
                        self.files = self.files.filter(f => f.name !== target.name);
                        self.showMessage('files.msg_deleted', 'success');
                    })
                    .catch(function() {
                        self.showMessage('files.msg_delete_error', 'error');
                    });
                },

                internalLink(file) {
                    return file.url || ('media/files/' + file.name);
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
