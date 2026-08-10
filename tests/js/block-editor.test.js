'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

let registration;
const createElement = (type, props, ...children) => ({ type, props: props || {}, children });
const component = (name) => name;
const wp = {
	blocks: { registerBlockType(name, definition) { registration = { name, definition }; } },
	element: { createElement },
	components: { PanelBody: component('PanelBody'), RangeControl: component('RangeControl'), ToggleControl: component('ToggleControl'), SelectControl: component('SelectControl'), TextControl: component('TextControl'), ColorPalette: component('ColorPalette') },
	blockEditor: { InspectorControls: component('InspectorControls'), useBlockProps(props) { return Object.assign({ 'data-block-wrapper': '1' }, props); } },
	serverSideRender: component('ServerSideRender'),
	i18n: { __(text) { return text; } },
};
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/block.js'), 'utf8');
vm.runInNewContext(source, { window: { wp, vemoroBlockDefaults: { posts: 24, columns: 3 }, vemoroBlockPalette: [{ name: 'Green sand', slug: 'vemoro-green-sand', color: '#F3FAF6' }], vemoroBlockTypography: { fontSizes: [{ name: 'Large', slug: 'large' }], fontFamilies: [{ name: 'GrueneType Neue', slug: 'gruenetypeneue' }] } } });

assert.equal(registration.name, 'vemoro-socialfeed/feed');
const output = registration.definition.edit({ attributes: {}, setAttributes() {} });
assert.equal(output.type, 'div');
assert.equal(output.props.className, 'vemoro-block-editor');
assert.equal(output.props['data-block-wrapper'], '1');
assert.equal(output.children[0][0].type, 'InspectorControls');
assert.equal(output.children[0][1].props.className, 'vemoro-block-editor__preview');
const inspectorPanels = output.children[0][0].children[0];
assert.equal(inspectorPanels[1].type, 'PanelBody');
assert.equal(inspectorPanels[1].children[0].type, 'ColorPalette');
assert.equal(inspectorPanels[2].props.title, 'Heading typography');
assert.equal(inspectorPanels[2].children[0].type, 'SelectControl');
console.log('Gutenberg block wrapper, inspector, and preview structure OK');
