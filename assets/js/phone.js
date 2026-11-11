// Phone mask: the same jQuery Mask as the other Berik sites, 8 (700) 000-00-00. Also show/hide password.
(function () {
	var M = '8 (700) 000-00-00';
	function check(el) {
		var n = el.value.replace(/\D/g, '').slice(1).length; // without the leading 8
		el.setCustomValidity(n === 0 && !el.required ? '' : (n === 10 ? '' : 'Нөмірді толық жазыңыз: 10 сан'));
	}
	document.querySelectorAll('.js-phone').forEach(function (el) {
		if (window.jQuery && jQuery.fn.mask) jQuery(el).mask(M);
		el.addEventListener('input', function () { check(el); });
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
