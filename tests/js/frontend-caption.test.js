'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList { add() {} remove() {} }
function caption(id, scrollHeight, clientHeight) {
	return { id, scrollHeight, clientHeight, dataset: {}, attributes: {}, setAttribute(name, value) { this.attributes[name] = value; }, getAttribute(name) { return this.attributes[name]; } };
}
function button() {
	return { hidden: true, dataset: { moreLabel: 'Mehr anzeigen', lessLabel: 'Weniger anzeigen' }, attributes: {}, listeners: {}, textContent: 'Mehr anzeigen', setAttribute(name, value) { this.attributes[name] = value; }, addEventListener(name, callback) { this.listeners[name] = callback; } };
}

const longCaption = caption('long-caption', 180, 80);
const shortCaption = caption('short-caption', 60, 60);
const longButton = button();
const shortButton = button();
const document = {
	documentElement: { classList: new ClassList() },
	querySelector(selector) { if (selector.includes('long-caption')) { return longButton; } if (selector.includes('short-caption')) { return shortButton; } return null; },
	querySelectorAll(selector) { return '[data-lif-caption][data-collapsible="1"]' === selector ? [longCaption, shortCaption] : []; },
};
const browserWindow = { matchMedia: () => ({ matches: false }) };
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8');
vm.runInNewContext(source, { document, window: browserWindow, Array, Promise });

assert.equal(longCaption.attributes['data-collapsed'], 'true');
assert.equal(longButton.hidden, false);
assert.equal(shortCaption.attributes['data-collapsed'], 'false');
assert.equal(shortButton.hidden, true);
longButton.listeners.click();
assert.equal(longCaption.attributes['data-collapsed'], 'false');
assert.equal(longButton.textContent, 'Weniger anzeigen');
longButton.listeners.click();
assert.equal(longCaption.attributes['data-collapsed'], 'true');
assert.equal(longButton.textContent, 'Mehr anzeigen');
console.log('frontend caption overflow and toggle behavior OK');
