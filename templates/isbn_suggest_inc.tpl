{* Suggest: Open Library editions for a row's ISBN box (suggest_isbn.php) - used by the author workbench; load_books.tpl still carries its own copy. Rows are <tr>s holding a checkbox, an input named isbn[...] (data-sheet = the catalogue's ISBN), a .isbn-suggest link with data-title/data-author and a .isbn-options div. *}
{literal}<script>
/* Suggest: the first few Open Library editions for a row's title and author. They are fetched for the first ten rows with no ISBN when the page
   opens, one after another (Open Library asks for gentle use), and by the row's own Suggest link. Picking one fills the ISBN box, and any ISBN in
   the box - picked or typed - ticks the row; an emptied box unticks it. */
(function () {
	var url = '{/literal}{$smarty.const.FISHEYEMEDIA_PKG_URL}{literal}suggest_isbn.php';
	function rowInput(el) { return el.closest('tr').querySelector('input[name^="isbn"]'); }
	function rowTick(el) { return el.closest('tr').querySelector('input[type="checkbox"]'); }
	function tickFor(input) { var box = rowTick(input); if (box) { box.checked = input.value.trim() !== ''; } }
	function suggest(link) {
		var box = link.parentNode.querySelector('.isbn-options');
		box.textContent = 'Asking Open Library...';
		return fetch(url + '?title=' + link.dataset.title + '&author=' + link.dataset.author, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				box.textContent = '';
				if (!data.matches || !data.matches.length) { box.textContent = 'No matches found.'; return; }
				data.matches.forEach(function (m) {
					var a = document.createElement('a');
					a.href = '#'; a.className = 'isbn-pick'; a.dataset.isbn = m.isbn;
					a.textContent = m.title + (m.authors ? ' - ' + m.authors : '') + (m.year ? ' (' + m.year + ')' : '') + '  ' + m.isbn;
					var d = document.createElement('div'); d.appendChild(a); box.appendChild(d);
				});
			})
			.catch(function () { box.textContent = 'Open Library did not answer.'; });
	}
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.isbn-suggest');
		var pick = e.target.closest('.isbn-pick');
		if (link) {
			e.preventDefault();
			suggest(link);
		} else if (pick) {
			e.preventDefault();
			var input = rowInput(pick);
			if (input) { input.value = pick.dataset.isbn; tickFor(input); }
		}
	});
	document.addEventListener('input', function (e) {
		if (e.target.matches && e.target.matches('input[name^="isbn"]')) {
			tickFor(e.target);
			var sheet = (e.target.dataset.sheet || '').replace(/[^0-9Xx]/g, ''), typed = e.target.value.replace(/[^0-9Xx]/g, '');
			var warn = e.target.parentNode.querySelector('.isbn-differs');
			if (!warn) { warn = document.createElement('span'); warn.className = 'isbn-differs help-block'; e.target.parentNode.appendChild(warn); }
			warn.textContent = sheet && typed && typed !== sheet ? 'Differs from the sheet (' + e.target.dataset.sheet + ') - this book will not be loaded.' : '';
		}
	});
	document.addEventListener('DOMContentLoaded', function () {
		var todo = Array.prototype.filter.call(document.querySelectorAll('.isbn-suggest'), function (l) { var i = rowInput(l); return i && i.value.trim() === ''; }).slice(0, 10);
		(function next() {
			var l = todo.shift();
			if (l) { suggest(l).then(function () { setTimeout(next, 400); }); }
		})();
	});
})();
</script>{/literal}
