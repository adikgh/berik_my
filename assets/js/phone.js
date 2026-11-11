// Phone mask 8 (700) 000-00-00, the same as the other Berik sites. Also show/hide password.
(function () {
	function fmt(v) {
		var d = v.replace(/\D/g, '');
		// the leading 8 of the mask, or a pasted / autofilled +7 or 8
		if (d[0] === '8' || (d[0] === '7' && d.length > 10)) d = d.slice(1);
		d = d.slice(0, 10);
		var o = '';
		if (d.length) o = '8 (' + d.slice(0, 3);
		if (d.length >= 3) o += ') ' + d.slice(3, 6);
		if (d.length >= 6) o += '-' + d.slice(6, 8);
		if (d.length >= 8) o += '-' + d.slice(8, 10);
		return o;
	}
	function check(el) {
		var n = el.value.replace(/\D/g, '').slice(1).length; // without the leading 8
		el.setCustomValidity(n === 0 && !el.required ? '' : (n === 10 ? '' : 'Нөмірді толық жазыңыз: 10 сан'));
	}
	document.querySelectorAll('.js-phone').forEach(function (el) {
		el.value = fmt(el.value);
		el.addEventListener('input', function () {
			var end = el.selectionStart === el.value.length;
			el.value = fmt(el.value);
			if (end) el.setSelectionRange(el.value.length, el.value.length);
			check(el);
		});
		el.addEventListener('blur', function () { check(el); });
	});
	document.querySelectorAll('.pw-eye').forEach(function (b) {
		b.addEventListener('click', function () {
			var i = b.parentNode.querySelector('input');
			var show = i.type === 'password';
			i.type = show ? 'text' : 'password';
			b.classList.toggle('on', show);
			b.setAttribute('aria-label', show ? 'Парольді жасыру' : 'Парольді көрсету');
			i.focus();
		});
	});
})();
