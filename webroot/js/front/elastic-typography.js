(function () {
	'use strict';

	var textTargets = document.querySelectorAll('.hp-hero-left .hp-tagline-org, .hp-hero-left .hp-headline');
	var badgeTargets = document.querySelectorAll('.hp-hero-left .hp-badge');
	var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
	var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
	var radius = 90;
	var maximumScale = 1.08;
	var maximumDisplacement = 5;
	var spring = 220;
	var damping = 28;
	var pointer = null;
	var characters = [];
	var frameId = null;
	var previousTime = 0;

	if ((!textTargets.length && !badgeTargets.length) || !finePointer.matches || reducedMotion.matches) {
		return;
	}

	function wrapTextNode(textNode) {
		var fragment = document.createDocumentFragment();
		var parts = textNode.nodeValue.match(/\s+|[^\s]+/g) || [];

		parts.forEach(function (part) {
			if (/^\s+$/.test(part)) {
				fragment.appendChild(document.createTextNode(part));
				return;
			}

			var word = document.createElement('span');
			word.className = 'hp-elastic-word';
			Array.from(part).forEach(function (character) {
				var span = document.createElement('span');
				span.className = 'hp-elastic-char';
				span.textContent = character;
				word.appendChild(span);
				characters.push({
					element: span,
					x: 0,
					y: 0,
					scale: 1,
					velocityX: 0,
					velocityY: 0,
					velocityScale: 0
				});
			});
			fragment.appendChild(word);
		});

		textNode.parentNode.replaceChild(fragment, textNode);
	}

	function getAccessibleText(element) {
		var text = '';

		function collect(node) {
			if (node.nodeType === Node.TEXT_NODE) {
				text += node.nodeValue;
			} else if (node.nodeType === Node.ELEMENT_NODE) {
				if (node.tagName === 'BR') {
					text += ' ';
				} else {
					Array.prototype.forEach.call(node.childNodes, collect);
				}
			}
		}

		collect(element);
		return text.replace(/\s+/g, ' ').trim();
	}

	function wrapTargetText(target, textNodes) {
		target.setAttribute('aria-label', getAccessibleText(target));
		textNodes.forEach(wrapTextNode);
	}

	Array.prototype.forEach.call(textTargets, function (target) {
		var walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
		var textNodes = [];
		while (walker.nextNode()) {
			textNodes.push(walker.currentNode);
		}
		wrapTargetText(target, textNodes);
	});

	Array.prototype.forEach.call(badgeTargets, function (badge) {
		var textNodes = Array.prototype.filter.call(badge.childNodes, function (node) {
			return node.nodeType === Node.TEXT_NODE;
		});
		var labelText = textNodes.map(function (node) {
			return node.nodeValue;
		}).join('').trim();

		if (!labelText) {
			return;
		}

		var label = document.createElement('span');
		label.className = 'hp-badge-label';
		label.textContent = labelText;
		badge.insertBefore(label, textNodes[0]);
		textNodes.forEach(function (node) {
			badge.removeChild(node);
		});

		var walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
		var labelTextNodes = [];
		while (walker.nextNode()) {
			labelTextNodes.push(walker.currentNode);
		}
		wrapTargetText(badge, labelTextNodes);
	});

	function scheduleFrame() {
		if (!frameId && !document.hidden) {
			frameId = window.requestAnimationFrame(update);
		}
	}

	function update(timestamp) {
		frameId = null;
		if (document.hidden) {
			return;
		}

		var delta = previousTime ? Math.min((timestamp - previousTime) / 1000, 0.032) : 1 / 60;
		previousTime = timestamp;
		var hasMotion = false;

		characters.forEach(function (character) {
			var rect = character.element.getBoundingClientRect();
			var centerX = rect.left + rect.width / 2 - character.x;
			var centerY = rect.top + rect.height / 2 - character.y;
			var distanceX = pointer ? centerX - pointer.x : 0;
			var distanceY = pointer ? centerY - pointer.y : 0;
			var distance = Math.sqrt(distanceX * distanceX + distanceY * distanceY);
			var proximity = pointer && distance < radius ? 1 - distance / radius : 0;
			proximity = proximity * proximity * (3 - 2 * proximity);

			var targetX = distance > 0 ? (distanceX / distance) * maximumDisplacement * proximity : 0;
			var targetY = distance > 0 ? (distanceY / distance) * maximumDisplacement * proximity : 0;
			var targetScale = 1 + (maximumScale - 1) * proximity;
			var forceX = (targetX - character.x) * spring;
			var forceY = (targetY - character.y) * spring;
			var forceScale = (targetScale - character.scale) * spring;

			character.velocityX += (forceX - damping * character.velocityX) * delta;
			character.velocityY += (forceY - damping * character.velocityY) * delta;
			character.velocityScale += (forceScale - damping * character.velocityScale) * delta;
			character.x += character.velocityX * delta;
			character.y += character.velocityY * delta;
			character.scale += character.velocityScale * delta;

			var displacement = Math.sqrt(character.x * character.x + character.y * character.y);
			if (displacement > maximumDisplacement) {
				var ratio = maximumDisplacement / displacement;
				character.x *= ratio;
				character.y *= ratio;
				character.velocityX *= ratio;
				character.velocityY *= ratio;
			}
			if (character.scale > maximumScale) {
				character.scale = maximumScale;
				character.velocityScale = Math.min(0, character.velocityScale);
			} else if (character.scale < 1) {
				character.scale = 1;
				character.velocityScale = Math.max(0, character.velocityScale);
			}

			if (
				Math.abs(character.x) > 0.01 ||
				Math.abs(character.y) > 0.01 ||
				Math.abs(character.scale - 1) > 0.001 ||
				Math.abs(character.velocityX) > 0.01 ||
				Math.abs(character.velocityY) > 0.01 ||
				Math.abs(character.velocityScale) > 0.001
			) {
				character.element.style.willChange = 'transform';
				character.element.style.transform = 'translate3d(' +
					character.x.toFixed(2) + 'px, ' + character.y.toFixed(2) +
					'px, 0) scale(' + character.scale.toFixed(4) + ')';
				hasMotion = true;
			} else {
				character.x = 0;
				character.y = 0;
				character.scale = 1;
				character.velocityX = 0;
				character.velocityY = 0;
				character.velocityScale = 0;
				character.element.style.transform = '';
				character.element.style.willChange = '';
			}
		});

		if (hasMotion) {
			scheduleFrame();
		} else {
			previousTime = 0;
		}
	}

	window.addEventListener('pointermove', function (event) {
		if (event.pointerType === 'mouse') {
			pointer = { x: event.clientX, y: event.clientY };
			scheduleFrame();
		}
	}, { passive: true });

	window.addEventListener('pointerleave', function () {
		pointer = null;
		scheduleFrame();
	});

	window.addEventListener('blur', function () {
		pointer = null;
		scheduleFrame();
	});

	window.addEventListener('resize', scheduleFrame, { passive: true });
	window.addEventListener('scroll', scheduleFrame, { passive: true });

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			pointer = null;
			if (frameId) {
				window.cancelAnimationFrame(frameId);
				frameId = null;
			}
			previousTime = 0;
		} else {
			scheduleFrame();
		}
	});
})();
