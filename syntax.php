<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Parsing\Handler;

/**
 * <fmtable> ... </fmtable> : tableau de fiches à la Dataview, filtrable en direct.
 * Voir helper.php pour la syntaxe de la requête.
 */
class syntax_plugin_fmtable extends SyntaxPlugin
{
    /** Au-delà, une colonne n'est plus proposée comme filtre automatique */
    protected const MAX_AUTO_VALUES = 25;

    /** @inheritdoc */
    public function getType()
    {
        return 'protected';
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 160;
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        $this->Lexer->addEntryPattern('<fmtable>(?=.*?</fmtable>)', $mode, 'plugin_fmtable');
    }

    /** @inheritdoc */
    public function postConnect()
    {
        $this->Lexer->addExitPattern('</fmtable>', 'plugin_fmtable');
    }

    /** @inheritdoc */
    public function handle($match, $state, $pos, Handler $handler)
    {
        return $state === DOKU_LEXER_UNMATCHED ? $match : false;
    }

    /** @inheritdoc */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml' || !is_string($data)) return false;
        if (!plugin_load('helper', 'fmeta')) {
            $renderer->doc .= '<div class="error">' . hsc(sprintf($this->getLang('need_fmeta'), 'fmtable')) . '</div>';
            return true;
        }
        $renderer->nocache();      // dépend de l'index des fiches et des droits de lecture
        $renderer->doc .= $this->html($data);
        return true;
    }

    // ---------------------------------------------------------------------------------------------

    protected function html(string $text): string
    {
        /** @var helper_plugin_fmtable $h */
        $h = $this->loadHelper('fmtable');
        /** @var helper_plugin_fmeta $fm */
        $fm = $this->loadHelper('fmeta');

        $q = $h->parse($text);
        if ($q['mode'] === 'TASK') return $this->taskHtml($h, $q, $h->runTasks($q));
        if ($q['mode'] === 'CALENDAR') return $this->calendarHtml($h, $q, $h->run($q));

        $rows = $h->run($q);
        if ($q['group'] !== null) {
            $groups = $h->groups($rows, $q);
            return $q['mode'] === 'LIST' ? $this->groupListHtml($h, $q, $groups) : $this->groupTableHtml($h, $q, $groups);
        }

        if ($q['mode'] === 'LIST') return $this->listHtml($h, $q, $rows);

        $cols = $q['cols'];
        if (!$cols) $cols = [['key' => 'status', 'label' => 'status'], ['key' => 'created', 'label' => 'created'],
            ['key' => 'tags', 'label' => 'tags']];

        // valeurs de chaque colonne : types et valeurs distinctes
        $info = [];
        foreach ($cols as $c) {
            $info[$c['key']] = $this->columnInfo($h, $rows, $c['key']);
        }

        // filtres : explicites (FILTERS) ou automatiques
        $filters = [];
        $wanted = $q['filters'];
        if ($wanted === null) {
            $wanted = [];
            foreach ($cols as $c) {
                $n = count($info[$c['key']]['values']);
                if ($n >= 2 && $n <= self::MAX_AUTO_VALUES && !($n === count($rows) && $n > 8)) $wanted[] = $c['key'];
            }
        }
        foreach ($wanted as $key) {
            $ci = $info[$key] ?? $this->columnInfo($h, $rows, $key);
            if (!$ci['values']) continue;
            $label = $key;
            foreach ($cols as $c) if ($c['key'] === $key) $label = $c['label'];
            $filters[] = ['k' => $key, 'l' => $label, 't' => $ci['type'], 'v' => $ci['values']];
        }
        $fkeys = array_column($filters, 'k');

        $out = '<div class="plugin_fmtable" data-filters="' . hsc(json_encode($filters, JSON_UNESCAPED_UNICODE)) . '">';
        $out .= '<div class="fmt-scroll"><table class="inline fmt-table"><thead><tr>';
        if (!$q['noid']) $out .= '<th data-k="__page">' . hsc($this->getLang('col_page')) . '</th>';
        foreach ($cols as $c) $out .= '<th data-k="' . hsc($c['key']) . '">' . hsc($c['label']) . '</th>';
        $out .= '</tr></thead><tbody>';

        foreach ($rows as $e) {
            $f = [];
            foreach ($filters as $fl) {
                $f[$fl['k']] = $this->filterValues($h, $e, $fl['k']);
            }
            $search = mb_strtolower($e['title'] . ' ' . json_encode($e['fields'] ?? [], JSON_UNESCAPED_UNICODE));
            $out .= '<tr data-f="' . hsc(json_encode($f, JSON_UNESCAPED_UNICODE)) . '" data-q="' . hsc($search) . '">';
            if (!$q['noid']) {
                $out .= '<td data-s="' . hsc(mb_strtolower($e['title'])) . '"><a href="' . hsc(wl($e['id'])) . '">'
                    . hsc($e['title']) . '</a></td>';
            }
            foreach ($cols as $c) {
                $raw = $h->columnValue($e, $c['key']);
                $out .= '<td data-s="' . hsc($this->sortKey($raw)) . '">'
                    . $this->cell($fm, $e, $c['key'], $raw, in_array($c['key'], $fkeys, true)) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        $out .= '<p class="fmt-count" data-total="' . count($rows) . '">' . count($rows) . ' / ' . count($rows) . '</p>';
        return $out . '</div>';
    }

    protected function listHtml(helper_plugin_fmtable $h, array $q, array $rows): string
    {
        if (!$rows) return '<p>' . hsc($this->getLang('none')) . '</p>';
        $out = '<ul class="plugin_fmtable_list">';
        foreach ($rows as $e) {
            $extra = '';
            if ($q['listexpr'] !== '') {
                $v = $this->free($h->columnValue($e, $q['listexpr']));
                if ($v !== '') $extra = ' : ' . $v;
            }
            $out .= '<li><a href="' . hsc(wl($e['id'])) . '">' . hsc($e['title']) . '</a>' . $extra . '</li>';
        }
        return $out . '</ul>';
    }

    // ---- regroupement (GROUP BY) -------------------------------------------------------------------

    /** Tableau d'un TABLE ... GROUP BY : une ligne par groupe, colonnes évaluées sur « rows » */
    protected function groupTableHtml(helper_plugin_fmtable $h, array $q, array $groups): string
    {
        if (!$groups) return '<p>' . hsc($this->getLang('none')) . '</p>';
        $cols = $q['cols'] ?: [['key' => 'rows.file.link', 'label' => $this->getLang('col_file')]];
        $gname = $q['group']['name'] !== '' ? $q['group']['name'] : $this->getLang('col_group');

        $out = '<div class="plugin_fmtable_static"><div class="fmt-scroll"><table class="inline fmt-table"><thead><tr>';
        if (!$q['noid']) $out .= '<th>' . hsc($gname) . '</th>';
        foreach ($cols as $c) $out .= '<th>' . hsc($c['label']) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach ($groups as $g) {
            $out .= '<tr>';
            if (!$q['noid']) $out .= '<td>' . $this->groupKey($g) . '</td>';
            foreach ($cols as $c) $out .= '<td>' . $this->free($h->groupExpr($g, $c['key'])) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div><p class="fmt-count">' . sprintf($this->getLang('groups_count'), count($groups)) . '</p></div>';
        return $out;
    }

    /** LIST ... GROUP BY : les groupes, avec leurs pages (ou « LIST expression » évaluée sur le groupe) */
    protected function groupListHtml(helper_plugin_fmtable $h, array $q, array $groups): string
    {
        if (!$groups) return '<p>' . hsc($this->getLang('none')) . '</p>';
        $out = '<ul class="plugin_fmtable_list">';
        foreach ($groups as $g) {
            $out .= '<li><strong>' . $this->groupKey($g) . '</strong>';
            if ($q['listexpr'] !== '') {
                $v = $this->free($h->groupExpr($g, $q['listexpr']));
                if ($v !== '') $out .= ' : ' . $v;
            } else {
                $out .= '<ul>';
                foreach ($g['rows'] as $e) {
                    $out .= '<li><a href="' . hsc(wl($e['id'])) . '">' . hsc($e['title']) . '</a></li>';
                }
                $out .= '</ul>';
            }
            $out .= '</li>';
        }
        return $out . '</ul>';
    }

    protected function groupKey(array $g): string
    {
        $k = $this->free($g['key']);
        return $k === '' ? '<em>' . hsc($this->getLang('null_group')) . '</em>' : $k;
    }

    // ---- calendrier (CALENDAR) ------------------------------------------------------------------------

    /**
     * Calendrier mensuel : les pages sont placées à la date du champ donné. Le mois se dessine en JavaScript
     * (script.js) à partir de data-events ; sans JavaScript, la liste des dates reste visible.
     */
    protected function calendarHtml(helper_plugin_fmtable $h, array $q, array $rows): string
    {
        $events = $h->calendarEvents($rows, $q['calfield']);
        $out = '<div class="plugin_fmcal" data-events="' . hsc(json_encode((object)$events, JSON_UNESCAPED_UNICODE)) . '">';
        $out .= '<ul class="fmcal-fallback">';
        foreach ($events as $day => $list) {
            $links = [];
            foreach ($list as $ev) $links[] = '<a href="' . hsc($ev['u']) . '">' . hsc($ev['t']) . '</a>';
            $out .= '<li><strong>' . hsc($day) . '</strong> : ' . implode(', ', $links) . '</li>';
        }
        if (!$events) $out .= '<li>' . hsc($this->getLang('none')) . '</li>';
        return $out . '</ul></div>';
    }

    // ---- tâches (TASK) ---------------------------------------------------------------------------------

    /** TASK : les tâches, regroupées par page (ou selon GROUP BY) */
    protected function taskHtml(helper_plugin_fmtable $h, array $q, array $rows): string
    {
        if (!$rows) return '<p>' . hsc($this->getLang('none_tasks')) . '</p>';

        $byPage = $q['group'] === null || strtolower($q['group']['expr']) === 'file.link';
        if ($byPage) {
            $q['group'] = ['expr' => 'file.link', 'name' => ''];
        }
        $groups = $h->groups($rows, $q);

        $out = '<div class="plugin_fmtable_static"><ul class="fmt-taskgroups">';
        foreach ($groups as $g) {
            $out .= '<li class="fmt-taskgroup"><span class="fmt-gkey">' . $this->groupKey($g) . '</span><ul class="fmt-tasks">';
            foreach ($g['rows'] as $e) {
                $t = $e['__task'];
                $out .= '<li class="fmt-task">' . $this->taskBox($t['status']) . ' <span class="fmt-ttext">'
                    . $this->inline($t['text']) . '</span>';
                if (!$byPage) {
                    $out .= ' <a class="fmt-tpage" href="' . hsc(wl($e['id'])) . '">' . hsc($e['title']) . '</a>';
                }
                $out .= '</li>';
            }
            $out .= '</ul></li>';
        }
        return $out . '</ul><p class="fmt-count">' . sprintf($this->getLang('tasks_count'), count($rows)) . '</p></div>';
    }

    /** Statuts de tâche du plugin tasklist (classe, symbole, libellé), pour une présentation identique */
    protected function taskStates(): array
    {
        static $states = null;
        if ($states === null) {
            $states = [' ' => ['todo', '', ''], 'x' => ['done', '✓', ''], '/' => ['half', '◐', ''], '-' => ['cancel', '–', '']];
            try {
                if (plugin_load('action', 'tasklist')) {
                    $states = (new ReflectionClassConstant('action_plugin_tasklist', 'STATES'))->getValue();
                }
            } catch (Throwable $t) {
                // on garde les statuts de base
            }
        }
        return $states;
    }

    /** Case d'une tâche (lecture seule : un clic ne modifie pas la page d'origine) */
    protected function taskBox(string $char): string
    {
        $states = $this->taskStates();
        $c = $char === 'X' ? 'x' : $char;
        [$cls, $glyph, $label] = $states[$c] ?? ['todo', '', ''];
        return '<span class="tl-box tl-' . hsc($cls) . ' fmt-tbox" data-state="' . hsc($c) . '" title="' . hsc($label)
            . '" role="img" aria-label="' . hsc($label) . '">' . $glyph . '</span>';
    }

    /** Texte d'une tâche : liens [[page|texte]] et [texte](adresse), **gras**, `code`, #tags */
    protected function inline(string $t): string
    {
        $s = hsc($t);
        $page = static function (string $target): string {
            $target = html_entity_decode($target, ENT_QUOTES);
            return wl(cleanID(preg_replace('/\.(?:md|txt)$/i', '', $target)));
        };
        $s = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/u', static function ($m) use ($page) {
            $label = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
            return '<a href="' . hsc($page($m[1])) . '">' . $label . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function ($m) use ($page) {
            $url = html_entity_decode($m[2], ENT_QUOTES);
            if (preg_match('#^https?://#i', $url)) return '<a class="urlextern" href="' . hsc($url) . '">' . $m[1] . '</a>';
            return '<a href="' . hsc($page($m[2])) . '">' . $m[1] . '</a>';
        }, $s);
        $s = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
        /** @var helper_plugin_fmeta $fm */
        $fm = $this->loadHelper('fmeta');
        return preg_replace_callback('/(?<![\p{L}\p{N}_&#\/="])#(\p{L}[\p{L}\p{N}_\/-]*)/u',
            static fn($m) => $fm->tagLink($m[1]), $s);
    }

    /** HTML d'une valeur quelconque : texte, lien de page, liste (éléments séparés par des virgules), booléen */
    protected function free($v): string
    {
        if ($v === null || $v === '' || $v === []) return '';
        /** @var helper_plugin_fmtable $h */
        $h = $this->loadHelper('fmtable');
        if ($h->isLink($v)) return '<a href="' . hsc(wl($v['__link'])) . '">' . hsc((string)$v['text']) . '</a>';
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $x) {
                $s = $this->free($x);
                if ($s !== '') $parts[] = $s;
            }
            return implode(', ', $parts);
        }
        if (is_bool($v)) return $v ? '✓' : '✗';
        if (is_float($v)) return hsc(rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
        return hsc((string)$v);
    }

    /** @return array{type:string,values:array} */
    protected function columnInfo(helper_plugin_fmtable $h, array $rows, string $key): array
    {
        $seen = [];
        $allBool = $allNum = true;
        $isList = false;
        $any = false;
        foreach ($rows as $e) {
            $raw = $h->columnValue($e, $key);
            if ($raw === null || $raw === '' || $raw === []) continue;
            foreach ($h->asList($raw) as $v) {
                $v = $h->plain($v);
                $any = true;
                $l = mb_strtolower($v);
                if ($l !== 'true' && $l !== 'false') $allBool = false;
                if (!is_numeric($v)) $allNum = false;
                $seen[$l] ??= $v;
            }
            if (is_array($raw) && !$h->isLink($raw)) $isList = true;
        }
        $type = !$any ? 'scalar' : ($allBool ? 'bool' : ($allNum && !$isList ? 'num' : ($isList ? 'list' : 'scalar')));
        $values = array_values($seen);
        if ($type === 'num') {
            usort($values, static fn($a, $b) => (float)$a <=> (float)$b);
        } elseif ($type !== 'bool') {
            natcasesort($values);
            $values = array_values($values);
        } else {
            $values = ['true', 'false'];
        }
        return ['type' => $type, 'values' => $values];
    }

    /** Valeurs normalisées (minuscules) d'une fiche pour un filtre */
    protected function filterValues(helper_plugin_fmtable $h, array $e, string $key): array
    {
        $raw = $h->columnValue($e, $key);
        if ($raw === null || $raw === '' || $raw === []) return [];
        $out = [];
        foreach ($h->asList($raw) as $v) {
            $v = $h->plain($v);
            if ($v !== '') $out[] = mb_strtolower($v);
        }
        return array_values(array_unique($out));
    }

    protected function sortKey($raw): string
    {
        if ($raw === null) return '';
        /** @var helper_plugin_fmtable $h */
        $h = $this->loadHelper('fmtable');
        $raw = $h->plain($raw);
        if (is_numeric($raw)) return sprintf('%020.6f', (float)$raw);
        return mb_strtolower($raw);
    }

    protected function cell(helper_plugin_fmeta $fm, array $e, string $key, $raw, bool $filterable): string
    {
        if ($raw === null || $raw === '' || $raw === []) return '';
        /** @var helper_plugin_fmtable $h */
        $h = $this->loadHelper('fmtable');
        if ($h->isLink($raw)) return $this->free($raw);
        $k = strtolower($key);
        if ($k === 'status' || $k === 'statut') return $fm->badge($e);
        if ($k === 'tags') return implode(' ', array_map([$fm, 'tagLink'], (array)$raw));
        if (is_array($raw)) {
            $chips = [];
            foreach ($raw as $v) {
                if ((string)$v === '') continue;
                $chips[] = '<span class="fmt-chip"' . ($filterable
                        ? ' data-k="' . hsc($key) . '" data-v="' . hsc(mb_strtolower((string)$v)) . '"' : '')
                    . '>' . hsc((string)$v) . '</span>';
            }
            return implode(' ', $chips);
        }
        if (is_float($raw)) $raw = rtrim(rtrim(number_format($raw, 2, '.', ''), '0'), '.');
        $l = strtolower((string)$raw);
        if ($l === 'true' || $l === 'false') {
            return '<span class="fmt-bool fmt-' . $l . '"' . ($filterable
                    ? ' data-k="' . hsc($key) . '" data-v="' . $l . '"' : '') . '>' . ($l === 'true' ? '✓' : '✗') . '</span>';
        }
        if (preg_match('#^https?://\S+$#i', (string)$raw)) {
            return '<a class="urlextern" href="' . hsc((string)$raw) . '">' . hsc((string)$raw) . '</a>';
        }
        if ($filterable) {
            return '<span class="fmt-chip fmt-plain" data-k="' . hsc($key) . '" data-v="' . hsc($l) . '">'
                . hsc((string)$raw) . '</span>';
        }
        return hsc((string)$raw);
    }
}
