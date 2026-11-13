(function () {
	'use strict';

	var canvases = document.querySelectorAll('canvas#hp-particles, canvas#s247-particles, canvas.portal-welcome-particles');
	if (!canvases.length) {
		return;
	}

	var states = [];
	var particleCount = 200;
	var repulsionRadius = 120;
	var maxRepulsion = 24;
	var pointer = null;
	var frameId = null;
	var lastFrameTime = 0;
	var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
	var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
	var reducedMotion = prefersReducedMotion.matches;
	var canRepel = finePointer.matches && !reducedMotion;
	var isPageVisible = !document.hidden;

	function resizeState(state) {
		var rect = state.canvas.getBoundingClientRect();
		var width = Math.max(0, Math.round(rect.width));
		var height = Math.max(0, Math.round(rect.height));
		if (!width || !height || (width === state.width && height === state.height)) {
			return;
		}

		if (state.width && state.height) {
			var scaleX = width / state.width;
			var scaleY = height / state.height;
			state.particles.forEach(function (particle) {
				particle.x *= scaleX;
				particle.y *= scaleY;
				particle.offsetX *= scaleX;
				particle.offsetY *= scaleY;
			});
		} else {
			for (var i = 0; i < particleCount; i++) {
				state.particles.push({
					x: Math.random() * width,
					y: Math.random() * height,
					r: Math.random() * 2 + 0.5,
					dx: (Math.random() - 0.5) * 0.4,
					dy: (Math.random() - 0.5) * 0.4,
					alpha: Math.random() * 0.5 + 0.1,
					angle: Math.random() * Math.PI * 2,
					offsetX: 0,
					offsetY: 0,
					velocityX: 0,
					velocityY: 0
				});
			}
		}

		state.width = width;
		state.height = height;
		state.canvas.width = width;
		state.canvas.height = height;
		if (reducedMotion) {
			renderState(state, 0);
		}
	}

	function renderState(state, frameScale) {
		var ctx = state.context;
		var rect = state.canvas.getBoundingClientRect();
		var mouseX = pointer ? pointer.x - rect.left : -repulsionRadius * 2;
		var mouseY = pointer ? pointer.y - rect.top : -repulsionRadius * 2;
		var pointerInCanvas = pointer
			&& mouseX >= 0 && mouseX <= state.width
			&& mouseY >= 0 && mouseY <= state.height
			&& canRepel;

		ctx.clearRect(0, 0, state.width, state.height);
		state.particles.forEach(function (particle) {
			var targetX = 0;
			var targetY = 0;
			if (pointerInCanvas) {
				var dx = particle.x + particle.offsetX - mouseX;
				var dy = particle.y + particle.offsetY - mouseY;
				var distance = Math.sqrt(dx * dx + dy * dy);
				if (distance < repulsionRadius) {
					var angle = distance > 0 ? Math.atan2(dy, dx) : particle.angle;
					var strength = 1 - distance / repulsionRadius;
					var displacement = maxRepulsion * strength * strength;
					targetX = Math.cos(angle) * displacement;
					targetY = Math.sin(angle) * displacement;
				}
			}

			particle.velocityX = (particle.velocityX + (targetX - particle.offsetX) * 0.08 * frameScale)
				* Math.pow(0.72, frameScale);
			particle.velocityY = (particle.velocityY + (targetY - particle.offsetY) * 0.08 * frameScale)
				* Math.pow(0.72, frameScale);
			particle.offsetX += particle.velocityX * frameScale;
			particle.offsetY += particle.velocityY * frameScale;

			ctx.beginPath();
			ctx.arc(particle.x + particle.offsetX, particle.y + particle.offsetY, particle.r, 0, Math.PI * 2);
			ctx.fillStyle = 'rgba(120,160,255,' + particle.alpha + ')';
			ctx.fill();

			particle.x += particle.dx * frameScale;
			particle.y += particle.dy * frameScale;
			if (particle.x < 0) particle.x = state.width;
			if (particle.x > state.width) particle.x = 0;
			if (particle.y < 0) particle.y = state.height;
			if (particle.y > state.height) particle.y = 0;
		});
	}

	function drawFrame(timestamp) {
		frameId = null;
		if (!isPageVisible) {
			return;
		}

		var frameScale = lastFrameTime ? Math.min((timestamp - lastFrameTime) / (1000 / 60), 2) : 1;
		lastFrameTime = timestamp;
		states.forEach(function (state) {
			renderState(state, frameScale);
		});

		if (!reducedMotion) {
			frameId = window.requestAnimationFrame(drawFrame);
		}
	}

	function startAnimation() {
		if (isPageVisible && !reducedMotion && !frameId) {
			lastFrameTime = 0;
			frameId = window.requestAnimationFrame(drawFrame);
		}
	}

	function stopAnimation() {
		if (frameId) {
			window.cancelAnimationFrame(frameId);
			frameId = null;
		}
		lastFrameTime = 0;
	}

	function handleMotionPreferenceChange() {
		reducedMotion = prefersReducedMotion.matches;
		canRepel = finePointer.matches && !reducedMotion;
		if (reducedMotion) {
			states.forEach(function (state) {
				state.particles.forEach(function (particle) {
					particle.offsetX = 0;
					particle.offsetY = 0;
					particle.velocityX = 0;
					particle.velocityY = 0;
				});
			});
			stopAnimation();
			drawFrame(0);
		} else {
			startAnimation();
		}
	}

	Array.prototype.forEach.call(canvases, function (canvas) {
		if (canvas.__acpParticlesInitialized) {
			return;
		}

		var context = canvas.getContext('2d');
		if (!context) {
			return;
		}

		canvas.__acpParticlesInitialized = true;
		var state = {
			canvas: canvas,
			context: context,
			width: 0,
			height: 0,
			particles: []
		};
		states.push(state);
		resizeState(state);

		if (window.ResizeObserver) {
			var observer = new window.ResizeObserver(function () {
				resizeState(state);
			});
			observer.observe(canvas);
		} else {
			window.addEventListener('resize', function () {
				resizeState(state);
			});
		}
	});

	if (!states.length) {
		return;
	}

	if (canRepel) {
		window.addEventListener('pointermove', function (event) {
			if (event.pointerType === 'mouse') {
				pointer = { x: event.clientX, y: event.clientY };
			}
		}, { passive: true });
		window.addEventListener('pointerleave', function () {
			pointer = null;
		});
		window.addEventListener('blur', function () {
			pointer = null;
		});
	}

	document.addEventListener('visibilitychange', function () {
		isPageVisible = !document.hidden;
		if (isPageVisible) {
			if (reducedMotion) {
				drawFrame(0);
			} else {
				startAnimation();
			}
		} else {
			stopAnimation();
		}
	});

	if (prefersReducedMotion.addEventListener) {
		prefersReducedMotion.addEventListener('change', handleMotionPreferenceChange);
	}
	if (finePointer.addEventListener) {
		finePointer.addEventListener('change', function () {
			canRepel = finePointer.matches && !reducedMotion;
			if (!canRepel) pointer = null;
		});
	}

	if (reducedMotion) {
		drawFrame(0);
	} else {
		startAnimation();
	}
})();
