/**
 * Editor registration for the AI Language Switcher block (server-rendered on the frontend).
 * Plain wp.* globals, no build step.
 */
(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;

    blocks.registerBlockType('ai-translate/language-switcher', {
        title: __('AI Language Switcher', 'ai-translate'),
        description: __('Shows the AI Translate language switcher.', 'ai-translate'),
        icon: 'translation',
        category: 'widgets',
        attributes: {
            type: { type: 'string', default: 'dropdown' },
            showFlags: { type: 'boolean', default: true },
            showCodes: { type: 'boolean', default: true }
        },
        edit: function (props) {
            var attrs = props.attributes;
            return el(
                'div',
                blockEditor.useBlockProps(),
                el(
                    blockEditor.InspectorControls,
                    {},
                    el(
                        components.PanelBody,
                        { title: __('Switcher settings', 'ai-translate') },
                        el(components.SelectControl, {
                            label: __('Type', 'ai-translate'),
                            value: attrs.type,
                            options: [
                                { label: __('Dropdown', 'ai-translate'), value: 'dropdown' },
                                { label: __('Inline', 'ai-translate'), value: 'inline' }
                            ],
                            onChange: function (v) { props.setAttributes({ type: v }); }
                        }),
                        el(components.ToggleControl, {
                            label: __('Show flags', 'ai-translate'),
                            checked: attrs.showFlags,
                            onChange: function (v) { props.setAttributes({ showFlags: v }); }
                        }),
                        el(components.ToggleControl, {
                            label: __('Show language codes', 'ai-translate'),
                            checked: attrs.showCodes,
                            onChange: function (v) { props.setAttributes({ showCodes: v }); }
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'ai-translate-block-placeholder' },
                    el('span', { className: 'dashicons dashicons-translation' }),
                    ' ' + __('AI Language Switcher (rendered on the frontend)', 'ai-translate')
                )
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
