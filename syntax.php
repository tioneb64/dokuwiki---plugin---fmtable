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
        $rows = $h->run($q);

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
                $raw = $h->value($e, $c['key']);
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
                $v = $h->value($e, $q['listexpr']);
                if ($v !== null && $v !== []) $extra = ' : ' . hsc(is_array($v) ? implode(', ', $v) : (string)$v);
            }
            $out .= '<li><a href="' . hsc(wl($e['id'])) . '">' . hsc($e['title']) . '</a>' . $extra . '</li>';
        }
        return $out . '</ul>';
    }

    /** @return array{type:string,values:array} */
    protected function columnInfo(helper_plugin_fmtable $h, array $rows, string $key): array
    {
        $seen = [];
        $allBool = $allNum = true;
        $isList = false;
        $any = false;
        foreach ($rows as $e) {
            $raw = $h->value($e, $key);
            if ($raw === null || $raw === '' || $raw === []) continue;
            foreach ((array)$raw as $v) {
                $any = true;
                $l = mb_strtolower((string)$v);
                if ($l !== 'true' && $l !== 'false') $allBool = false;
                if (!is_numeric($v)) $allNum = false;
                $seen[$l] ??= (string)$v;
            }
            if (is_array($raw)) $isList = true;
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
        $raw = $h->value($e, $key);
        if ($raw === null || $raw === '' || $raw === []) return [];
        $out = [];
        foreach ((array)$raw as $v) {
            if ((string)$v !== '') $out[] = mb_strtolower((string)$v);
        }
        return array_values(array_unique($out));
    }

    protected function sortKey($raw): string
    {
        if ($raw === null) return '';
        if (is_array($raw)) $raw = implode(',', $raw);
        if (is_numeric($raw)) return sprintf('%020.6f', (float)$raw);
        return mb_strtolower((string)$raw);
    }

    protected function cell(helper_plugin_fmeta $fm, array $e, string $key, $raw, bool $filterable): string
    {
        if ($raw === null || $raw === '' || $raw === []) return '';
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
