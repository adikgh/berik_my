// Menu cart: kept in this browser per company, sent to /menu.php?send=1
(function () {
	var M = window.MENU, key = 'cart_' + M.cid, cart = {};
	try { cart = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { cart = {}; }

	var $ = function (s, el) { return (el || document).querySelector(s); };
	var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
	var money = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₸'; };
	var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

	var items = {};
	$$('.mn_it').forEach(function (el) { items[el.dataset.id] = { el: el, name: el.dataset.name, price: +el.dataset.price }; });
	Object.keys(cart).forEach(function (id) { if (!items[id] || !(cart[id] > 0)) delete cart[id]; });

	var bar = $('.mn_bar'), sheet = $('.mn_sheet:not(.mn_done)'), done = $('.mn_done'), form = $('.mn_form');

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

	function draw() {
		var n = 0;
		Object.keys(items).forEach(function (id) {
			var q = cart[id] || 0; n += q;
			var it = items[id].el;
			it.classList.toggle('on', q > 0);
			var i = $('.mn_q i', it); if (i) i.textContent = q;
		});
		bar.hidden = !n;
		$('.mn_cnt', bar).textContent = n;
		$('.mn_sum', bar).textContent = money(food());

		$('.mn_lines').innerHTML = Object.keys(cart).map(function (id) {
			var it = items[id];
			return '<li data-id="' + id + '"><span>' + esc(it.name) + ' <small>× ' + cart[id] + '</small></span>' +
				'<span class="mn_q"><button type="button" class="mn_minus">−</button><i>' + cart[id] + '</i><button type="button" class="mn_plus">+</button></span>' +
				'<span>' + money(it.price * cart[id]) + '</span></li>';
		}).join('') || '<li class="muted">Себет бос</li>';

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

	document.addEventListener('click', function (e) {
		var b = e.target.closest('.mn_plus, .mn_minus');
		if (b) {
			var row = b.closest('[data-id]');
			add(row.dataset.id, b.classList.contains('mn_plus') ? 1 : -1);
			return;
		}
		var it = e.target.closest('.mn_it');
		if (it && !it.classList.contains('on')) add(it.dataset.id, 1); // tap on a dish adds it
	});

	function open() { sheet.hidden = false; document.body.classList.add('mn_lock'); }
	function close() { sheet.hidden = true; document.body.classList.remove('mn_lock'); }
	bar.addEventListener('click', open);
	$('.mn_close').addEventListener('click', close);
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

	draw();
})();
