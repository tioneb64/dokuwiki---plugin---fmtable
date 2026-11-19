<?php

use dokuwiki\Extension\Plugin;

/**
 * Fmtable : requêtes de type Dataview sur les en-têtes des fiches (et sur les tâches des pages).
 *
 *   TABLE nom, categorie AS "Catégorie", delivery
 *   FROM "70 Veille/logiciels" OR #message-broker
 *   WHERE type = "message-broker" AND contains(delivery, "exactly-once")
 *   SORT throughput_score DESC, nom ASC
 *   GROUP BY categorie
 *   FILTERS categorie, delivery
 *   LIMIT 50
 *
 *   TASK FROM #projet WHERE !completed GROUP BY file.link
 *
 * Les champs sont ceux de l'en-tête (index du plugin fmeta) ; created, updated, revue, expire, status, tags,
 * title, file.name, file.link, file.path et file.folder sont aussi disponibles.
 */
class helper_plugin_fmtable extends Plugin
{
    /** Mots-clés d'une requête (« GROUP BY » compte pour un seul) */
    protected const KEYWORDS = 'TABLE|LIST|TASK|CALENDAR|FROM|WHERE|SORT|FLATTEN|GROUP\s+BY|FILTERS|LIMIT';

    // ---- analyse de la requête ----------------------------------------------------------------

    /**
     * @return array{mode:string,calfield:string,cols:array,noid:bool,from:?array,hasTag:bool,where:?array,sort:array,group:?array,filters:?array,limit:int,listexpr:string}
     */
    public function parse(string $text): array
    {
        $q = ['mode' => 'TABLE', 'calfield' => '', 'cols' => [], 'noid' => false, 'from' => null, 'hasTag' => false, 'where' => null,
            'sort' => [], 'flatten' => [], 'group' => null, 'filters' => null, 'limit' => 0, 'listexpr' => ''];

        foreach ($this->sections($text) as [$kw, $body]) {
            switch ($kw) {
                case 'TABLE':
                    $q['mode'] = 'TABLE';
                    if (preg_match('/^WITHOUT\s+ID\b\s*(.*)$/i', $body, $m)) {
                        $q['noid'] = true;
                        $body = $m[1];
                    }
                    $q['cols'] = $this->parseColumns($body);
                    break;
                case 'LIST':
                    $q['mode'] = 'LIST';
                    if (preg_match('/^WITHOUT\s+ID\b\s*(.*)$/i', $body, $m)) $body = $m[1];
                    $q['listexpr'] = $body;
                    break;
                case 'TASK':
                    $q['mode'] = 'TASK';
                    break;
                case 'CALENDAR':
                    $q['mode'] = 'CALENDAR';
                    $q['calfield'] = $body;
                    break;
                case 'FROM':
                    $q['from'] = $this->parseFrom($body);
                    $q['hasTag'] = $q['from'] !== null && $this->fromHasTag($q['from']);
                    break;
                case 'WHERE':
                    $q['where'] = $this->parseWhere($body);
                    break;
                case 'SORT':
                    foreach ($this->splitList($body) as $item) {
                        if (preg_match('/^(.*?)(?:\s+(ASC|DESC|ASCENDING|DESCENDING))?$/i', trim($item), $m)) {
                            $q['sort'][] = [trim($m[1]), isset($m[2]) && stripos($m[2], 'D') === 0];
                        }
                    }
                    break;
                case 'FLATTEN':
                    if (preg_match('/^(.*?)(?:\s+AS\s+(?:"([^"]*)"|\'([^\']*)\'|(\S+)))?\s*$/i', $body, $m) && trim($m[1]) !== '') {
                        $name = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                        $q['flatten'][] = ['expr' => trim($m[1]), 'name' => $name !== '' ? $name : trim($m[1])];
                    }
                    break;
                case 'GROUP BY':
                    if (preg_match('/^(.*?)(?:\s+AS\s+(?:"([^"]*)"|\'([^\']*)\'|(\S+)))?\s*$/i', $body, $m) && trim($m[1]) !== '') {
                        $name = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                        $q['group'] = ['expr' => trim($m[1]), 'name' => $name];
                    }
                    break;
                case 'FILTERS':
                    $q['filters'] = array_values(array_filter(array_map('trim', $this->splitList($body)), 'strlen'));
                    break;
                case 'LIMIT':
                    $q['limit'] = max(0, (int)$body);
                    break;
            }
        }
        return $q;
    }

    /**
     * Découpe la requête en [mot-clé, contenu] ; un mot-clé écrit dans des guillemets, après un point, un # ou
     * un / n'en est pas un. La requête peut tenir sur une seule ligne.
     *
     * @return array<int,array{0:string,1:string}>
     */
    protected function sections(string $text): array
    {
        $re = '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|(?<![\w.#\/-])(' . self::KEYWORDS . ')(?![\w.\/-])/i';
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
        $marks = [];
        foreach ($m as $x) {
            if (!isset($x[1]) || $x[1][1] < 0 || $x[1][0] === '') continue;
            $kw = strtoupper(preg_replace('/\s+/', ' ', $x[1][0]));
            $before = substr($text, 0, $x[0][1]);
            $lineStart = preg_match('/(?:^|\n)[ \t]*$/', $before) === 1;
            if (!$lineStart) {
                // en milieu de ligne : seuls les mots-clés de clause sont reconnus, et pas à la place d'un nom de
                // champ (« TABLE a, from », « WHERE x = 1 AND limit > 2 »)
                if (in_array($kw, ['TABLE', 'LIST', 'TASK', 'CALENDAR'], true)) continue;
                if (preg_match('/(?:[,(=<>!]|\b(?:AND|OR|NOT|AS|BY|ID)\b)\s*$/i', $before)) continue;
            }
            $marks[] = [$kw, $x[0][1], $x[0][1] + strlen($x[0][0])];
        }
        $out = [];
        foreach ($marks as $i => [$kw, , $end]) {
            $stop = $marks[$i + 1][1] ?? strlen($text);
            $out[] = [$kw, trim(preg_replace('/\s+/', ' ', substr($text, $end, $stop - $end)))];
        }
        return $out;
    }

    /** Découpe « a, b AS "x, y", c » en éléments, sans couper dans les guillemets */
    protected function splitList(string $s): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $quote = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote !== '') {
                if ($c === $quote) $quote = '';
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === ',' && $depth === 0) {
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = $cur;
        return array_values(array_filter(array_map('trim', $out), 'strlen'));
    }

    protected function parseColumns(string $body): array
    {
        $cols = [];
        foreach ($this->splitList($body) as $item) {
            $label = '';
            if (preg_match('/^(.*?)\s+AS\s+(?:"([^"]*)"|\'([^\']*)\'|(\S+))\s*$/i', $item, $m)) {
                $item = trim($m[1]);
                $label = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
            }
            if ($label === '') $label = strtolower($item) === 'file.link' ? $this->getLang('col_page') : $item;
            $cols[] = ['key' => $item, 'label' => $label];
        }
        return $cols;
    }

    /** "70 Veille/logiciels" -> 70_veille:logiciels */
    protected function toNamespace(string $path): string
    {
        $segs = preg_split('#[/\\\\:]#', $path, -1, PREG_SPLIT_NO_EMPTY);
        return implode(':', array_filter(array_map('cleanID', $segs), 'strlen'));
    }

    // ---- expression FROM : "dossier", #tag, and, or, -, parenthèses ----------------------------------

    /**
     * Arbre : ['ns', espace] | ['tag', tag] | ['not', x] | ['and', [..]] | ['or', [..]] ; null si vide.
     * Des éléments simplement juxtaposés (ou séparés par une virgule) valent « or ».
     */
    protected function parseFrom(string $s): ?array
    {
        preg_match_all('/"[^"]*"|\'[^\']*\'|\(|\)|,|\[\[[^\]]*\]\]|[^\s()",]+/u', $s, $m);
        $t = [];
        foreach ($m[0] as $w) {
            $c = $w[0];
            if ($c === '"' || $c === "'") {
                $t[] = ['ns', $this->toNamespace(substr($w, 1, -1))];
            } elseif ($c === '(' || $c === ')') {
                $t[] = [$c];
            } elseif ($c === ',') {
                $t[] = ['or'];
            } elseif ($c === '[') {
                continue;                                  // [[page]] : liens entrants/sortants, non géré
            } else {
                $neg = 0;
                while ($w !== '' && ($w[0] === '-' || $w[0] === '!')) {
                    $neg++;
                    $w = substr($w, 1);
                }
                for ($i = 0; $i < $neg; $i++) $t[] = ['neg'];
                if ($w === '') continue;
                if (strcasecmp($w, 'and') === 0) {
                    $t[] = ['and'];
                } elseif (strcasecmp($w, 'or') === 0) {
                    $t[] = ['or'];
                } elseif ($w[0] === '#') {
                    $tag = mb_strtolower(ltrim($w, '#'));
                    if ($tag !== '') $t[] = ['tag', $tag];
                } else {
                    $t[] = ['ns', $this->toNamespace($w)];
                }
            }
        }
        $i = 0;
        return $t ? $this->fromOr($t, $i) : null;
    }

    protected function fromOr(array $t, int &$i): ?array
    {
        $parts = [];
        $first = $this->fromAnd($t, $i);
        if ($first !== null) $parts[] = $first;
        while (isset($t[$i])) {
            $k = $t[$i][0];
            if ($k === 'or') {
                $i++;
            } elseif (!in_array($k, ['ns', 'tag', '(', 'neg'], true)) {
                break;                                      // « ) » ou « and » perdu : on s'arrête
            }
            $x = $this->fromAnd($t, $i);
            if ($x !== null) $parts[] = $x;
        }
        if (!$parts) return null;
        return count($parts) === 1 ? $parts[0] : ['or', $parts];
    }

    protected function fromAnd(array $t, int &$i): ?array
    {
        $parts = [];
        $x = $this->fromUnary($t, $i);
        if ($x !== null) $parts[] = $x;
        while (isset($t[$i]) && $t[$i][0] === 'and') {
            $i++;
            $x = $this->fromUnary($t, $i);
            if ($x !== null) $parts[] = $x;
        }
        if (!$parts) return null;
        return count($parts) === 1 ? $parts[0] : ['and', $parts];
    }

    protected function fromUnary(array $t, int &$i): ?array
    {
        if (!isset($t[$i])) return null;
        $k = $t[$i][0];
        if ($k === 'neg') {
            $i++;
            $x = $this->fromUnary($t, $i);
            return $x === null ? null : ['not', $x];
        }
        if ($k === '(') {
            $i++;
            $x = $this->fromOr($t, $i);
            if (isset($t[$i]) && $t[$i][0] === ')') $i++;
            return $x;
        }
        if ($k === 'ns' || $k === 'tag') {
            $i++;
            return $t[$i - 1];
        }
        return null;
    }

    protected function fromHasTag(array $n): bool
    {
        switch ($n[0]) {
            case 'tag':
                return true;
            case 'not':
                return $this->fromHasTag($n[1]);
            case 'and':
            case 'or':
                foreach ($n[1] as $x) if ($this->fromHasTag($x)) return true;
        }
        return false;
    }

    protected function fromMatch(array $n, string $id, array $e): bool
    {
        switch ($n[0]) {
            case 'ns':
                return $n[1] === '' || $id === $n[1] || strpos($id, $n[1] . ':') === 0;
            case 'tag':
                foreach (($e['tags'] ?? []) as $t) {
                    $t = mb_strtolower(ltrim((string)$t, '#'));
                    if ($t === $n[1] || strpos($t, $n[1] . '/') === 0) return true;      // #a couvre aussi #a/b
                }
                return false;
            case 'not':
                return !$this->fromMatch($n[1], $id, $e);
            case 'and':
                foreach ($n[1] as $x) if (!$this->fromMatch($x, $id, $e)) return false;
                return true;
            case 'or':
                foreach ($n[1] as $x) if ($this->fromMatch($x, $id, $e)) return true;
                return false;
        }
        return false;
    }

    // ---- expressions WHERE ---------------------------------------------------------------------

    protected function tokenize(string $s): array
    {
        $tokens = [];
        $re = '/\s*(?:("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(<=|>=|!=|=|<|>)|([(),])|(-?\d+(?:\.\d+)?)(?![\w-])|([^\s()=<>!,"\']+)|(!))/A';
        $pos = 0;
        $len = strlen($s);
        while ($pos < $len && preg_match($re, $s, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if (($m[1] ?? '') !== '') {
                $tokens[] = ['str', stripcslashes(substr($m[1], 1, -1))];
            } elseif (($m[2] ?? '') !== '') {
                $tokens[] = ['op', $m[2]];
            } elseif (($m[3] ?? '') !== '') {
                $tokens[] = ['p', $m[3]];
            } elseif (($m[4] ?? '') !== '') {
                $tokens[] = ['num', (float)$m[4]];
            } elseif (($m[5] ?? '') !== '') {
                $tokens[] = ['id', $m[5]];
            } elseif (($m[6] ?? '') !== '') {
                $tokens[] = ['id', 'NOT'];                  // « !completed »
            } else {
                break;
            }
        }
        return $tokens;
    }

    /** Arbre : ['or'|'and', [..]] | ['not', x] | ['cmp', op, a, b] | ['fn', nom, [args]] | ['val', x] */
    protected function parseWhere(string $s): array
    {
        $t = $this->tokenize($s);
        $i = 0;
        return $this->pOr($t, $i);
    }

    /** Expression seule (colonne, clé de regroupement) : ['field', nom] | ['lit', x] | ['fn', nom, [args]] */
    protected function parseExpr(string $s): array
    {
        static $cache = [];
        if (!isset($cache[$s])) {
            $t = $this->tokenize($s);
            $i = 0;
            $n = $this->pOr($t, $i);
            $cache[$s] = $n[0] === 'val' ? $n[1] : $n;
        }
        return $cache[$s];
    }

    protected function isWord(array $t, int $i, string $w): bool
    {
        return isset($t[$i]) && $t[$i][0] === 'id' && strcasecmp($t[$i][1], $w) === 0;
    }

    protected function pOr(array $t, int &$i): array
    {
        $parts = [$this->pAnd($t, $i)];
        while ($this->isWord($t, $i, 'OR')) {
            $i++;
            $parts[] = $this->pAnd($t, $i);
        }
        return count($parts) === 1 ? $parts[0] : ['or', $parts];
    }

    protected function pAnd(array $t, int &$i): array
    {
        $parts = [$this->pNot($t, $i)];
        while ($this->isWord($t, $i, 'AND')) {
            $i++;
            $parts[] = $this->pNot($t, $i);
        }
        return count($parts) === 1 ? $parts[0] : ['and', $parts];
    }

    protected function pNot(array $t, int &$i): array
    {
        if ($this->isWord($t, $i, 'NOT')) {
            $i++;
            return ['not', $this->pNot($t, $i)];
        }
        $a = $this->pAdd($t, $i);               // les parenthèses sont traitées par pPrimary
        if (isset($t[$i]) && $t[$i][0] === 'op') {
            $op = $t[$i][1];
            $i++;
            return ['cmp', $op, $a, $this->pAdd($t, $i)];
        }
        return ['val', $a];
    }

    /** Opérateur arithmétique : « + - * / » séparés par des espaces (« a-b » reste un nom de champ) */
    protected function isOp(array $t, int $i, string $chars): bool
    {
        return isset($t[$i]) && $t[$i][0] === 'id' && strlen($t[$i][1]) === 1 && strpos($chars, $t[$i][1]) !== false;
    }

    protected function pAdd(array $t, int &$i): array
    {
        $left = $this->pMul($t, $i);
        while ($this->isOp($t, $i, '+-')) {
            $op = $t[$i][1];
            $i++;
            $left = ['bin', $op, $left, $this->pMul($t, $i)];
        }
        return $left;
    }

    protected function pMul(array $t, int &$i): array
    {
        $left = $this->pPrimary($t, $i);
        while ($this->isOp($t, $i, '*/')) {
            $op = $t[$i][1];
            $i++;
            $left = ['bin', $op, $left, $this->pPrimary($t, $i)];
        }
        return $left;
    }

    /** Opérande, ou expression entre parenthèses */
    protected function pPrimary(array $t, int &$i): array
    {
        if (isset($t[$i]) && $t[$i] === ['p', '(']) {
            $i++;
            $e = $this->pOr($t, $i);
            if (isset($t[$i]) && $t[$i] === ['p', ')']) $i++;
            return $e[0] === 'val' ? $e[1] : $e;
        }
        return $this->pOperand($t, $i);
    }

    protected function pOperand(array $t, int &$i): array
    {
        if (!isset($t[$i])) return ['lit', null];
        [$type, $v] = $t[$i];
        $i++;
        if ($type === 'str' || $type === 'num') return ['lit', $v];
        if ($type === 'id') {
            $low = strtolower($v);
            if ($low === 'true') return ['lit', true];
            if ($low === 'false') return ['lit', false];
            if ($low === 'null') return ['lit', null];
            if (isset($t[$i]) && $t[$i] === ['p', '(']) {          // fonction : contains(a, b)
                $i++;
                $args = [];
                while (isset($t[$i]) && $t[$i] !== ['p', ')']) {
                    if ($t[$i] === ['p', ',']) {
                        $i++;
                        continue;
                    }
                    $a = $this->pOr($t, $i);                 // argument : toute expression (choice(a = 1, 2, 3))
                    $args[] = $a[0] === 'val' ? $a[1] : $a;
                }
                $i++;
                return ['fn', $low, $args];
            }
            return ['field', $v];
        }
        return ['lit', null];
    }

    // ---- valeurs : liens, listes, texte ----------------------------------------------------------

    /** Lien vers une page, tel que le renvoie « file.link » */
    public function isLink($v): bool
    {
        return is_array($v) && isset($v['__link']);
    }

    /** Valeur sous forme de liste d'éléments (un lien compte pour un élément) */
    public function asList($v): array
    {
        if ($v === null) return [];
        if ($this->isLink($v)) return [$v];
        return is_array($v) ? array_values($v) : [$v];
    }

    /** Texte d'une valeur : un lien donne son libellé, une liste ses éléments séparés par des virgules */
    public function plain($v): string
    {
        if ($v === null) return '';
        if ($this->isLink($v)) return (string)$v['text'];
        if (is_array($v)) return implode(', ', array_map([$this, 'plain'], array_values($v)));
        if (is_bool($v)) return $v ? 'true' : 'false';
        return (string)$v;
    }

    /** Valeur brute d'un champ pour une fiche (scalaire, liste, lien ou null) */
    public function value(array $e, string $key)
    {
        $k = strtolower(trim($key));
        // tâche (mode TASK) : ses propres champs d'abord (text, status, completed, due...)
        if (isset($e['__flat']) && array_key_exists($k, $e['__flat'])) return $e['__flat'][$k];   // FLATTEN
        if (isset($e['__task']) && array_key_exists($k, $e['__task'])) return $e['__task'][$k];
        switch ($k) {
            case 'file.name':
            case 'name_of_file':
                return noNS($e['id']);
            case 'file.link':
                return ['__link' => $e['id'], 'text' => $e['title'] ?? noNS($e['id'])];
            case 'file.path':
                return $e['id'];
            case 'file.folder':
                return getNS($e['id']);
            case 'title':
            case 'file.title':
                return $e['title'] ?? noNS($e['id']);
            case 'created':
            case 'file.cday':
            case 'file.ctime':
                return ($e['created'] ?? '') ?: null;
            case 'updated':
            case 'modified':
            case 'file.mday':
            case 'file.mtime':
                return ($e['updated'] ?? '') ?: null;
            case 'revue':
            case 'last-revue':
            case 'dernière_revue':
            case 'derniere_revue':
                return ($e['revue'] ?? '') ?: null;
            case 'expire':
                // date saisie, à défaut date calculée par fmlife (revue/modification + durée de validité)
                return ($e['expire'] ?? '') ?: (($e['computed']['expire'] ?? '') ?: null);
            case 'status':
            case 'statut':
                return ($e['status'] ?? '') ?: null;
            case 'tags':
                return $e['tags'] ?? [];
            case 'today':
            case 'now':
                return date('Y-m-d');
            case 'tomorrow':
                return date('Y-m-d', strtotime('+1 day'));
            case 'yesterday':
                return date('Y-m-d', strtotime('-1 day'));
            case 'sow':
                return date('Y-m-d', strtotime('monday this week'));
            case 'eow':
                return date('Y-m-d', strtotime('sunday this week'));
            case 'som':
                return date('Y-m-01');
            case 'eom':
                return date('Y-m-t');
            case 'soy':
                return date('Y-01-01');
            case 'eoy':
                return date('Y-12-31');
        }
        // champs calculés fournis par d'autres plugins (voir fmeta::enrich) : retard, echeance, reference...
        if (isset($e['computed']) && array_key_exists($k, $e['computed'])) return $e['computed'][$k];
        foreach (($e['fields'] ?? []) as $fk => $fv) {
            if (strtolower((string)$fk) === $k) return $fv === '' ? null : $fv;
        }
        return null;
    }

    /** Valeur typée : « true » -> true, « 3 » -> 3 ; un lien devient son libellé */
    protected function typed($v)
    {
        if ($this->isLink($v)) return $v['text'];
        if (is_array($v)) return array_map([$this, 'typed'], $v);
        if (is_string($v)) {
            $l = strtolower($v);
            if ($l === 'true') return true;
            if ($l === 'false') return false;
            if (is_numeric($v)) return $v + 0;
        }
        return $v;
    }

    protected function operandValue(array $e, array $node)
    {
        switch ($node[0]) {
            case 'lit':
                return $node[1];
            case 'field':
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $node[1])) return $node[1];       // date écrite telle quelle
                return $this->typed($this->value($e, $node[1]));
            case 'fn':
                return $this->callFn($node[1], array_map(fn($n) => $this->operandValue($e, $n), $node[2]));
            case 'bin':
                return $this->arith($node[1], $this->operandValue($e, $node[2]), $this->operandValue($e, $node[3]));
            case 'val':
                return $this->operandValue($e, $node[1]);
            case 'cmp':
            case 'and':
            case 'or':
            case 'not':
                return $this->evalNode($e, $node);
        }
        return null;
    }

    /** a op b : nombres, ou « + » sur du texte (concaténation) ; null si un côté est vide ou non numérique */
    protected function arith(string $op, $a, $b)
    {
        if ($a === null || $b === null) return null;
        // date +/- durée : today - "30d", today + "2 weeks" (d, w, mo, y)
        if (($op === '+' || $op === '-') && is_string($a) && preg_match('/^\d{4}-\d{2}-\d{2}/', $a)
            && is_string($b) && preg_match('/^(\d+)\s*(d|days?|w|weeks?|mo|months?|y|years?)$/i', trim($b), $m)) {
            $unit = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'][strtolower($m[2][0])];
            $t = strtotime(substr($a, 0, 10) . ' ' . $op . $m[1] . ' ' . $unit);
            return $t === false ? null : date('Y-m-d', $t);
        }
        if (is_numeric($a) && is_numeric($b)) {
            $a += 0;
            $b += 0;
            switch ($op) {
                case '+': return $a + $b;
                case '-': return $a - $b;
                case '*': return $a * $b;
                case '/': return $b == 0 ? null : $a / $b;
            }
        }
        return $op === '+' ? $this->plain($a) . $this->plain($b) : null;
    }

    protected function same($a, $b): bool
    {
        if (is_bool($a) || is_bool($b)) return (bool)$a === (bool)$b && $a !== null && $b !== null;
        if (is_numeric($a) && is_numeric($b)) return (float)$a === (float)$b;
        $x = mb_strtolower((string)$a);
        $y = mb_strtolower((string)$b);
        // « #tag » et « tag » désignent la même étiquette
        if (($x[0] ?? '') === '#') $x = substr($x, 1);
        if (($y[0] ?? '') === '#') $y = substr($y, 1);
        return $x === $y;
    }

    /** Nombres d'une valeur ou liste (les autres éléments sont ignorés) */
    protected function numbers($v): array
    {
        $out = [];
        foreach ($this->asList($v) as $x) {
            $x = $this->typed($x);
            if (is_numeric($x)) $out[] = $x + 0;
        }
        return $out;
    }

    /**
     * Fonctions utilisables dans WHERE, SORT, GROUP BY et les colonnes d'un regroupement.
     * Les arguments sont déjà évalués.
     */
    protected function callFn(string $name, array $a)
    {
        $x = $a[0] ?? null;
        switch ($name) {
            case 'contains':
            case 'icontains':
                $needle = $a[1] ?? null;
                if ($x === null) return false;
                if (is_array($x) && !$this->isLink($x)) {
                    foreach ($x as $item) if ($this->same($this->typed($item), $needle)) return true;
                    return false;
                }
                return $needle !== null && mb_stripos($this->plain($x), $this->plain($needle)) !== false;
            case 'startswith':
                return $x !== null && isset($a[1]) && mb_stripos($this->plain($x), $this->plain($a[1])) === 0;
            case 'endswith':
                $s = mb_strtolower($this->plain($x));
                $e = mb_strtolower($this->plain($a[1] ?? ''));
                return $x !== null && $e !== '' && mb_substr($s, -mb_strlen($e)) === $e;
            case 'length':
            case 'count':
                if ($x === null) return 0;
                if ($this->isLink($x)) return 1;
                return is_array($x) ? count($x) : mb_strlen((string)$x);
            case 'sum':
                return array_sum($this->numbers($x));
            case 'min':
            case 'max':
                $n = $this->numbers($x);
                if (!$n) return null;
                return $name === 'min' ? min($n) : max($n);
            case 'average':
            case 'avg':
                $n = $this->numbers($x);
                return $n ? round(array_sum($n) / count($n), 2) : null;
            case 'join':
                return implode($a[1] ?? ', ', array_map([$this, 'plain'], $this->asList($x)));
            case 'unique':
                $seen = [];
                foreach ($this->asList($x) as $item) $seen[mb_strtolower($this->plain($item))] ??= $item;
                return array_values($seen);
            case 'sort':
                $l = $this->asList($x);
                usort($l, fn($p, $q) => strnatcasecmp($this->plain($p), $this->plain($q)));
                return $l;
            case 'reverse':
                return array_reverse($this->asList($x));
            case 'round':
                $n = $this->numbers($x);
                return $n ? round($n[0], (int)($a[1] ?? 0)) : null;
            case 'choice':                                     // choice(condition, si_vrai, si_faux)
                return ((is_array($x) && !$this->isLink($x)) ? count($x) > 0 : (bool)$x) ? ($a[1] ?? null) : ($a[2] ?? null);
            case 'default':
                return ($x === null || $x === '' || $x === []) ? ($a[1] ?? null) : $x;
            case 'lower':
                return $x === null ? null : mb_strtolower($this->plain($x));
            case 'upper':
                return $x === null ? null : mb_strtoupper($this->plain($x));
            case 'date':
                $s = $this->plain($x);
                return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
        }
        return null;
    }

    public function evalNode(array $e, array $n): bool
    {
        switch ($n[0]) {
            case 'or':
                foreach ($n[1] as $x) if ($this->evalNode($e, $x)) return true;
                return false;
            case 'and':
                foreach ($n[1] as $x) if (!$this->evalNode($e, $x)) return false;
                return true;
            case 'not':
                return !$this->evalNode($e, $n[1]);
            case 'val':
            case 'fn':
                $v = $n[0] === 'fn' ? $this->operandValue($e, $n) : $this->operandValue($e, $n[1]);
                return is_array($v) ? count($v) > 0 : (bool)$v;
            case 'cmp':
                [, $op, $a, $b] = $n;
                $x = $this->operandValue($e, $a);
                $y = $this->operandValue($e, $b);
                if (is_array($x)) {                         // liste : vrai si un élément convient
                    foreach ($x as $item) {
                        if ($this->compare($op, $item, $y)) return true;
                    }
                    return $op === '!=' ? !$this->hasAny($x, $y) : false;
                }
                return $this->compare($op, $x, $y);
        }
        return false;
    }

    protected function hasAny(array $list, $y): bool
    {
        foreach ($list as $x) if ($this->same($x, $y)) return true;
        return false;
    }

    protected function compare(string $op, $x, $y): bool
    {
        if ($op === '=') {
            if ($x === null || $y === null) return $x === null && $y === null || ($x === null && $y === '');
            return $this->same($x, $y);
        }
        if ($op === '!=') return !$this->compare('=', $x, $y);
        if ($x === null || $y === null) return false;
        if (is_numeric($x) && is_numeric($y)) {
            $c = (float)$x <=> (float)$y;
        } else {
            $c = strcmp(mb_strtolower((string)$x), mb_strtolower((string)$y));
        }
        switch ($op) {
            case '<': return $c < 0;
            case '<=': return $c <= 0;
            case '>': return $c > 0;
            case '>=': return $c >= 0;
        }
        return false;
    }

    // ---- exécution -----------------------------------------------------------------------------

    /**
     * Fiches (de l'index fmeta, lisibles par l'utilisateur) répondant à la requête, triées.
     * Les pages sans champ propre sont ignorées, sauf quand FROM contient un #tag.
     * LIMIT ne s'applique ici que sans GROUP BY (sinon il porte sur les groupes).
     */
    public function run(array $q): array
    {
        /** @var helper_plugin_fmeta $fm */
        $fm = plugin_load('helper', 'fmeta');
        $rows = [];
        foreach ($fm->load() as $id => $e) {
            if (auth_quickaclcheck($id) < AUTH_READ) continue;
            if (empty($e['fields']) && !$q['hasTag'] && $q['mode'] !== 'CALENDAR') continue;
            if ($q['from'] !== null && !$this->fromMatch($q['from'], $id, $e)) continue;
            $e['id'] = $id;
            $e = $fm->enrich($e);
            foreach ($this->flattenRow($e, $q['flatten']) as $r) {
                if ($q['where'] !== null && !$this->evalNode($r, $q['where'])) continue;
                $rows[] = $r;
            }
        }
        $sort = $q['sort'] ?: [['file.name', false]];
        foreach ($sort as &$s) {                       // SORT peut citer le titre d'une colonne (AS "Score")
            foreach ($q['cols'] as $c) {
                if ($c['label'] !== $c['key'] && strcasecmp($c['label'], $s[0]) === 0) {
                    $s[0] = $c['key'];
                    break;
                }
            }
        }
        unset($s);
        $this->sortRows($rows, $sort);
        if ($q['limit'] > 0 && $q['group'] === null) $rows = array_slice($rows, 0, $q['limit']);
        return $rows;
    }

    /**
     * Valeur d'une colonne : un champ, ou une expression (a + b, choice(...), length(...)...)
     */
    public function columnValue(array $e, string $key)
    {
        if (preg_match('/^[^\s()"\']+$/u', $key)) return $this->value($e, $key);
        $n = $this->parseExpr($key);
        return $n[0] === 'field' ? $this->value($e, $n[1]) : $this->operandValue($e, $n);
    }

    /**
     * FLATTEN expr [AS nom] : une ligne par élément de la liste (la valeur est lisible sous « nom » dans les colonnes,
     * WHERE, SORT et GROUP BY). Une valeur simple passe telle quelle ; une liste vide supprime la ligne.
     * Appliqué avant WHERE (un WHERE peut donc porter sur le champ aplati).
     *
     * @return array[]
     */
    protected function flattenRow(array $e, array $flat): array
    {
        $rows = [$e];
        foreach ($flat as $f) {
            $node = $this->parseExpr($f['expr']);
            $key = strtolower($f['name']);
            $next = [];
            foreach ($rows as $r) {
                $v = $node[0] === 'field' ? $this->value($r, $node[1]) : $this->operandValue($r, $node);
                $items = (is_array($v) && !$this->isLink($v)) ? $v : [$v];
                foreach ($items as $item) {
                    $c = $r;
                    $c['__flat'][$key] = $item;
                    $next[] = $c;
                }
            }
            $rows = $next;
        }
        return $rows;
    }

    protected function sortRows(array &$rows, array $sort): void
    {
        usort($rows, function ($a, $b) use ($sort) {
            foreach ($sort as [$key, $desc]) {
                $x = $this->typed($this->columnValue($a, $key));
                $y = $this->typed($this->columnValue($b, $key));
                if (is_array($x)) $x = $this->plain($x);
                if (is_array($y)) $y = $this->plain($y);
                if ($x === null && $y === null) continue;
                if ($x === null) return 1;                    // sans valeur : toujours en dernier
                if ($y === null) return -1;
                $c = (is_numeric($x) && is_numeric($y)) ? ((float)$x <=> (float)$y)
                    : strcmp(mb_strtolower((string)$x), mb_strtolower((string)$y));
                if ($c !== 0) return $desc ? -$c : $c;
            }
            return ($a['__task']['line'] ?? 0) <=> ($b['__task']['line'] ?? 0);
        });
    }

    // ---- regroupement (GROUP BY) -----------------------------------------------------------------

    /**
     * Regroupe les lignes selon l'expression GROUP BY. Une valeur liste (tags) place la ligne dans
     * un groupe par élément. Groupes triés par clé, « sans valeur » en dernier ; LIMIT porte sur les groupes.
     *
     * @return array<int,array{key:mixed,rows:array,name:string}>
     */
    public function groups(array $rows, array $q): array
    {
        $g = $q['group'];
        $node = $this->parseExpr($g['expr']);
        $buckets = [];
        foreach ($rows as $e) {
            $k = $node[0] === 'field' ? $this->value($e, $node[1]) : $this->operandValue($e, $node);
            $items = ($k === null || $k === '' || $k === []) ? [null] : $this->asList($k);
            foreach ($items as $item) {
                $id = $item === null ? "\0" : mb_strtolower($this->plain($item));
                $buckets[$id] ??= ['key' => $item, 'rows' => [], 'name' => $g['name']];
                $buckets[$id]['rows'][] = $e;
            }
        }
        $out = array_values($buckets);
        usort($out, function ($a, $b) {
            if ($a['key'] === null || $b['key'] === null) return ($a['key'] === null) <=> ($b['key'] === null);
            $x = $this->plain($a['key']);
            $y = $this->plain($b['key']);
            return (is_numeric($x) && is_numeric($y)) ? ((float)$x <=> (float)$y) : strnatcasecmp($x, $y);
        });
        if ($q['limit'] > 0) $out = array_slice($out, 0, $q['limit']);
        return $out;
    }

    /** Valeur d'une expression de colonne pour un groupe : key, rows, rows.champ, length(rows), sum(rows.x)... */
    public function groupExpr(array $g, string $expr)
    {
        return $this->groupOperand($g, $this->parseExpr($expr));
    }

    protected function groupOperand(array $g, array $n)
    {
        switch ($n[0]) {
            case 'lit':
                return $n[1];
            case 'bin':
                return $this->arith($n[1], $this->typed($this->groupOperand($g, $n[2])), $this->typed($this->groupOperand($g, $n[3])));
            case 'val':
                return $this->groupOperand($g, $n[1]);
            case 'fn':
                return $this->callFn($n[1], array_map(fn($x) => $this->groupOperand($g, $x), $n[2]));
            case 'field':
                $name = $n[1];
                $low = strtolower($name);
                if ($low === 'key' || ($g['name'] !== '' && strcasecmp($g['name'], $name) === 0)) return $g['key'];
                if ($low === 'rows') {
                    return array_map(fn($r) => $this->value($r, 'file.link'), $g['rows']);
                }
                if (strncmp($low, 'rows.', 5) === 0) {
                    $sub = substr($name, 5);
                    $out = [];
                    foreach ($g['rows'] as $r) {
                        foreach ($this->asList($this->value($r, $sub)) as $v) {
                            if ($v !== null && $v !== '') $out[] = $v;      // les listes (tags) sont mises à plat
                        }
                    }
                    return $out;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $name)) return $name;
                // champ simple (ex. le nom donné par FLATTEN) : valeur de la première fiche du groupe
                return $g['rows'] ? $this->value($g['rows'][0], $name) : null;
        }
        return null;
    }

    // ---- calendrier (CALENDAR) -------------------------------------------------------------------------

    /**
     * Pages placées par date : « AAAA-MM-JJ » => [['t' => titre, 'u' => adresse], ...].
     * Une valeur liste de dates place la page à chaque date ; les dates « JJ/MM/AAAA » sont acceptées.
     *
     * @return array<string,array<int,array{t:string,u:string}>>
     */
    public function calendarEvents(array $rows, string $field): array
    {
        $field = trim($field) !== '' ? trim($field) : 'file.cday';
        $out = [];
        foreach ($rows as $e) {
            foreach ($this->asList($this->value($e, $field)) as $v) {
                $s = trim($this->plain($v));
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
                    $d = $m[1] . '-' . $m[2] . '-' . $m[3];
                } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) {
                    $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                } else {
                    continue;
                }
                $out[$d][] = ['t' => (string)($e['title'] ?? noNS($e['id'])), 'u' => wl($e['id'])];
            }
        }
        ksort($out);
        foreach ($out as &$list) {
            usort($list, static fn($a, $b) => strnatcasecmp($a['t'], $b['t']));
        }
        unset($list);
        return $out;
    }

    // ---- tâches (mode TASK) -----------------------------------------------------------------------

    /**
     * Tâches « - [ ] texte » des pages lisibles (hors blocs de code), une entrée par tâche : la fiche de la page
     * plus '__task' (text, status, checked, completed, fullyCompleted, section, line, tags, due, scheduled, start,
     * created, completion, et les champs « [clé:: valeur] »).
     */
    public function runTasks(array $q): array
    {
        global $conf;
        /** @var helper_plugin_fmeta $fm */
        $fm = plugin_load('helper', 'fmeta');
        $index = $fm->load();
        $pages = [];
        search($pages, $conf['datadir'], 'search_allpages', [], '');

        $rows = [];
        foreach ($pages as $p) {
            $id = $p['id'];
            if (auth_quickaclcheck($id) < AUTH_READ) continue;
            $e = $index[$id] ?? array_merge($fm->normalize([]), ['title' => noNS($id), 'hdr' => false]);
            if ($q['from'] !== null && !$this->fromMatch($q['from'], $id, $e)) continue;
            $raw = (string)rawWiki($id);
            if (strpos($raw, '[') === false) continue;
            $tasks = $this->pageTasks($raw);
            if (!$tasks) continue;
            $e['id'] = $id;
            $e = $fm->enrich($e);
            foreach ($tasks as $t) {
                $r = $e;
                $r['__task'] = $t;
                foreach ($this->flattenRow($r, $q['flatten']) as $f) {
                    if ($q['where'] !== null && !$this->evalNode($f, $q['where'])) continue;
                    $rows[] = $f;
                }
            }
        }
        $this->sortRows($rows, $q['sort'] ?: [['file.path', false]]);
        if ($q['limit'] > 0 && $q['group'] === null) $rows = array_slice($rows, 0, $q['limit']);
        return $rows;
    }

    /** @return array<int,array> tâches d'un texte de page */
    protected function pageTasks(string $raw): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));
        $i = 0;
        if (isset($lines[0]) && preg_match('/^---\s*$/', $lines[0])) {       // en-tête
            for ($j = 1; $j < count($lines); $j++) {
                if (preg_match('/^---\s*$/', $lines[$j])) {
                    $i = $j + 1;
                    break;
                }
            }
        }
        $tasks = [];
        $fence = null;          // [caractère, longueur] d'une clôture ouverte
        $zone = null;           // balise littérale ouverte (code, file...)
        $section = '';
        $prevBlank = true;
        $topIsItem = false;     // la dernière ligne non indentée était un élément de liste
        for ($n = count($lines); $i < $n; $i++) {
            $l = rtrim($lines[$i]);
            if ($zone !== null) {
                if (stripos($l, '</' . $zone . '>') !== false) $zone = null;
                continue;
            }
            if ($fence !== null) {
                if (preg_match('/^\s{0,3}(' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',})\s*$/', $l)) $fence = null;
                continue;
            }
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $l, $m)) {
                $fence = [$m[1][0], strlen($m[1])];
                continue;
            }
            if (preg_match('/<(code|file|nowiki|html|php)\b[^>]*>/i', $l, $m) && stripos($l, '</' . $m[1] . '>') === false) {
                $zone = strtolower($m[1]);
                continue;
            }
            if ($l === '') {
                $prevBlank = true;
                continue;
            }
            $indented = preg_match('/^(?:\t|    )/', $l) === 1;
            // texte indenté de 4 espaces après une ligne vide, hors liste : bloc de code
            if ($indented && $prevBlank && !$topIsItem) continue;
            $isItem = preg_match('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]+/', $l) === 1;
            if (!$indented) $topIsItem = $isItem;
            $prevBlank = false;

            if (preg_match('/^#{1,6}\s+(.*?)\s*#*\s*$/', $l, $m) || preg_match('/^={2,6}\s*(.*?)\s*={2,6}\s*$/', $l, $m)) {
                $section = $m[1];
                continue;
            }
            if (preg_match('/^(?:>\s*)*[ \t]*(?:[-*+]|\d+[.)])[ \t]+\[(.)\](?:[ \t]+(.*))?$/u', $l, $m)) {
                $tasks[] = $this->taskFields($m[1], $m[2] ?? '', $section, $i + 1);
            }
        }
        return $tasks;
    }

    protected function taskFields(string $char, string $text, string $section, int $line): array
    {
        $done = in_array($char, ['x', 'X'], true);
        $f = [
            'text' => $text, 'status' => $char, 'checked' => $char !== ' ', 'completed' => $done,
            'fullycompleted' => $done, 'section' => $section, 'line' => $line, 'tags' => [],
        ];
        // dates à la manière du plugin Tasks d'Obsidian
        $emoji = ['due' => '📅|🗓️?', 'scheduled' => '⏳', 'start' => '🛫', 'created' => '➕', 'completion' => '✅', 'cancelled' => '❌'];
        foreach ($emoji as $key => $re) {
            if (preg_match('/(?:' . $re . ')\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) $f[$key] = $m[1];
        }
        // champs en ligne « [clé:: valeur] » ou « (clé:: valeur) »
        if (preg_match_all('/[\[(]([\p{L}][\p{L}\p{N}_-]*)::[ \t]*([^\])]*)[\])]/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = trim($x[2]);
                if ($v !== '') $f[strtolower($x[1])] = $v;
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}_&#\/])#(\p{L}[\p{L}\p{N}_\/-]*)/u', $text, $m)) {
            $f['tags'] = array_values(array_unique($m[1]));
        }
        return $f;
    }
}
