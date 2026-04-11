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

                uploadFiles(fileList) {
                    const queue = fileList.map(f => ({ name: f.name, file: f, status: 'queued', error: '' }));
                    this.uploadQueue = queue;
                    this.processQueue(0);
                },

                processQueue(index) {
                    if (index >= this.uploadQueue.length) {
                        this.loadFiles();
                        var self = this;
                        setTimeout(function() { self.uploadQueue = []; }, 2000);
                        return;
                    }

                    var self = this;
                    var item = this.uploadQueue[index];
                    item.status = 'uploading';

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
                            item.error  = error.response?.data?.message || 'files.msg_upload_failed';
                            self.processQueue(index + 1);
                        });
                    };
                    reader.readAsDataURL(item.file);
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
