/**
 * The refresh button in a page's github tab.
 *
 * Typemill's editor draws a meta tab with the Vue component named after that tab
 * when one is registered, and falls back to `tab-defaulttab` - its own generic
 * form - otherwise. Registering `tab-github` therefore takes this tab over, so
 * the generic form is rendered inside it: the fields keep being drawn, validated
 * and saved by the core, and this only adds a button above them and passes the
 * save event back up.
 *
 * The readme is stored and only checked now and then, which is what keeps a page
 * working when GitHub does not answer - but it also means a change on GitHub is
 * not visible at once. This is the way to ask for it now.
 */
(function () {
    if (typeof app === 'undefined' || typeof app.component !== 'function') {
        return;
    }

    app.component('tab-github', {
        props: [
            'item',
            'formData',
            'formDefinitions',
            'pageid',
            'saved',
            'errors',
            'message',
            'messageClass',
            'translationfor',
        ],

        emits: ['saveform'],

        data: function () {
            return {
                busy: false,
                note: '',
                noteClass: '',
            };
        },

        template: `
            <section>
                <div class="flex flex-wrap items-center gap-3 border-2 border-stone-200 dark:border-stone-600 p-4 my-8">
                    <button
                        type="button"
                        class="px-4 py-2 bg-stone-700 dark:bg-stone-600 hover:bg-stone-900 hover:dark:bg-stone-900 text-white cursor-pointer transition duration-100 disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="busy || !repository"
                        @click.prevent="refresh"
                    >
                        {{ busy ? $filters.translate('githubreadme.refreshing') : $filters.translate('githubreadme.refresh_now') }}
                    </button>

                    <span class="text-sm text-stone-500 dark:text-stone-300 flex-1 min-w-48">
                        {{ $filters.translate('githubreadme.refresh_help') }}
                    </span>

                    <span v-if="note" :class="noteClass" class="text-sm w-full" data-githubreadme-note>{{ note }}</span>
                </div>

                <tab-defaulttab
                    v-bind="$props"
                    v-on:saveform="$emit('saveform')"
                ></tab-defaulttab>
            </section>`,

        computed: {
            repository: function () {
                return this.formData && this.formData.repository
                    ? String(this.formData.repository).trim()
                    : '';
            },
        },

        methods: {
            /**
             * Ask for the file itself, now.
             *
             * What the form currently holds is sent, not what the page has saved,
             * so the answer is about the repository the author is looking at.
             */
            refresh: function () {
                var self = this;

                if (!this.repository || this.busy) {
                    return;
                }

                this.busy = true;
                this.note = '';
                this.noteClass = '';

                tmaxios
                    .post('/api/v1/githubreadme/refresh', {
                        repository: this.repository,
                        branch: this.formData.branch || '',
                        path: this.formData.path || '',
                    })
                    .then(function (response) {
                        var data = response.data || {};

                        if (data.ok) {
                            self.say('githubreadme.refresh_done', 'text-teal-600 dark:text-teal-400', data);
                            return;
                        }

                        // A failed refresh leaves the stored copy alone, so the
                        // page is still whole - the message says which of the two
                        // situations this is.
                        self.say(
                            data.kept ? 'githubreadme.refresh_kept' : 'githubreadme.refresh_empty',
                            'text-rose-600 dark:text-rose-400',
                            data
                        );
                    })
                    .catch(function (error) {
                        var status = error && error.response ? error.response.status : 0;

                        self.say(
                            status === 422 ? 'githubreadme.refresh_invalid' : 'githubreadme.refresh_failed',
                            'text-rose-600 dark:text-rose-400',
                            {}
                        );
                    })
                    .then(function () {
                        self.busy = false;
                    });
            },

            say: function (key, cssClass, data) {
                var text = this.$filters.translate(key);

                if (data && data.failure) {
                    text = text + ' ' + data.failure;
                }

                this.note = text;
                this.noteClass = cssClass;
            },
        },
    });
})();
