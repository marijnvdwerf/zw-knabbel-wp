/**
 * Knabbel WP block editor integration.
 *
 * Adds the Radionieuws toggle and the Babbel sync status to the Summary panel.
 * The classic editor shows the same in its Publish box (includes/admin/metabox.php).
 * No build step: this uses the wp.* globals declared as script dependencies.
 */

(() => {
    const { registerPlugin } = wp.plugins;
    const { PluginPostStatusInfo, store: editorStore } = wp.editor;
    const { ToggleControl } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const { createElement: h } = wp.element;

    const META_KEY = '_zw_knabbel_send_to_babbel';
    const strings = knabbel_editor;

    function KnabbelStatusInfo() {
        const { enabled, status } = useSelect((select) => {
            const editor = select(editorStore);

            return {
                enabled: Boolean(
                    editor.getEditedPostAttribute('meta')?.[META_KEY],
                ),
                status: editor.getEditedPostAttribute('knabbel_status'),
            };
        }, []);
        const { editPost } = useDispatch(editorStore);

        return h(
            PluginPostStatusInfo,
            { className: 'knabbel-post-status' },
            h(
                'div',
                { style: { width: '100%' } },
                h(ToggleControl, {
                    __nextHasNoMarginBottom: true,
                    label: strings.label,
                    checked: enabled,
                    // Only send this key; the other meta is left alone.
                    onChange: (value) =>
                        editPost({ meta: { [META_KEY]: value } }),
                }),
                enabled &&
                    status &&
                    h(
                        'p',
                        { className: 'knabbel-sync-status' },
                        `${strings.status}: ${status.status_label}`,
                    ),
                status?.error_message &&
                    h(
                        'div',
                        { className: 'knabbel-sync-warning', role: 'alert' },
                        h('strong', null, strings.warning),
                        h('div', null, status.error_message),
                    ),
            ),
        );
    }

    registerPlugin('zw-knabbel-wp', { render: KnabbelStatusInfo });
})();
