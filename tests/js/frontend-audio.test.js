'use strict';

const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList {
	constructor() { this.values = new Set(); }
	add(value) { this.values.add(value); }
	remove(value) { this.values.delete(value); }
}

class Video {
	constructor() {
		this.listeners = {};
		this.paused = true;
		this._muted = true;
		this._volume = 1;
	}
	addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
	emit(name) { (this.listeners[name] || []).forEach((callback) => callback()); }
	get muted() { return this._muted; }
	set muted(value) { if (this._muted !== value) { this._muted = value; this.emit('volumechange'); } }
	get volume() { return this._volume; }
	set volume(value) { if (this._volume !== value) { this._volume = value; this.emit('volumechange'); } }
	closest() { return this.stage; }
	play() { this.paused = false; return Promise.resolve(); }
	pause() { this.paused = true; }
}

function stage(video) {
	const button = { dataset: { playLabel: 'play', pauseLabel: 'pause' }, setAttribute() {}, addEventListener() {} };
	return {
		classList: new ClassList(),
		dataset: { lifHoverAutoplay: '1' },
		listeners: {},
		querySelector(selector) { return '[data-lif-video]' === selector ? video : button; },
		addEventListener(name, callback) { this.listeners[name] = callback; },
	};
}

(async () => {
	const videos = [new Video(), new Video()];
	const stages = videos.map((video) => stage(video));
	videos.forEach((video, index) => { video.stage = stages[index]; });
	const confirmButton = { listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; } };
	const cancelButton = { listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; } };
	const dialog = { dataset: {}, listeners: {}, open: false, querySelector(selector) { if ('[data-lif-external-confirm-button]' === selector) { return confirmButton; } if ('[data-lif-external-cancel]' === selector) { return cancelButton; } return { textContent: 'privacy notice' }; }, addEventListener(name, callback) { this.listeners[name] = callback; }, showModal() { this.open = true; }, close() { this.open = false; } };
	const feed = { querySelector() { return dialog; } };
	const externalLink = { href: 'https://www.instagram.com/p/example/', target: '_blank', dataset: {}, listeners: {}, closest() { return feed; }, addEventListener(name, callback) { this.listeners[name] = callback; } };
	const document = {
		documentElement: { classList: new ClassList() },
		querySelector() { return null; },
		querySelectorAll(selector) {
			if ('[data-lif-video]' === selector) { return videos; }
			if ('[data-lif-video-stage]' === selector) { return stages; }
			if ('[data-lif-external-dialog]' === selector) { return [dialog]; }
			if ('[data-lif-external-confirm]' === selector) { return [externalLink]; }
			return [];
		},
	};
	const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8');
	const browserWindow = { opened: '', matchMedia: () => ({ matches: false }), open(url) { this.opened = url; }, location: { assigned: '', assign(url) { this.assigned = url; } } };
	vm.runInNewContext(source, { document, window: browserWindow, Array, Promise });

	videos[0].muted = false;
	videos[0].volume = 0.35;
	assert.equal(videos[1].muted, false);
	assert.equal(videos[1].volume, 0.35);

	stages[0].listeners.pointerenter();
	await Promise.resolve();
	stages[1].listeners.pointerenter();
	await Promise.resolve();
	assert.equal(videos[0].paused, true);
	assert.equal(videos[1].paused, false);
	assert.equal(videos[1].muted, false);
	assert.equal(videos[1].volume, 0.35);
	let prevented = false;
	externalLink.listeners.click({ preventDefault() { prevented = true; } });
	assert.equal(prevented, true);
	assert.equal(dialog.open, true);
	assert.equal(dialog.dataset.lifUrl, externalLink.href);
	assert.equal(browserWindow.opened, '');
	confirmButton.listeners.click();
	assert.equal(browserWindow.opened, externalLink.href);
	console.log('frontend audio synchronization and external confirmation OK');
})();
