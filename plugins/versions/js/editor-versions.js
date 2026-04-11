(function () {
    const style = document.createElement('style');
    style.textContent = `
        .tm-versions-grid{display:grid;grid-template-columns:minmax(15rem,20rem) minmax(0,1fr);gap:1.5rem;align-items:start}
        .tm-versions-list{height:32rem;overflow:auto}
        .tm-versions-list button.active{background:#e7e5e4}
        .tm-versions-detail{display:flex;flex-direction:column;height:32rem;overflow:hidden}
        .tm-versions-diff{flex:1;overflow:auto;background:#1c1917;color:#f5f5f4;scrollbar-color:#a8a29e #292524;scrollbar-width:auto}
        .tm-versions-diff::-webkit-scrollbar{width:14px;height:14px}
        .tm-versions-diff::-webkit-scrollbar-track{background:#292524}
        .tm-versions-diff::-webkit-scrollbar-thumb{background:#a8a29e;border:3px solid #292524;border-radius:999px}
        .tm-versions-diff::-webkit-scrollbar-thumb:hover{background:#d6d3d1}
        .tm-versions-diff-stats-wrap{position:sticky;top:0;z-index:2;padding:.75rem;background:#292524;border-bottom:1px solid #44403c}
        .tm-versions-diff-stats{display:flex;gap:.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem}
        .tm-versions-diff-stats .add{color:#7ef0ca}
        .tm-versions-diff-stats .remove{color:#ff9eb1}
        .tm-versions-diff-line{display:block;padding:.22rem .75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;white-space:pre-wrap;word-break:break-word}
        .tm-versions-diff-line.add{background:#12352d;color:#d7fff4}
        .tm-versions-diff-line.remove{background:#4a1f2a;color:#ffe1e7}
        @media (max-width: 960px){.tm-versions-grid{grid-template-columns:1fr}}
        .tm-mergely-overlay{position:fixed;inset:0;background:rgba(68,64,60,.92);display:flex;align-items:center;justify-content:center;z-index:60}
        .tm-mergely-dialog{background:#fff;border:1px solid #14b8a6;box-shadow:0 8px 32px rgba(0,0,0,.3);width:95vw;height:90vh;display:flex;flex-direction:column;position:relative}
        .dark .tm-mergely-dialog{background:#292524;border-color:#d6d3d1}
        .tm-mergely-header{display:flex;justify-content:space-between;align-items:center;padding:.75rem 1.25rem;border-bottom:1px solid #e7e5e4;flex-shrink:0}
        .dark .tm-mergely-header{border-color:#44403c}
        .tm-mergely-header h3{font-size:1.1rem;font-weight:700;color:#1c1917}
        .dark .tm-mergely-header h3{color:#e7e5e4}
        .tm-mergely-labels{display:flex;flex-shrink:0;border-bottom:1px solid #e7e5e4;align-items:center}
        .dark .tm-mergely-labels{border-color:#44403c}
        .tm-mergely-label-cell{flex:1;padding:.3rem .75rem;border-right:1px solid #e7e5e4}
        .dark .tm-mergely-label-cell{border-right-color:#44403c}
        .tm-mergely-labels span{flex:1;text-align:center;padding:.4rem;font-size:.8rem;font-weight:600;color:#78716c}
        .dark .tm-mergely-labels span{color:#a8a29e}
        .tm-mergely-labels select{width:100%;padding:.3rem .5rem;font-size:.8rem;color:#1c1917;background:#e7e5e4;border:1px solid #d6d3d1;outline:none;cursor:pointer}
        .dark .tm-mergely-labels select{color:#e7e5e4;background:#44403c;border-color:#57534e}
        .tm-mergely-body{flex:1;overflow:hidden}
        .tm-mergely-body #tm-mergely-editor{height:100%}
        .tm-mergely-footer{display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:.75rem 1.25rem;border-top:1px solid #e7e5e4;flex-shrink:0}
        .tm-mergely-notice{font-size:.7rem;color:#a8a29e}
        .tm-mergely-notice a{color:#a8a29e;text-decoration:underline}
        .tm-mergely-footer-actions{display:flex;gap:.5rem}
        .dark .tm-mergely-footer{border-color:#44403c}
        .tm-mergely-confirm{position:absolute;inset:0;background:rgba(41,37,36,.75);display:flex;align-items:center;justify-content:center;z-index:10}
        .tm-mergely-confirm-box{background:#fff;border:1px solid #14b8a6;padding:1.5rem 2rem;max-width:34rem;width:90%;box-shadow:0 4px 24px rgba(0,0,0,.25)}
        .dark .tm-mergely-confirm-box{background:#292524;border-color:#d6d3d1}
        .tm-mergely-confirm-sides{display:flex;gap:.75rem;margin-bottom:1.25rem}
        .tm-mergely-confirm-side{flex:1;padding:.75rem 1rem;border:2px solid transparent}
        .tm-mergely-confirm-side.active{border-color:#14b8a6;background:#f0fdfa}
        .dark .tm-mergely-confirm-side.active{background:#134e4a}
        .tm-mergely-confirm-side.inactive{border-color:#e7e5e4;background:#f5f5f4;opacity:.6}
        .dark .tm-mergely-confirm-side.inactive{border-color:#44403c;background:#1c1917}
        .tm-mergely-confirm-side-badge{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#78716c;margin-bottom:.3rem}
        .tm-mergely-confirm-side.active .tm-mergely-confirm-side-badge{color:#0d9488}
        .tm-mergely-confirm-side-name{font-size:.85rem;font-weight:600;color:#1c1917;word-break:break-word}
        .dark .tm-mergely-confirm-side-name{color:#e7e5e4}
        .tm-mergely-confirm-side-role{font-size:.75rem;color:#0d9488;margin-top:.3rem;font-weight:600}
        .tm-mergely-confirm-text{font-size:.85rem;color:#78716c;margin-bottom:1.25rem}
        .dark .tm-mergely-confirm-text{color:#a8a29e}
        .tm-mergely-confirm-actions{display:flex;justify-content:flex-end;gap:.5rem}
    `;
    document.head.appendChild(style);

    app.component('tab-versions', {
        props: ['item'],
        template: `
            <section class="dark:bg-stone-700 dark:text-stone-200">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-bold">{{ $filters.translate('Versions') }}</h2>
                        <p class="text-sm text-stone-500 dark:text-stone-300">{{ $filters.translate('Inspect changes, compare edits, and restore earlier versions.') }}</p>
                    </div>
                    <button
                        @click.prevent="loadVersions"
                        class="px-3 py-2 bg-stone-700 dark:bg-stone-600 hover:bg-stone-900 hover:dark:bg-stone-900 text-white transition duration-100"
                    >
                        {{ $filters.translate('Refresh') }}
                    </button>
                </div>

                <div v-if="loading" class="p-6 bg-stone-100 dark:bg-stone-800">{{ $filters.translate('Loading versions') }}...</div>
                <div v-else-if="error" class="p-4 bg-rose-500 text-white">{{ error }}</div>
                <div v-else-if="versions.length === 0" class="p-6 bg-stone-100 dark:bg-stone-800">{{ $filters.translate('No versions stored yet.') }}</div>
                <div v-else class="tm-versions-grid">
                    <div class="tm-versions-list bg-stone-100 dark:bg-stone-800">
                        <template v-for="version in versions" :key="version.id">
                            <div
                                v-if="version.event_only"
                                class="flex justify-between items-center px-4 py-2 border-b border-stone-200 dark:border-stone-700 text-xs text-stone-400 dark:text-stone-500 italic"
                            >
                                <span>{{ version.action }} — {{ version.user_label }}</span>
                                <span>{{ formatDate(version.created_at) }}</span>
                            </div>
                            <button
                                v-else
                                @click.prevent="selectVersion(version)"
                                class="block w-full text-left p-4 border-b border-stone-200 dark:border-stone-700 hover:bg-stone-200 hover:dark:bg-stone-700 transition duration-100"
                                :class="{ active: selectedVersion && selectedVersion.id === version.id }"
                            >
                                <div class="flex justify-between gap-3">
                                    <strong>{{ version.action }}</strong>
                                    <span class="text-xs">{{ formatDate(version.created_at) }}</span>
                                </div>
                                <div class="text-sm mt-1">{{ version.user_label }}</div>
                                <div class="text-xs mt-2 text-stone-500 dark:text-stone-300">
                                    +{{ version.diff_stats.added }} / -{{ version.diff_stats.removed }}
                                </div>
                            </button>
                        </template>
                    </div>

                    <div class="tm-versions-detail">
                        <div v-if="selectedDetail" class="bg-stone-100 dark:bg-stone-800 p-5 mb-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-lg font-bold">{{ selectedDetail.version.title || item.name }}</h3>
                                </div>
                            </div>
                            <div class="mt-3">
                                <p class="text-sm">{{ selectedDetail.version.user_label }} | {{ formatDate(selectedDetail.version.created_at) }}</p>
                                <p class="text-sm mt-1">{{ $filters.translate('Compared with') }}: {{ compareToDisplay(selectedDetail.compare_to) }}</p>
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button
                                    @click.prevent="openMergely(selectedDetail.version)"
                                    class="h-11 px-5 bg-teal-500 hover:bg-teal-600 text-white transition duration-100"
                                >
                                    {{ $filters.translate('versions.compare_and_restore') }}
                                </button>
                            </div>
                        </div>

                        <div v-if="selectedDetail" class="tm-versions-diff">
                            <div class="tm-versions-diff-stats-wrap">
                                <div v-if="selectedDetail.diff.stats.added === 0 && selectedDetail.diff.stats.removed === 0" class="tm-versions-diff-stats">
                                    <span>{{ $filters.translate('versions.no_changes') }}</span>
                                </div>
                                <div v-else class="tm-versions-diff-stats">
                                    <span class="add">+{{ selectedDetail.diff.stats.added }}</span>
                                    <span class="remove">-{{ selectedDetail.diff.stats.removed }}</span>
                                </div>
                            </div>
                            <div
                                v-for="(line, lineIndex) in visibleDiffLines"
                                :key="lineIndex"
                                class="tm-versions-diff-line"
                                :class="line.type"
                            >
                                <span>{{ prefix(line.type) }} {{ line.line }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="mergelyOpen"
                    class="tm-mergely-overlay"
                    @click.self="closeMergely"
                >
                    <div class="tm-mergely-dialog">
                        <div class="tm-mergely-header">
                            <h3>{{ mergelyVersionLabel }}</h3>
                            <div class="flex items-center gap-2">
                                <span v-if="mergelySaving" class="text-sm text-stone-500">{{ $filters.translate('versions.saving') }}...</span>
                            </div>
                        </div>
                        <div class="tm-mergely-labels">
                            <div class="tm-mergely-label-cell">
                                <select v-model="mergelyLeftVersionId">
                                    <option v-for="v in selectableVersions" :key="v.id" :value="v.id">{{ formatVersionLabel(v) }} (+{{ v.diff_stats.added }} / -{{ v.diff_stats.removed }})</option>
                                </select>
                            </div>
                            <span>{{ $filters.translate('versions.current_version') }} ({{ $filters.translate('versions.editable') }})</span>
                        </div>
                        <div class="tm-mergely-body">
                            <div id="tm-mergely-editor"></div>
                        </div>
                        <div class="tm-mergely-footer">
                            <span class="tm-mergely-notice">Diff powered by <a href="https://mergely.com" target="_blank" rel="noopener">Mergely</a>, licensed under <a href="https://www.gnu.org/licenses/lgpl-3.0.html" target="_blank" rel="noopener">LGPL</a></span>
                            <div class="tm-mergely-footer-actions">
                                <button
                                    @click.prevent="closeMergely"
                                    class="px-4 py-2 bg-stone-200 dark:bg-stone-500 hover:bg-stone-300 dark:hover:bg-stone-400 text-stone-900 dark:text-stone-200 text-sm transition duration-100"
                                >
                                    {{ $filters.translate('versions.close') }}
                                </button>
                                <button
                                    @click.prevent="mergelyConfirmAction = 'left'"
                                    :disabled="mergelySaving"
                                    class="px-4 py-2 border border-teal-500 text-teal-600 dark:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-900 text-sm transition duration-100"
                                >
                                    {{ $filters.translate('versions.restore_left') }}
                                </button>
                                <button
                                    @click.prevent="mergelyConfirmAction = 'right'"
                                    :disabled="mergelySaving"
                                    class="px-4 py-2 bg-teal-500 hover:bg-teal-600 text-white text-sm transition duration-100"
                                >
                                    {{ $filters.translate('versions.save_as_draft') }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="mergelyConfirmAction"
                            class="tm-mergely-confirm"
                        >
                            <div class="tm-mergely-confirm-box">
                                <div class="tm-mergely-confirm-sides">
                                    <div class="tm-mergely-confirm-side" :class="mergelyConfirmAction === 'left' ? 'active' : 'inactive'">
                                        <div class="tm-mergely-confirm-side-badge">{{ $filters.translate('versions.left_side') }}</div>
                                        <div class="tm-mergely-confirm-side-name">{{ mergelyVersionLabel }}</div>
                                        <div v-if="mergelyConfirmAction === 'left'" class="tm-mergely-confirm-side-role">{{ $filters.translate('versions.confirm_will_be_saved') }}</div>
                                    </div>
                                    <div class="tm-mergely-confirm-side" :class="mergelyConfirmAction === 'right' ? 'active' : 'inactive'">
                                        <div class="tm-mergely-confirm-side-badge">{{ $filters.translate('versions.right_side') }}</div>
                                        <div class="tm-mergely-confirm-side-name">{{ $filters.translate('versions.current_version') }}</div>
                                        <div v-if="mergelyConfirmAction === 'right'" class="tm-mergely-confirm-side-role">{{ $filters.translate('versions.confirm_will_be_saved') }}</div>
                                    </div>
                                </div>
                                <p class="tm-mergely-confirm-text">{{ $filters.translate('versions.confirm_warning') }}</p>
                                <div class="tm-mergely-confirm-actions">
                                    <button
                                        @click.prevent="mergelyConfirmAction = null"
                                        class="px-4 py-2 bg-stone-200 dark:bg-stone-600 hover:bg-stone-300 dark:hover:bg-stone-500 text-stone-900 dark:text-stone-200 text-sm transition duration-100"
                                    >
                                        {{ $filters.translate('versions.cancel') }}
                                    </button>
                                    <button
                                        @click.prevent="confirmMergelyAction"
                                        class="px-4 py-2 bg-teal-500 hover:bg-teal-600 text-white text-sm transition duration-100"
                                    >
                                        {{ $filters.translate('versions.confirm') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </section>
        `,
        data() {
            return {
                loading: true,
                error: '',
                detailsCache: {},
                versions: [],
                selectedVersion: null,
                selectedDetail: null,
                mergelyOpen: false,
                mergelyInstance: null,
                mergelySaving: false,
                mergelyLeftVersionId: null,
                mergelyConfirmAction: null,
                mergelyAppEventCtor: null,
            };
        },
        watch: {
            mergelyLeftVersionId: function (newId) {
                var self = this;
                if (!self.mergelyOpen || !self.mergelyInstance || !newId) return;
                self.fetchVersionDetail(newId)
                .then(function (detail) {
                    self.mergelyInstance.lhs(detail.version.markdown || '');
                })
                .catch(function (error) {
                    self.error = handleErrorMessage(error) || 'Could not load version.';
                });
            }
        },
        computed: {
            selectableVersions() {
                return this.versions.filter(function (version) {
                    return !version.event_only;
                });
            },
            visibleDiffLines() {
                if (!this.selectedDetail || !this.selectedDetail.diff || !Array.isArray(this.selectedDetail.diff.lines)) {
                    return [];
                }

                return this.selectedDetail.diff.lines.filter(function (line) {
                    return line.type === 'add' || line.type === 'remove';
                });
            },
            mergelyVersionLabel() {
                var self = this;
                var v = self.selectableVersions.find(function (v) { return v.id === self.mergelyLeftVersionId; });
                return v ? self.formatVersionLabel(v) : '';
            }
        },
        mounted() {
            this.loadVersions();
        },
        methods: {
            loadVersions() {
                var self = this;
                self.loading = true;
                self.error = '';

                tmaxios.get('/api/v1/versions/page', {
                    params: { url: data.urlinfo.route }
                })
                .then(function (response) {
                    self.detailsCache = {};
                    self.versions = response.data.versions || [];
                    self.loading = false;
                    if (self.selectableVersions.length > 0) {
                        self.selectVersion(self.selectableVersions[0]);
                    } else {
                        self.selectedVersion = null;
                        self.selectedDetail = null;
                    }
                })
                .catch(function (error) {
                    self.loading = false;
                    self.error = handleErrorMessage(error) || 'Versions could not be loaded.';
                });
            },
            fetchVersionDetail(versionId) {
                var self = this;

                if (self.detailsCache[versionId]) {
                    return Promise.resolve(self.detailsCache[versionId]);
                }

                return tmaxios.get('/api/v1/versions/page/version', {
                    params: {
                        url: data.urlinfo.route,
                        version_id: versionId
                    }
                })
                .then(function (response) {
                    self.detailsCache[versionId] = response.data;
                    return response.data;
                });
            },
            selectVersion(version) {
                var self = this;
                if (!version || version.event_only) {
                    return;
                }
                self.selectedVersion = version;

                self.fetchVersionDetail(version.id)
                .then(function (detail) {
                    self.selectedDetail = detail;
                })
                .catch(function (error) {
                    self.error = handleErrorMessage(error) || 'Version details could not be loaded.';
                });
            },
            formatDate(value) {
                if (!value) {
                    return '';
                }

                return new Date(value).toLocaleString();
            },
            formatVersionLabel(v) {
                if (!v) return '';
                return (v.user_label ? v.user_label + ' | ' : '') + this.formatDate(v.created_at);
            },
            compareToDisplay(compareTo) {
                if (!compareTo) return '';
                if (compareTo.created_at) return this.formatVersionLabel(compareTo);
                return compareTo.label || '';
            },
            prefix(type) {
                if (type === 'add') return '+';
                if (type === 'remove') return '-';
                return ' ';
            },
            openMergely(version) {
                var self = this;
                if (!version || version.event_only) {
                    return;
                }

                self.mergelyLeftVersionId = version.id;

                Promise.all([
                    self.fetchVersionDetail(version.id),
                    tmaxios.get('/api/v1/versions/page/current', { params: { url: data.urlinfo.route } })
                ])
                .then(function (results) {
                    var versionMarkdown = results[0].version.markdown || '';
                    var currentMarkdown = results[1].data.markdown || '';
                    self.mergelyOpen = true;
                    self.$nextTick(function () {
                        self.initMergely(versionMarkdown, currentMarkdown);
                    });
                })
                .catch(function (error) {
                    self.error = handleErrorMessage(error) || 'Could not open diff viewer.';
                });
            },
            initMergely(leftText, rightText) {
                var self = this;
                var el = document.getElementById('tm-mergely-editor');
                if (!el) return;

                el.innerHTML = '';

                if (self.mergelyInstance) {
                    try { self.mergelyInstance.unbind(); } catch (e) {}
                    self.mergelyInstance = null;
                }

                // IMPORTANT: Vue's compiler overrides the global Event constructor.
                // Mergely (and CodeMirror internally) dispatch DOM-style events and
                // depend on the native window.Event. If Vue's override is in place,
                // Mergely's event system silently fails — the 'updated' event never
                // fires and lhs() calls from the version dropdown have no effect.
                // Swap back to the native constructor before init, restore on close.
                if (typeof window.Event === 'function' && Event !== window.Event) {
                    self.mergelyAppEventCtor = Event;
                    Event = window.Event;
                }

                var instance = new Mergely('#tm-mergely-editor', {
                    license: 'lgpl-separate-notice',
                    wrap_lines: true,
                    sidebar: false,
                    ignorews: false,
                    ignorecase: false,
                    lhs: leftText,
                    rhs: rightText,
                    lhs_cmsettings: { readOnly: true },
                    rhs_cmsettings: { readOnly: false }
                });

                instance.once('updated', function () {
                    instance.scrollToDiff('next');
                });

                // IMPORTANT: Do not remove this requestAnimationFrame block.
                // Calling lhs()/rhs() after the first paint ensures CodeMirror
                // has fully initialised its internal state. Without this, the
                // watcher-driven lhs() calls (version dropdown) fail silently
                // because CodeMirror's change event doesn't propagate correctly
                // from a not-yet-rendered editor. update() also bypasses the
                // 50 ms _changing() debounce for a reliable immediate diff.
                requestAnimationFrame(function () {
                    instance.lhs(leftText);
                    instance.rhs(rightText);
                    instance.update();
                });

                self.mergelyInstance = instance;
            },
            saveMergelyRight() {
                this.saveMergely('rhs');
            },
            restoreMergelyLeft() {
                this.saveMergely('lhs');
            },
            saveMergely(side) {
                var self = this;
                if (!self.mergelyInstance || self.mergelySaving) return;

                self.mergelySaving = true;

                tmaxios.post('/api/v1/versions/page/save', {
                    url: data.urlinfo.route,
                    markdown: self.mergelyInstance.get(side)
                })
                .then(function () {
                    self.closeMergely();
                    window.location.reload();
                })
                .catch(function (error) {
                    self.mergelySaving = false;
                    self.error = handleErrorMessage(error) || (side === 'rhs' ? 'Could not save draft.' : 'Could not restore version.');
                });
            },
            confirmMergelyAction() {
                var action = this.mergelyConfirmAction;
                this.mergelyConfirmAction = null;
                if (action === 'left') {
                    this.restoreMergelyLeft();
                } else if (action === 'right') {
                    this.saveMergelyRight();
                }
            },
            closeMergely() {
                if (this.mergelyInstance) {
                    try { this.mergelyInstance.unbind(); } catch (e) {}
                    this.mergelyInstance = null;
                }
                if (this.mergelyAppEventCtor) {
                    Event = this.mergelyAppEventCtor;
                    this.mergelyAppEventCtor = null;
                }
                this.mergelyOpen = false;
                this.mergelySaving = false;
                this.mergelyConfirmAction = null;
            }
        }
    });

    const deleteArticleWithVersions = function () {
        tmaxios.delete('/api/v1/versions/article', {
            data: {
                url: data.urlinfo.route,
                item_id: this.item.keyPath
            }
        })
        .then(function (response) {
            window.location.replace(response.data.url);
        })
        .catch(function (error) {
            this.showModal = false;
            if (error.response) {
                let message = handleErrorMessage(error);
                if (message) {
                    this.message = message;
                    this.messageClass = 'bg-rose-500';
                }
            }
        }.bind(this));
    };

    const attachDeleteArticleOverride = function () {
        if (!publisher) {
            return;
        }

        if (publisher._versionsDeleteOverridden) {
            return;
        }

        if (publisher._component && publisher._component.methods) {
            publisher._component.methods.deleteArticle = deleteArticleWithVersions;
        }

        if (publisher._instance && publisher._instance.ctx) {
            const boundOverride = deleteArticleWithVersions.bind(publisher._instance.proxy);
            publisher._instance.ctx.deleteArticle = boundOverride;
            if (publisher._instance.proxy) {
                publisher._instance.proxy.deleteArticle = boundOverride;
            }
            publisher._versionsDeleteOverridden = true;
        }
    };

    if (tmaxios && !tmaxios._versionsDeleteWrapped) {
        const originalDelete = tmaxios.delete.bind(tmaxios);
        tmaxios.delete = function (url, config) {
            if (url === '/api/v1/article') {
                return originalDelete('/api/v1/versions/article', config);
            }

            return originalDelete(url, config);
        };
        tmaxios._versionsDeleteWrapped = true;
    }

    attachDeleteArticleOverride();
    setTimeout(attachDeleteArticleOverride, 0);
})();
