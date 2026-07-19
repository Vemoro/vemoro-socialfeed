'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList { add() {} remove() {} }
function stage(height) {
	return { offsetHeight: height, style: { height: '', removeProperty(name) { if ('height' === name) { this.height = ''; } } }, getBoundingClientRect() { return { height }; } };
}
function post(offsetTop, mediaStage) { return { offsetTop, offsetHeight: mediaStage.offsetHeight, querySelector(selector) { return '.lif-post__stage' === selector ? mediaStage : null; } }; }

const equalStages = [stage(400), stage(400)];
const mixedStages = [stage(300), stage(500)];
const posts = [post(0, equalStages[0]), post(0, equalStages[1]), post(600, mixedStages[0]), post(600, mixedStages[1])];
const feed = {
	dataset: {},
	querySelectorAll(selector) { return '.lif-post' === selector ? posts : []; },
	querySelector() { return null; },
};
const document = {
	documentElement: { classList: new ClassList() },
	querySelector() { return null; },
	querySelectorAll(selector) { return '[data-lif-feed]' === selector ? [feed] : []; },
};
const browserWindow = { matchMedia: () => ({ matches: false }) };
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8');
vm.runInNewContext(source, { document, window: browserWindow, Array, Object, Math, Promise });

assert.equal(equalStages[0].style.height, '');
assert.equal(equalStages[1].style.height, '');
assert.equal(mixedStages[0].style.height, '500px');
assert.equal(mixedStages[1].style.height, '500px');
console.log('frontend conditional row height normalization OK');
