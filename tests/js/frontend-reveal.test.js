'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList {
	constructor() { this.values = new Set(); }
	add(value) { this.values.add(value); }
	remove(value) { this.values.delete(value); }
	contains(value) { return this.values.has(value); }
	toggle(value, force) { if (force) { this.add(value); } else { this.remove(value); } }
}

const button = { listeners: {}, attributes: {}, addEventListener(name, callback) { this.listeners[name] = callback; }, setAttribute(name, value) { this.attributes[name] = value; } };
const reveal = { hidden: true, dataset: {}, querySelector(selector) { return '[data-vemoro-feed-more]' === selector ? button : null; } };
const closeButton = { listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; } };
const close = { hidden: true, dataset: {}, classList: new ClassList(), querySelector(selector) { return '[data-vemoro-feed-close-button]' === selector ? closeButton : null; } };
const videos = [{ paused: false, pause() { this.paused = true; }, closest() { return null; } }, { paused: false, pause() { this.paused = true; }, closest() { return null; } }];
const stage = { offsetHeight: 800 };
const posts = [0, 0, 900, 900].map((offsetTop) => ({ offsetTop, offsetHeight: 900, querySelector(selector) { return '.vemoro-post__stage' === selector ? stage : null; } }));
const style = {
	values: {}, maxHeight: '',
	setProperty(name, value) { this.values[name] = value; },
	removeProperty(name) { delete this.values[name]; if ('max-height' === name) { this.maxHeight = ''; } },
};
const feed = {
	dataset: {}, classList: new ClassList(), style, offsetHeight: 1100, scrollHeight: 3100, listeners: {},
	querySelectorAll(selector) { if ('.vemoro-post' === selector) { return posts; } if ('[data-vemoro-video]' === selector) { return videos; } return []; },
	querySelector(selector) { if ('[data-vemoro-feed-reveal]' === selector) { return reveal; } if ('[data-vemoro-feed-close]' === selector) { return close; } return null; },
	getBoundingClientRect() { return { height: '1' === this.dataset.vemoroExpanded ? 3100 : 1100, top: 0, bottom: 3100 }; },
	addEventListener(name, callback) { this.listeners[name] = callback; },
	removeEventListener(name) { delete this.listeners[name]; },
	scrollIntoView() { this.scrolledIntoView = true; },
};
const document = {
	documentElement: { classList: new ClassList() },
	querySelector() { return null; },
	querySelectorAll(selector) { return '[data-vemoro-feed]' === selector ? [feed] : []; },
};
const browserWindow = { innerHeight: 800, matchMedia: () => ({ matches: false }), requestAnimationFrame(callback) { callback(); return 1; } };
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8');
vm.runInNewContext(source, { document, window: browserWindow, Array, Promise });

assert.equal(feed.classList.contains('is-collapsed'), true);
assert.equal(style.values['--vemoro-collapsed-height'], '1100px');
assert.equal(reveal.hidden, false);
button.listeners.click();
assert.equal(feed.dataset.vemoroExpanded, '1');
assert.equal(feed.classList.contains('is-collapsed'), false);
assert.equal(feed.classList.contains('is-expanding'), true);
assert.equal(style.maxHeight, '3100px');
assert.equal(reveal.hidden, true);
feed.listeners.transitionend({ propertyName: 'max-height' });
assert.equal(feed.classList.contains('is-expanding'), false);
assert.equal(style.maxHeight, '');
assert.equal(style.values['--vemoro-collapsed-height'], undefined);
assert.equal(close.hidden, false);
closeButton.listeners.click();
assert.equal(videos[0].paused, false);
assert.equal(videos[1].paused, false);
assert.equal(feed.classList.contains('is-collapsing'), true);
assert.equal(style.maxHeight, '1100px');
assert.equal(feed.scrolledIntoView, true);
feed.listeners.transitionend({ propertyName: 'max-height' });
assert.equal(videos[0].paused, true);
assert.equal(videos[1].paused, true);
assert.equal(feed.classList.contains('is-collapsed'), true);
assert.equal(close.hidden, true);
assert.equal(reveal.hidden, false);
console.log('frontend responsive reveal, sticky close, and video pause behavior OK');
