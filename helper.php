<?php

use dokuwiki\Extension\Plugin;

/**
 * Fmtable : requêtes de type Dataview sur les en-têtes des fiches.
 *
 *   TABLE nom, categorie AS "Catégorie", delivery
 *   FROM "70 Veille/logiciels"
 *   WHERE type = "message-broker" AND contains(delivery, "exactly-once")
 *   SORT throughput_score DESC, nom ASC
 *   FILTERS categorie, delivery
 *   LIMIT 50
 *
 * Les champs sont ceux de l'en-tête (index du plugin fmeta) ; created, updated (ou revue), expire,
 * status, tags, title et file.name sont aussi disponibles.
 */
class helper_plugin_fmtable extends Plugin
{
    // ---- analyse de la requête ----------------------------------------------------------------

    /**
     * @return array{mode:string,cols:array,noid:bool,from:string[],where:?array,sort:array,filters:?array,limit:int,listexpr:string}
     */
    public function parse(string $text): array
    {
        $q = ['mode' => 'TABLE', 'cols' => [], 'noid' => false, 'from' => [], 'where' => null,
            'sort' => [], 'filters' => null, 'limit' => 0, 'listexpr' => ''];

        $parts = preg_split('/^[ \t]*(TABLE|LIST|FROM|WHERE|SORT|FILTERS|LIMIT)\b/im', trim($text), -1,
            PREG_SPLIT_DELIM_CAPTURE);
        // $parts[0] = texte avant le premier mot-clé (ignoré), puis [mot-clé, contenu, ...]
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $kw = strtoupper($parts[$i]);
            $body = trim(preg_replace('/\s+/', ' ', $parts[$i + 1]));
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
                    $q['listexpr'] = $body;
                    break;
                case 'FROM':
                    preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^\s,]+)/', $body, $m, PREG_SET_ORDER);
                    foreach ($m as $x) {
                        $v = ($x[1] ?? '') !== '' ? $x[1] : (($x[2] ?? '') !== '' ? $x[2] : ($x[3] ?? ''));
                        if ($v !== '' && strtoupper($v) !== 'OR') $q['from'][] = $this->toNamespace($v);
                    }
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

    /** Découpe « a, b AS "x, y", c » en éléments, sans couper dans les guillemets */
    protected function splitList(string $s): array
    {
        preg_match_all('/(?:"[^"]*"|\'[^\']*\'|\([^)]*\)|[^,])+/', $s, $m);
        return array_values(array_filter(array_map('trim', $m[0]), 'strlen'));
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
            $cols[] = ['key' => $item, 'label' => $label !== '' ? $label : $item];
        }
        return $cols;
    }

    /** "70 Veille/logiciels" -> 70_veille:logiciels */
    protected function toNamespace(string $path): string
    {
        $segs = preg_split('#[/\\\\:]#', $path, -1, PREG_SPLIT_NO_EMPTY);
        return implode(':', array_filter(array_map('cleanID', $segs), 'strlen'));
    }

    // ---- expressions WHERE ---------------------------------------------------------------------

    protected function tokenize(string $s): array
    {
        $tokens = [];
        $re = '/\s*(?:("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(<=|>=|!=|=|<|>)|([(),])|(-?\d+(?:\.\d+)?)(?![\w-])|([^\s()=<>!,"\']+))/A';
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
        if (isset($t[$i]) && $t[$i] === ['p', '(']) {
            $i++;
            $e = $this->pOr($t, $i);
            if (isset($t[$i]) && $t[$i] === ['p', ')']) $i++;
            return $e;
        }
        $a = $this->pOperand($t, $i);
        if (isset($t[$i]) && $t[$i][0] === 'op') {
            $op = $t[$i][1];
            $i++;
            return ['cmp', $op, $a, $this->pOperand($t, $i)];
        }
        return ['val', $a];
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
                    $args[] = $this->pOperand($t, $i);
                }
                $i++;
                return ['fn', $low, $args];
            }
            return ['field', $v];
        }
        return ['lit', null];
    }

    // ---- valeurs des champs --------------------------------------------------------------------

    /** Valeur brute d'un champ pour une fiche (scalaire, liste ou null) */
    public function value(array $e, string $key)
    {
        $k = strtolower(trim($key));
        switch ($k) {
            case 'file.name':
            case 'name_of_file':
                return noNS($e['id']);
            case 'title':
            case 'file.title':
                return $e['title'] ?? noNS($e['id']);
            case 'created':
            case 'file.ctime':
                return $e['created'] ?: null;
            case 'updated':
            case 'revue':
            case 'modified':
            case 'file.mtime':
                return ($e['revue'] ?? '') ?: null;
            case 'expire':
                return ($e['expire'] ?? '') ?: null;
            case 'status':
            case 'statut':
                return ($e['status'] ?? '') ?: null;
            case 'tags':
                return $e['tags'] ?? [];
        }
        foreach (($e['fields'] ?? []) as $fk => $fv) {
            if (strtolower((string)$fk) === $k) return $fv === '' ? null : $fv;
        }
        return null;
    }

    /** Valeur typée : « true » -> true, « 3 » -> 3 */
    protected function typed($v)
    {
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
                return $this->typed($this->value($e, $node[1]));
            case 'fn':
                return $this->evalNode($e, $node);
        }
        return null;
    }

    protected function same($a, $b): bool
    {
        if (is_bool($a) || is_bool($b)) return (bool)$a === (bool)$b && $a !== null && $b !== null;
        if (is_numeric($a) && is_numeric($b)) return (float)$a === (float)$b;
        return mb_strtolower((string)$a) === mb_strtolower((string)$b);
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
                $v = $this->operandValue($e, $n[1]);
                return is_array($v) ? count($v) > 0 : (bool)$v;
            case 'fn':
                if ($n[1] === 'contains' && count($n[2]) >= 2) {
                    $hay = $this->operandValue($e, $n[2][0]);
                    $needle = $this->operandValue($e, $n[2][1]);
                    if ($hay === null) return false;
                    if (is_array($hay)) {
                        foreach ($hay as $x) if ($this->same($x, $needle)) return true;
                        return false;
                    }
                    return $needle !== null && mb_stripos((string)$hay, (string)$needle) !== false;
                }
                return false;
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

    /** Fiches (de l'index fmeta, lisibles par l'utilisateur) répondant à la requête, triées */
    public function run(array $q): array
    {
        /** @var helper_plugin_fmeta $fm */
        $fm = plugin_load('helper', 'fmeta');
        $rows = [];
        foreach ($fm->load() as $id => $e) {
            if (empty($e['fields']) || auth_quickaclcheck($id) < AUTH_READ) continue;   // pages sans champ propre : exclues
            if ($q['from']) {
                $in = false;
                foreach ($q['from'] as $ns) {
                    if ($ns === '' || strpos($id, $ns . ':') === 0 || $id === $ns) {
                        $in = true;
                        break;
                    }
                }
                if (!$in) continue;
            }
            $e['id'] = $id;
            if ($q['where'] !== null && !$this->evalNode($e, $q['where'])) continue;
            $rows[] = $e;
        }

        $sort = $q['sort'] ?: [['file.name', false]];
        usort($rows, function ($a, $b) use ($sort) {
            foreach ($sort as [$key, $desc]) {
                $x = $this->typed($this->value($a, $key));
                $y = $this->typed($this->value($b, $key));
                if (is_array($x)) $x = implode(',', $x);
                if (is_array($y)) $y = implode(',', $y);
                if ($x === null && $y === null) continue;
                if ($x === null) return 1;                    // sans valeur : toujours en dernier
                if ($y === null) return -1;
                $c = (is_numeric($x) && is_numeric($y)) ? ((float)$x <=> (float)$y)
                    : strcmp(mb_strtolower((string)$x), mb_strtolower((string)$y));
                if ($c !== 0) return $desc ? -$c : $c;
            }
            return 0;
        });
        if ($q['limit'] > 0) $rows = array_slice($rows, 0, $q['limit']);
        return $rows;
    }
}
