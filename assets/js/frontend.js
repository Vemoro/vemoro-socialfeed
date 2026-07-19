(function () {
	'use strict';
	document.documentElement.classList.add('lif-js');

	function initializeCarousels(root) { root.querySelectorAll('[data-lif-carousel]').forEach(function (carousel) {
		if (carousel.dataset.lifBound === '1') { return; } carousel.dataset.lifBound = '1';
		var track = carousel.querySelector('.lif-carousel__track'); var slides = carousel.querySelectorAll('.lif-carousel__slide'); var indicator = carousel.querySelector('.lif-carousel__indicator'); var index = 0;
		if (slides.length < 2) { return; }
		function show(next) { index = (next + slides.length) % slides.length; track.style.transform = 'translateX(-' + (index * 100) + '%)'; slides.forEach(function (slide, i) { slide.setAttribute('aria-hidden', i === index ? 'false' : 'true'); }); indicator.textContent = (index + 1) + '/' + slides.length; }
		carousel.querySelector('.lif-carousel__previous').addEventListener('click', function (event) { event.preventDefault(); show(index - 1); });
		carousel.querySelector('.lif-carousel__next').addEventListener('click', function (event) { event.preventDefault(); show(index + 1); });
	}); }
	initializeCarousels(document);

	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var activeVideo = null;
	var feedVideos = [];
	var sharedMuted = true;
	var sharedVolume = 1;
	var syncingVolume = false;
	function applySharedAudio(video) { if (!video) { return; } syncingVolume = true; video.volume = sharedVolume; video.muted = sharedMuted; syncingVolume = false; }
	function shareAudio(source) { if (syncingVolume) { return; } sharedMuted = source.muted; sharedVolume = source.volume; syncingVolume = true; feedVideos.forEach(function (video) { if (video !== source) { video.volume = sharedVolume; video.muted = sharedMuted; } }); syncingVolume = false; }
	function initializeVideoElements(root) { root.querySelectorAll('[data-lif-video]').forEach(function (video) { if (feedVideos.indexOf(video) !== -1) { return; } feedVideos.push(video); video.addEventListener('volumechange', function () { shareAudio(video); }); video.addEventListener('play', function () { if (activeVideo && activeVideo !== video) { pauseVideo(activeVideo); } markPlaying(video); }); video.addEventListener('contextmenu', function (event) { event.preventDefault(); }); applySharedAudio(video); }); }
	function pauseVideo(video) { if (!video) { return; } video.pause(); var stage = video.closest('[data-lif-video-stage]'); if (stage) { stage.classList.remove('is-playing'); var button = stage.querySelector('[data-lif-video-toggle]'); if (button) { button.setAttribute('aria-label', button.dataset.playLabel); } } if (activeVideo === video) { activeVideo = null; } }
	function markPlaying(video) { activeVideo = video; var stage = video.closest('[data-lif-video-stage]'); if (stage) { stage.classList.add('is-playing'); var button = stage.querySelector('[data-lif-video-toggle]'); if (button) { button.setAttribute('aria-label', button.dataset.pauseLabel); } } }
	function playVideo(video) { if (!video) { return; } if (activeVideo && activeVideo !== video) { pauseVideo(activeVideo); } applySharedAudio(video); var promise = video.play(); if (promise && promise.then) { promise.then(function () { markPlaying(video); }).catch(function () { pauseVideo(video); }); } else { markPlaying(video); } }
	function initializeVideoStages(root) { root.querySelectorAll('[data-lif-video-stage]').forEach(function (stage) {
		if (stage.dataset.lifBound === '1') { return; } stage.dataset.lifBound = '1';
		var video = stage.querySelector('[data-lif-video]'); var button = stage.querySelector('[data-lif-video-toggle]'); if (!video) { return; }
		if (!reducedMotion && stage.dataset.lifHoverAutoplay === '1') { stage.addEventListener('pointerenter', function () { playVideo(video); }); }
		if (button) { button.addEventListener('click', function () { if (video.paused) { playVideo(video); } else { pauseVideo(video); } }); }
	}); }
	initializeVideoElements(document); initializeVideoStages(document);

	function initializeCaptions(root) { root.querySelectorAll('[data-lif-caption][data-collapsible="1"]').forEach(function (caption) {
		if (caption.dataset.lifBound === '1') { return; } caption.dataset.lifBound = '1';
		var button = document.querySelector('[data-lif-caption-toggle][aria-controls="' + caption.id + '"]'); if (!button) { return; }
		caption.setAttribute('data-collapsed', 'true');
		if (caption.scrollHeight <= caption.clientHeight + 1) { caption.setAttribute('data-collapsed', 'false'); return; }
		button.hidden = false;
		button.addEventListener('click', function () { var collapsed = caption.getAttribute('data-collapsed') === 'true'; caption.setAttribute('data-collapsed', collapsed ? 'false' : 'true'); button.setAttribute('aria-expanded', collapsed ? 'true' : 'false'); button.textContent = collapsed ? button.dataset.lessLabel : button.dataset.moreLabel; });
	}); }
	initializeCaptions(document);

	function continueToExternal(url, target) { if ('_blank' === target) { window.open(url, '_blank', 'noopener,noreferrer'); } else { window.location.assign(url); } }
	document.querySelectorAll('[data-lif-external-dialog]').forEach(function (dialog) {
		var confirmButton = dialog.querySelector('[data-lif-external-confirm-button]'); var cancelButton = dialog.querySelector('[data-lif-external-cancel]');
		confirmButton.addEventListener('click', function () { var url = dialog.dataset.lifUrl; var target = dialog.dataset.lifTarget; dialog.close(); if (url) { continueToExternal(url, target); } });
		cancelButton.addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
	});
	function initializeExternalLinks(root) { root.querySelectorAll('[data-lif-external-confirm]').forEach(function (link) {
		if (link.dataset.lifBound === '1') { return; } link.dataset.lifBound = '1';
		link.addEventListener('click', function (event) {
			event.preventDefault(); var feed = link.closest('.lif-feed'); var dialog = feed ? feed.querySelector('[data-lif-external-dialog]') : null; var url = link.href; var target = link.target;
			if (dialog && typeof dialog.showModal === 'function') { dialog.dataset.lifUrl = url; dialog.dataset.lifTarget = target; dialog.showModal(); }
			else if (window.confirm(dialog ? dialog.querySelector('p').textContent : 'Instagram')) { continueToExternal(url, target); }
		});
	}); }
	initializeExternalLinks(document);

	var collapsibleFeeds = Array.prototype.slice.call(document.querySelectorAll('[data-lif-feed]'));
	function normalizeMediaRows(root) {
		var posts = Array.prototype.slice.call(root.querySelectorAll('.lif-post')); var rows = {};
		posts.forEach(function (post) { var stage = post.querySelector('.lif-post__stage'); if (stage && stage.style) { stage.style.removeProperty('height'); } });
		if (posts.length) { void posts[0].offsetHeight; }
		posts.forEach(function (post) { var stage = post.querySelector('.lif-post__stage'); if (!stage || !stage.style) { return; } var key = String(Math.round(post.offsetTop)); (rows[key] ||= []).push(stage); });
		Object.keys(rows).forEach(function (key) { var stages = rows[key]; if (stages.length < 2) { return; } var heights = stages.map(function (stage) { return stage.getBoundingClientRect ? stage.getBoundingClientRect().height : stage.offsetHeight; }); var max = Math.max.apply(Math, heights); var min = Math.min.apply(Math, heights); if (max - min <= 2) { return; } stages.forEach(function (stage) { stage.style.height = Math.round(max) + 'px'; }); });
	}
	function collapsedFeedHeight(feed) {
		var posts = Array.prototype.slice.call(feed.querySelectorAll('.lif-post')); if (posts.length < 2) { return 0; }
		var firstTop = posts[0].offsetTop; var secondRowPost = null;
		for (var i = 1; i < posts.length; i += 1) { if (posts[i].offsetTop > firstTop + 2) { secondRowPost = posts[i]; break; } }
		if (!secondRowPost) { return 0; }
		var stage = secondRowPost.querySelector('.lif-post__stage'); var stageHeight = stage ? stage.offsetHeight : secondRowPost.offsetHeight;
		return secondRowPost.offsetTop + Math.max(80, stageHeight * 0.25);
	}
	function updateStickyClose(feed) {
		var close = feed.querySelector('[data-lif-feed-close]'); if (!close || close.hidden || !feed.getBoundingClientRect) { return; }
		var rect = feed.getBoundingClientRect(); var viewportHeight = window.innerHeight || 0;
		close.classList.toggle('is-at-end', rect.bottom <= viewportHeight - 16); close.classList.toggle('is-outside', rect.bottom <= 0 || rect.top >= viewportHeight);
	}
	function expandFeed(feed, reveal) {
		var startHeight = feed.getBoundingClientRect ? feed.getBoundingClientRect().height : feed.offsetHeight;
		var deferred = feed.querySelector('[data-lif-feed-deferred]');
		if (deferred && deferred.content) { feed.insertBefore(deferred.content.cloneNode(true), deferred); deferred.remove(); initializeCarousels(feed); initializeVideoElements(feed); initializeVideoStages(feed); initializeCaptions(feed); initializeExternalLinks(feed); normalizeMediaRows(feed); }
		var close = feed.querySelector('[data-lif-feed-close]'); feed.dataset.lifExpanded = '1'; feed.style.maxHeight = Math.round(startHeight) + 'px'; feed.classList.remove('is-collapsed'); reveal.hidden = true; reveal.querySelector('[data-lif-feed-more]').setAttribute('aria-expanded', 'true'); if (close) { close.hidden = false; updateStickyClose(feed); }
		if (reducedMotion || !window.requestAnimationFrame) { feed.style.removeProperty('max-height'); feed.style.removeProperty('--lif-collapsed-height'); return; }
		feed.classList.add('is-expanding'); void feed.offsetHeight;
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				var targetHeight = feed.scrollHeight;
				var finish = function (event) { if (event && event.propertyName !== 'max-height') { return; } feed.classList.remove('is-expanding'); feed.style.removeProperty('max-height'); feed.style.removeProperty('--lif-collapsed-height'); feed.removeEventListener('transitionend', finish); };
				feed.addEventListener('transitionend', finish); feed.style.maxHeight = Math.max(Math.round(startHeight), targetHeight) + 'px';
			});
		});
	}
	function collapseFeed(feed, reveal, close) {
		var currentHeight = feed.getBoundingClientRect ? feed.getBoundingClientRect().height : feed.offsetHeight; var targetHeight = collapsedFeedHeight(feed); if (!targetHeight) { return; }
		close.hidden = true; close.classList.remove('is-at-end'); close.classList.remove('is-outside'); feed.dataset.lifExpanded = '0'; feed.style.maxHeight = Math.round(currentHeight) + 'px'; feed.style.setProperty('--lif-collapsed-height', Math.round(targetHeight) + 'px'); reveal.querySelector('[data-lif-feed-more]').setAttribute('aria-expanded', 'false');
		if (feed.scrollIntoView) { feed.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' }); }
		var finished = false; var fallbackTimer = 0;
		var finish = function (event) { if (event && event.propertyName !== 'max-height') { return; } if (finished) { return; } finished = true; if (fallbackTimer && window.clearTimeout) { window.clearTimeout(fallbackTimer); } Array.prototype.slice.call(feed.querySelectorAll('[data-lif-video]')).forEach(pauseVideo); feed.classList.remove('is-collapsing'); feed.classList.add('is-collapsed'); feed.style.removeProperty('max-height'); reveal.hidden = false; feed.removeEventListener('transitionend', finish); };
		if (reducedMotion || !window.requestAnimationFrame) { finish(); return; }
		feed.classList.add('is-collapsing'); void feed.offsetHeight;
		window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { feed.addEventListener('transitionend', finish); if (window.setTimeout) { fallbackTimer = window.setTimeout(finish, 2200); } feed.style.maxHeight = Math.round(targetHeight) + 'px'; }); });
	}
	function measureCollapsedFeed(feed) {
		if (feed.dataset.lifExpanded === '1') { return; }
		var reveal = feed.querySelector('[data-lif-feed-reveal]'); var height = collapsedFeedHeight(feed); if (!reveal || !height) { return; }
		feed.style.setProperty('--lif-collapsed-height', Math.round(height) + 'px'); feed.classList.add('is-collapsed'); reveal.hidden = false;
		if (reveal.dataset.lifBound !== '1') { reveal.dataset.lifBound = '1'; reveal.querySelector('[data-lif-feed-more]').addEventListener('click', function () { expandFeed(feed, reveal); }); }
		var close = feed.querySelector('[data-lif-feed-close]'); if (close && close.dataset.lifBound !== '1') { close.dataset.lifBound = '1'; close.querySelector('[data-lif-feed-close-button]').addEventListener('click', function () { collapseFeed(feed, reveal, close); }); }
	}
	collapsibleFeeds.forEach(function (feed) { normalizeMediaRows(feed); measureCollapsedFeed(feed); });
	if (collapsibleFeeds.length && window.addEventListener) { var resizeFrame = 0; var scrollFrame = 0; window.addEventListener('resize', function () { window.cancelAnimationFrame(resizeFrame); resizeFrame = window.requestAnimationFrame(function () { collapsibleFeeds.forEach(function (feed) { normalizeMediaRows(feed); measureCollapsedFeed(feed); updateStickyClose(feed); }); }); }); window.addEventListener('scroll', function () { window.cancelAnimationFrame(scrollFrame); scrollFrame = window.requestAnimationFrame(function () { collapsibleFeeds.forEach(updateStickyClose); }); }, { passive: true }); }
}());
