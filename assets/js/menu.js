// Menu: dish card, search, upsell and the cart (kept in this browser per company, sent to /menu.php?send=1)
(function () {
	var M = window.MENU, key = 'cart_' + M.cid, cart = {};
	try { cart = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { cart = {}; }

	var $ = function (s, el) { return (el || document).querySelector(s); };
	var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
	var money = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₸'; };
	var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

	var items = {}, order = [];
	$$('.mn_it').forEach(function (el) {
		var d = el.dataset;
		items[d.id] = { id: d.id, el: el, cat: d.cat, name: d.name, price: +d.price, desc: d.desc || '', img: d.img || '', ico: d.ico || '', h: d.h || 0 };
		order.push(d.id);
	});
	Object.keys(cart).forEach(function (id) { if (!items[id] || !(cart[id] > 0)) delete cart[id]; });

	var bar = $('.mn_bar'), sheet = $('.mn_sheet:not(.mn_done):not(.mn_card)'), done = $('.mn_done'), form = $('.mn_form');
	var card = $('.mn_card'), canBuy = !!form;

	function save() { try { localStorage.setItem(key, JSON.stringify(cart)); } catch (e) {} }
	function type() { var t = form.elements.type; return +(t.value || (t[0] && t.value)); }
	function food() { var s = 0; Object.keys(cart).forEach(function (id) { s += items[id].price * cart[id]; }); return s; }
	function dlv() {
		if (type() !== 1) return 0;
		var z = form.elements.zone;
		if (!z) return M.delivery;
		var o = z.options[z.selectedIndex];
		return o && o.dataset.price ? +o.dataset.price : 0;
	}

	// "Add something else?": cheaper dishes from other categories, one per category in turn
	function upsell(skip, n, cap) {
		if (!M.upsell) return [];
		if (M.upsell_ids && M.upsell_ids.length) // chosen in admin
			return M.upsell_ids.map(String).filter(function (id) { return items[id] && !cart[id] && !(cur && cur.id === id); }).map(function (id) { return items[id]; }).slice(0, n);
		var by = {}, cats = [];
		order.forEach(function (id) {
			var it = items[id];
			if (cart[id] || skip[it.cat] === true || (cap && it.price > cap)) return;
			if (!by[it.cat]) { by[it.cat] = []; cats.push(it.cat); }
			by[it.cat].push(it);
		});
		cats.forEach(function (c) { by[c].sort(function (a, b) { return a.price - b.price; }); });
		var out = [];
		for (var i = 0; out.length < n && cats.some(function (c) { return by[c][i]; }); i++)
			cats.forEach(function (c) { if (by[c][i] && out.length < n) out.push(by[c][i]); });
		return out;
	}
	function drawUp(box, list) {
		if (!box) return;
		box.hidden = !canBuy || !list.length;
		$('.mn_up_list', box).innerHTML = list.map(function (it) {
			return '<div class="mn_up_it" data-id="' + it.id + '">' +
				(it.ico ? '<div class="mn_ph" style="--h:' + (+it.h) + '"><em aria-hidden="true">' + esc(it.ico) + '</em>' +
					(it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '') + '</div>'
				: (it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy" onerror="this.remove()">' : '')) +
				'<b>' + esc(it.name) + '</b><button type="button" class="mn_up_add">+ ' + money(it.price) + '</button></div>';
		}).join('');
	}

	function draw() {
		var n = 0;
		Object.keys(items).forEach(function (id) {
			var q = cart[id] || 0; n += q;
			var it = items[id].el;
			it.classList.toggle('on', q > 0);
			var i = $('.mn_q i', it); if (i) i.textContent = q;
		});
		$$('.mn_ncnt').forEach(function (el) { el.textContent = n; el.hidden = !n; });
		if (!canBuy) return;
		bar.hidden = !n;
		$('.mn_cnt', bar).textContent = n;
		$('.mn_sum', bar).textContent = money(food());

		$('.mn_lines').innerHTML = Object.keys(cart).map(function (id) {
			var it = items[id];
			return '<li data-id="' + id + '"><span>' + esc(it.name) + '</span>' +
				'<span class="mn_q"><button type="button" class="mn_minus">−</button><i>' + cart[id] + '</i><button type="button" class="mn_plus">+</button></span>' +
				'<span>' + money(it.price * cart[id]) + '</span></li>';
		}).join('') || '<li class="muted">Себет бос</li>';
		if (!sheet.hidden) {
			var inCats = {};
			Object.keys(cart).forEach(function (id) { inCats[items[id].cat] = true; });
			drawUp($('.mn_up_cart'), upsell(inCats, 6, 2500));
		}

		var d = type() === 1;
		$$('.mn_dlv', form).forEach(function (el) { el.hidden = !d; });
		if (form.elements.address) form.elements.address.required = d;
		if (form.elements.zone) form.elements.zone.required = d;
		var ds = dlv();
		$('.mn_dsum').textContent = form.elements.zone && !form.elements.zone.value ? 'аймақты таңдаңыз' : money(ds);
		$('.mn_total').textContent = money(food() + ds);
		if (!n && !sheet.hidden) close();
	}

	function add(id, d) {
		cart[id] = Math.max(0, Math.min(99, (cart[id] || 0) + d));
		if (!cart[id]) delete cart[id];
		save(); draw();
	}

	// Dish card; its link is ?p=<id> so it can be shared
	var cur = null, cq = 1, pushed = false;
	function url(id) {
		var u = new URL(location.href);
		if (id) u.searchParams.set('p', id); else u.searchParams.delete('p');
		return u.pathname + u.search + u.hash;
	}
	function drawCard() {
		if (!canBuy || !cur) return;
		$('.mn_c_q i', card).textContent = cq;
		$('.mn_c_add', card).textContent = (cart[cur.id] ? 'Тағы қосу · ' : 'Қосу · ') + money(cur.price * cq);
	}
	function openCard(id, push) {
		cur = items[id]; if (!cur) return;
		cq = 1;
		var img = $('.mn_c_img', card);
		img.hidden = !cur.img;
		if (cur.img) img.src = cur.img; else img.removeAttribute('src');
		var ph = $('.mn_c_ph', card); // templates: emoji tile when there is no photo
		if (ph) { ph.hidden = !!cur.img; ph.style.setProperty('--h', +cur.h); $('em', ph).textContent = cur.ico; img.onerror = function () { img.hidden = true; ph.hidden = false; }; }
		$('.mn_c_name', card).textContent = cur.name;
		$('.mn_c_desc', card).textContent = cur.desc;
		$('.mn_c_desc', card).hidden = !cur.desc;
		$('.mn_c_pr', card).textContent = money(cur.price);
		var skip = {}; skip[cur.cat] = true;
		drawUp($('.mn_up', card), upsell(skip, 6, Math.max(1500, Math.round(cur.price / 2))));
		drawCard();
		card.hidden = false;
		$('.mn_box', card).scrollTop = 0;
		document.body.classList.add('mn_lock');
		if (push !== false) { history.pushState({ p: id }, '', url(id)); pushed = true; }
	}
	function closeCard(back) {
		if (card.hidden) return;
		card.hidden = true; cur = null;
		if (!sheet || sheet.hidden) document.body.classList.remove('mn_lock');
		if (back === false) pushed = false;
		else if (pushed) { pushed = false; history.back(); }
		else history.replaceState(null, '', url(0));
	}
	window.addEventListener('popstate', function () {
		var p = new URL(location.href).searchParams.get('p');
		if (p && items[p]) openCard(p, false); else closeCard(false);
	});
	card.addEventListener('click', function (e) {
		if (e.target === card || e.target.closest('.mn_x')) { closeCard(); return; }
		if (e.target.closest('.mn_c_plus')) { cq = Math.min(99, cq + 1); drawCard(); return; }
		if (e.target.closest('.mn_c_minus')) { cq = Math.max(1, cq - 1); drawCard(); return; }
		if (e.target.closest('.mn_c_add')) { add(cur.id, cq); closeCard(); }
	});

	document.addEventListener('click', function (e) {
		var u = e.target.closest('.mn_up_it');
		if (u) {
			if (e.target.closest('.mn_up_add')) {
				add(u.dataset.id, 1);
				u.classList.add('ok');
				var b = $('.mn_up_add', u); b.textContent = '✓ Қосылды'; b.disabled = true;
			} else if (!card.contains(u) && M.card !== 0) { openCard(u.dataset.id); }
			return;
		}
		if (card.contains(e.target)) return;
		var b2 = e.target.closest('.mn_plus, .mn_minus');
		if (b2) {
			var row = b2.closest('[data-id]');
			add(row.dataset.id, b2.classList.contains('mn_plus') ? 1 : -1);
			return;
		}
		var it = e.target.closest('.mn_it');
		if (it && M.card !== 0) openCard(it.dataset.id); // tap on a dish opens its card
		else if (it && canBuy && !it.classList.contains('on')) add(it.dataset.id, 1);
	});

	// Search by name and description
	var q = $('#mn_q');
	if (q) q.addEventListener('input', function () {
		var s = q.value.trim().toLowerCase(), any = false;
		$$('.mn_sec').forEach(function (sec) {
			var vis = 0;
			$$('.mn_it', sec).forEach(function (el) {
				var it = items[el.dataset.id];
				var ok = !s || (it.name + ' ' + it.desc).toLowerCase().indexOf(s) >= 0;
				el.hidden = !ok; if (ok) vis++;
			});
			sec.hidden = !vis; if (vis) any = true;
		});
		$('#cats').hidden = !!s;
		$('.mn_none').hidden = any;
	});

	if (canBuy) {
		var open = function () {
			sheet.hidden = false; document.body.classList.add('mn_lock'); draw();
		};
		var close = function () { sheet.hidden = true; document.body.classList.remove('mn_lock'); };
		bar.addEventListener('click', open);
		// Template top bar and bottom menu open the cart too; an empty cart just nudges
		$$('.mn_tcart, .mn_nv[data-go="cart"]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.preventDefault();
				if (Object.keys(cart).length) open();
				else { b.classList.remove('mn_nudge'); void b.offsetWidth; b.classList.add('mn_nudge'); }
			});
		});
		$('.mn_close', sheet).addEventListener('click', close);
		sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
		form.addEventListener('change', draw);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var err = $('.mn_err'), btn = $('button[type=submit]', form);
			err.hidden = true;
			if (type() === 1 && M.min && food() < M.min) { err.textContent = 'Жеткізу үшін ең аз сома: ' + money(M.min); err.hidden = false; return; }
			var d = new FormData(form);
			d.append('csrf', M.csrf);
			Object.keys(cart).forEach(function (id) { d.append('items[' + id + ']', cart[id]); });
			btn.disabled = true;
			fetch('/menu.php?send=1', { method: 'POST', body: d, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (r) {
					btn.disabled = false;
					if (!r.ok) { err.textContent = r.msg || 'Қате'; err.hidden = false; return; }
					cart = {}; save(); draw(); close();
					$('.mn_num', done).textContent = '№' + r.num;
					$('.mn_link', done).href = r.url;
					done.hidden = false;
				})
				.catch(function () { btn.disabled = false; err.textContent = 'Байланыс қатесі, қайта көріңіз.'; err.hidden = false; });
		});
	}

	// Category chips follow the scroll
	var chips = $$('.mn_cats a');
	if ('IntersectionObserver' in window && chips.length) {
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (en) {
				if (!en.isIntersecting) return;
				chips.forEach(function (a) {
					var on = a.getAttribute('href') === '#' + en.target.id;
					a.classList.toggle('on', on);
					if (on) a.scrollIntoView({ block: 'nearest', inline: 'center' });
				});
			});
		}, { rootMargin: '-45% 0px -50% 0px' });
		$$('.mn_sec').forEach(function (s) { io.observe(s); });
	}

	// Template bottom menu: back to the top, search
	$$('.mn_nv[data-go="top"]').forEach(function (b) {
		b.addEventListener('click', function (e) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
	});
	$$('.mn_nv[data-go="find"]').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.preventDefault();
			if (!q) return;
			q.scrollIntoView({ block: 'center', behavior: 'smooth' });
			setTimeout(function () { q.focus(); }, 250);
		});
	});

	// List / grid switch next to the search, remembered in this browser
	var vb = $('.mn_view'), vkey = 'mn_view_' + M.cid;
	var setView = function (g) { $$('.mn_grid').forEach(function (el) { el.classList.toggle('mn_tiles', g); }); if (vb) vb.classList.toggle('on', g); };
	if (vb) {
		try { var v0 = localStorage.getItem(vkey); if (v0) setView(v0 === 'grid'); else vb.classList.toggle('on', !!$('.mn_tiles')); } catch (e) {}
		vb.addEventListener('click', function () {
			var g = !$('.mn_grid.mn_tiles'); setView(g);
			try { localStorage.setItem(vkey, g ? 'grid' : 'list'); } catch (e) {}
		});
	}

	// "All" chip is on while the page is at the top
	var all = $('.mn_all');
	if (all) {
		var allOn = function () {
			if (window.scrollY > 60) return;
			chips.forEach(function (a) { a.classList.toggle('on', a === all); });
			if (cbar0) cbar0.scrollTo({ left: 0 });
		};
		var cbar0 = $('#cats');
		window.addEventListener('scroll', allOn, { passive: true });
		all.addEventListener('click', function (e) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
		allOn();
	}

	// Arc categories (a template sets --arc: 1 on .mn_cats): each chip gets --a, 0 in the middle .. 1 at the edges
	var cbar = $('#cats');
	if (cbar && chips.length && getComputedStyle(cbar).getPropertyValue('--arc').trim() === '1') {
		var arcOn = false;
		var arc = function () {
			arcOn = false;
			var r = cbar.getBoundingClientRect(), mid = r.left + r.width / 2, half = r.width / 2 || 1;
			chips.forEach(function (a) {
				var b = a.getBoundingClientRect(), d = Math.min(1, Math.abs(b.left + b.width / 2 - mid) / half);
				a.style.setProperty('--a', d.toFixed(3));
			});
		};
		var arcLater = function () { if (!arcOn) { arcOn = true; requestAnimationFrame(arc); } };
		cbar.addEventListener('scroll', arcLater, { passive: true });
		window.addEventListener('resize', arcLater);
		if (!$('a.on', cbar)) chips[0].classList.add('on');
		arc();
	}

	draw();
	var p0 = new URL(location.href).searchParams.get('p');
	if (p0 && items[p0] && M.card !== 0) openCard(p0, false);
})();
