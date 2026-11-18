/**
 * Fmtable : filtrage en direct d'un tableau de fiches (recherche, filtres par caractéristique, tri).
 *
 * Chaque filtre a un type :
 *   scalar  une valeur par fiche : plusieurs valeurs choisies = OU
 *   list    plusieurs valeurs par fiche : plusieurs valeurs choisies = ET (la fiche doit toutes les avoir)
 *   bool    oui / non
 *   num     nombre : au moins (>=), au plus (<=) ou égal (=)
 */
jQuery(function () {
    'use strict';

    var L = (window.LANG && LANG.plugins && LANG.plugins.fmtable) || {};
    function t(key, dflt) { return L[key] || dflt; }

    jQuery('.plugin_fmtable').each(function () {
        var $box = jQuery(this);
        var filters = [];
        try { filters = JSON.parse($box.attr('data-filters') || '[]'); } catch (e) { filters = []; }
        var $table = $box.find('table.fmt-table');
        var $rows = $table.find('tbody tr');
        var total = $rows.length;
        var state = {q: '', f: {}};   // f[k] = {vals: [], op: '>=', num: ''}

        // données des lignes, lues une fois
        var data = $rows.map(function () {
            var f = {};
            try { f = JSON.parse(jQuery(this).attr('data-f') || '{}'); } catch (e) { f = {}; }
            return {f: f, q: jQuery(this).attr('data-q') || ''};
        }).get();

        filters.forEach(function (fl) { state.f[fl.k] = {vals: [], op: '>=', num: ''}; });

        // ---- barre de filtres ------------------------------------------------------------------
        var $bar = jQuery('<div class="fmt-bar"></div>');
        var $search = jQuery('<input type="search" class="edit fmt-search">')
            .attr('placeholder', t('search', 'Rechercher…'));
        $bar.append(jQuery('<div class="fmt-filter fmt-searchbox"></div>').append($search));
        var $groups = {};

        function countFor(fl, v) {
            var n = 0;
            data.forEach(function (d) { if ((d.f[fl.k] || []).indexOf(v.toLowerCase()) !== -1) n++; });
            return n;
        }

        filters.forEach(function (fl) {
            var $g = jQuery('<div class="fmt-filter"></div>').attr('data-k', fl.k);
            $g.append(jQuery('<span class="fmt-label"></span>').text(fl.l));
            var $sel = jQuery('<select class="edit fmt-select"></select>');
            if (fl.t === 'bool') {
                $sel.append(jQuery('<option value="">' + t('all', '(tous)') + '</option>'));
                $sel.append(jQuery('<option value="true"></option>').text(t('yes', 'oui')));
                $sel.append(jQuery('<option value="false"></option>').text(t('no', 'non')));
            } else {
                $sel.append(jQuery('<option value=""></option>').text(fl.t === 'num' ? t('value', 'valeur…') : t('add', '+ choisir…')));
                fl.v.forEach(function (v) {
                    $sel.append(jQuery('<option></option>').attr('value', v.toLowerCase())
                        .text(v + ' (' + countFor(fl, v) + ')'));
                });
            }
            if (fl.t === 'num') {
                var $op = jQuery('<select class="edit fmt-op"><option value=">=">≥</option>' +
                    '<option value="<=">≤</option><option value="=">=</option></select>');
                $g.append($op);
                $op.on('change', function () { state.f[fl.k].op = $op.val(); apply(); });
            }
            $g.append($sel).append(jQuery('<span class="fmt-active"></span>'));
            $sel.on('change', function () {
                var v = $sel.val();
                var s = state.f[fl.k];
                if (fl.t === 'bool') { s.vals = v ? [v] : []; }
                else if (fl.t === 'num') { s.num = v; }
                else if (v && s.vals.indexOf(v) === -1) { s.vals.push(v); }
                if (fl.t !== 'bool') $sel.val('');
                apply();
            });
            $groups[fl.k] = $g;
            $bar.append($g);
        });

        var $reset = jQuery('<button type="button" class="button fmt-reset"></button>').text(t('reset', 'Réinitialiser'));
        $bar.append($reset);
        $box.prepend($bar);
        if (!filters.length) $bar.find('.fmt-reset').hide();

        // ---- application des filtres -----------------------------------------------------------
        function visible(d) {
            if (state.q && d.q.indexOf(state.q) === -1) return false;
            for (var i = 0; i < filters.length; i++) {
                var fl = filters[i], s = state.f[fl.k], have = d.f[fl.k] || [];
                if (fl.t === 'num') {
                    if (s.num === '') continue;
                    if (!have.length) return false;
                    var x = parseFloat(have[0]), y = parseFloat(s.num);
                    if (s.op === '>=' && !(x >= y)) return false;
                    if (s.op === '<=' && !(x <= y)) return false;
                    if (s.op === '=' && !(x === y)) return false;
                } else if (s.vals.length) {
                    if (fl.t === 'list') {
                        for (var j = 0; j < s.vals.length; j++) if (have.indexOf(s.vals[j]) === -1) return false;
                    } else {
                        var ok = false;
                        for (var k = 0; k < s.vals.length; k++) if (have.indexOf(s.vals[k]) !== -1) ok = true;
                        if (!ok) return false;
                    }
                }
            }
            return true;
        }

        function label(fl, v) {
            var found = v;
            (fl.v || []).forEach(function (x) { if (x.toLowerCase() === v) found = x; });
            return found;
        }

        function drawActive() {
            filters.forEach(function (fl) {
                var $a = $groups[fl.k].find('.fmt-active').empty();
                var s = state.f[fl.k];
                if (fl.t === 'bool') return;
                if (fl.t === 'num') return;
                s.vals.forEach(function (v) {
                    $a.append(jQuery('<span class="fmt-pill"></span>').text(label(fl, v) + ' ')
                        .append(jQuery('<a href="#" class="fmt-x" title="' + t('remove', 'Retirer') + '">✕</a>')
                            .on('click', function (e) {
                                e.preventDefault();
                                s.vals = s.vals.filter(function (z) { return z !== v; });
                                apply();
                            })));
                });
            });
        }

        function apply() {
            var shown = 0;
            $rows.each(function (i) {
                var show = visible(data[i]);
                jQuery(this).toggle(show);
                if (show) shown++;
            });
            $box.find('.fmt-count').text(shown + ' / ' + total);
            drawActive();
            filters.forEach(function (fl) {
                var s = state.f[fl.k];
                var on = fl.t === 'num' ? s.num !== '' : s.vals.length > 0;
                $groups[fl.k].toggleClass('fmt-on', on);
                if (fl.t === 'bool') $groups[fl.k].find('select').val(s.vals[0] || '');
            });
        }

        $search.on('input', function () { state.q = $search.val().toLowerCase().trim(); apply(); });
        $reset.on('click', function () {
            state.q = '';
            $search.val('');
            filters.forEach(function (fl) { state.f[fl.k] = {vals: [], op: '>=', num: ''}; $groups[fl.k].find('select').val(''); });
            $groups && filters.forEach(function (fl) { if (fl.t === 'num') $groups[fl.k].find('.fmt-op').val('>='); });
            apply();
        });

        // un clic sur une valeur du tableau la propose comme filtre
        $table.on('click', '[data-k][data-v]', function () {
            var k = jQuery(this).attr('data-k'), v = jQuery(this).attr('data-v');
            var fl = filters.filter(function (x) { return x.k === k; })[0];
            if (!fl) return;
            var s = state.f[k];
            if (fl.t === 'bool') s.vals = [v];
            else if (fl.t === 'num') s.num = v;
            else if (s.vals.indexOf(v) === -1) s.vals.push(v);
            apply();
        });

        // ---- tri -------------------------------------------------------------------------------
        var $ths = $table.find('thead th');
        $ths.css('cursor', 'pointer').attr('title', t('sort', 'Trier')).on('click', function () {
            var col = $ths.index(this);
            var asc = !jQuery(this).hasClass('fmt-asc');
            $ths.removeClass('fmt-asc fmt-desc');
            jQuery(this).addClass(asc ? 'fmt-asc' : 'fmt-desc');
            var order = $rows.map(function (i) {
                return {i: i, k: jQuery(this).children().eq(col).attr('data-s') || ''};
            }).get();
            order.sort(function (a, b) {
                if (a.k === '' && b.k !== '') return 1;       // sans valeur : toujours en dernier
                if (b.k === '' && a.k !== '') return -1;
                var c = a.k < b.k ? -1 : (a.k > b.k ? 1 : 0);
                return asc ? c : -c;
            });
            var $body = $table.find('tbody');
            var rowsNow = $rows.get(), dataNow = data.slice();
            var newRows = [], newData = [];
            order.forEach(function (o) { newRows.push(rowsNow[o.i]); newData.push(dataNow[o.i]); });
            $body.append(newRows);
            $rows = $table.find('tbody tr');
            data = newData;
        });

        apply();
    });
});
