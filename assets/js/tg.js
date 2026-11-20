/* ₸ белгісі негізгі саннан кіші (75%): мәтіндегі ₸ <span class="tg_sym">-ге оралады (2026-11-20, user) */
;(function(){
	var SKIP = /^(SCRIPT|STYLE|TEXTAREA|SELECT|OPTION|INPUT|NOSCRIPT|TITLE)$/;
	var TG = /\d\s?тг(?![а-яёәіңғүұқөһ])/i, TG_ALL = /(\d)\s?тг(?![а-яёәіңғүұқөһ])/gi;
	function wrap(root){
		if (!root || !document.createTreeWalker) return;
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), list = [], n;
		while ((n = w.nextNode())) {
			var p = n.parentNode;
			if (TG.test(n.nodeValue) && p && !SKIP.test(p.nodeName)) n.nodeValue = n.nodeValue.replace(TG_ALL, '$1 ₸');   /* «1500 тг» → «1500 ₸» (бір белгі) */
			if (n.nodeValue.indexOf('₸') < 0) continue;
			if (!p || SKIP.test(p.nodeName) || p.isContentEditable || (p.classList && p.classList.contains('tg_sym'))) continue;
			list.push(n);
		}
		list.forEach(function(t){
			var parts = t.nodeValue.split('₸'), f = document.createDocumentFragment();
			parts.forEach(function(s, i){
				if (i < parts.length - 1) s = s.replace(/[  ]+$/, '');   /* ₸ алдындағы бос орын алынады, аралықты .tg_sym margin береді */
				if (s) f.appendChild(document.createTextNode(s));
				if (i < parts.length - 1){ var sp = document.createElement('span'); sp.className = 'tg_sym'; sp.textContent = '₸'; f.appendChild(sp); }
			});
			t.parentNode.replaceChild(f, t);
		});
	}
	function start(){
		wrap(document.body);
		if (window.MutationObserver) new MutationObserver(function(ms){
			ms.forEach(function(m){
				if (m.type === 'characterData') { if (m.target.parentNode) wrap(m.target.parentNode); return; }
				m.addedNodes.forEach(function(a){ if (a.nodeType === 1) wrap(a); else if (a.nodeType === 3 && a.parentNode) wrap(a.parentNode); });
			});
		}).observe(document.body, { childList: true, subtree: true, characterData: true });
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
