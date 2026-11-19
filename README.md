# Fmtable : tableaux de fiches filtrables

Plugin DokuWiki (`fmtable`).

Tableau de fiches **à la Dataview** (`TABLE`, `LIST`, `TASK`, `CALENDAR`, `FROM`, `WHERE`, `SORT`, `FLATTEN`, `GROUP BY`, `LIMIT`) construit depuis les en-têtes des fiches, avec **recherche, filtres par caractéristique et tri en direct**.

## Utilisation

```
<fmtable>
TABLE name AS "Broker", category AS "Catégorie", delivery AS "Garanties", replay
FROM "70 Veille/logiciels/message-broker"
WHERE type = "message-broker" AND throughput_score >= 3
SORT throughput_score DESC, name ASC
FILTERS category, delivery, replay
LIMIT 50
</fmtable>
```

Les requêtes Dataview `TABLE`, `LIST`, `TASK` et `CALENDAR` d'Obsidian fonctionnent telles quelles, sur une ou plusieurs lignes ; seule la ligne `FILTERS` est propre au plugin. Les blocs `dataviewjs` ne sont volontairement pas exécutés (risque de sécurité) : réécrivez-les en requêtes. Le langage accepte aussi `choice(cond, a, b)` et les opérations `+ - * /` (opérateur entouré d'espaces), par exemple pour un score calculé ; une date accepte aussi `+`/`-` avec une durée (`today - "30d"`).

- `WHERE` : comparaisons (`=`, `!=`, `<`, `<=`, `>`, `>=`), `AND`, `OR`, `NOT`, parenthèses, `contains(champ, "valeur")`, `null`.
- Filtres : valeur unique = « ou » ; champ liste = « et » ; oui/non pour les booléens ; `≥ ≤ =` pour les nombres.
- Un clic sur une valeur du tableau l'ajoute aux filtres ; un clic sur un titre de colonne trie.
- Champs : ceux de l'en-tête, plus `title`, `file.name`, `file.link`, `file.path`, `file.folder`, `created`, `updated`, `revue`, `expire` (saisie, à défaut calculée par `fmlife`), `status`, `tags`, et les dates `today`, `tomorrow`, `yesterday`, `sow`, `eow`, `som`, `eom`, `soy`, `eoy`.
- Fonctions : `contains`, `startswith`, `endswith`, `length`, `lower`, `upper`, `round`, `default`, `date`, `sum`, `min`, `max`, `average`, `join`, `unique`, `sort`, `reverse` ; négation `NOT` ou `!`. Pas d'opérateurs arithmétiques ni de `dur()`.

### `FROM` : dossiers et hashtags

```
LIST FROM #projet
LIST FROM "30_projets" and #cicd
LIST FROM (#projet or #ia) and -"99_test"
```

`"dossier"` (espace de noms) et `#tag` se combinent avec `and`, `or`, `-` et des parenthèses ; des éléments juxtaposés valent `or`. `#a` retient aussi `#a/b`. Les hashtags viennent de l'en-tête et du texte des pages (index `fmeta`). Quand `FROM` contient un hashtag, les pages sans champ propre sont retenues ; sinon elles restent exclues. `FROM [[page]]` n'est pas géré.

### `GROUP BY`

```
TABLE WITHOUT ID key AS "Statut", length(rows) AS "Nombre", rows.file.link AS "Pages"
FROM "30_projets"
GROUP BY status
```

Colonnes d'un regroupement : `key` (ou le nom donné par `AS`), `rows.champ` (liste des valeurs du groupe), `rows.file.link`, `length(rows)`, `sum(rows.x)`, `join(unique(rows.tags), ", ")`. Écart avec Dataview : un champ liste (`tags`) place la fiche dans un groupe par élément. `LIMIT` porte sur les groupes. Un tableau regroupé n'a pas de filtres en direct.

### `TASK`

```
TASK
FROM "10_pilotage"
WHERE !completed AND due <= date(today)
GROUP BY file.link
```

Liste les tâches `- [ ]`, `- [x]`, `- [/]`… des pages (hors blocs de code), par page ou selon `GROUP BY`. Champs : `text`, `status` (caractère entre crochets), `checked`, `completed`, `section`, `tags`, `line`, dates `📅` (`due`), `⏳` (`scheduled`), `🛫` (`start`), `➕` (`created`), `✅` (`completion`), champs `[clé:: valeur]`, plus ceux de la page. Les cases sont en lecture seule (la page d'origine se modifie depuis sa propre page) et reprennent le style du plugin `tasklist` s'il est présent. Chaque affichage relit les pages concernées : limiter avec `FROM`.

### `FLATTEN`

`FLATTEN champ AS nom` : une ligne par élément d'un champ liste ; `nom` désigne l'élément dans les colonnes, `WHERE`, `SORT` et `GROUP BY`. Appliqué avant `WHERE` ; une liste vide supprime la ligne ; plusieurs `FLATTEN` s'enchaînent.

### `CALENDAR`

```
CALENDAR revue
FROM "30_projets" and #cicd
```

Calendrier mensuel (semaines du lundi, navigation par mois, jour courant encadré) : chaque page est placée à la date du champ donné (`created`/`file.cday`, `updated`/`file.mday`, `revue`, `expire` ou un champ de l'en-tête au format `AAAA-MM-JJ` ou `JJ/MM/AAAA` ; une liste de dates place la page à chacune). `FROM`, `WHERE` et `LIMIT` s'appliquent ; les pages sans champ propre sont retenues. Dessiné en JavaScript à partir de `data-events` ; sans JavaScript, une liste « date : pages » s'affiche.

## Dépendances

`fmeta`.

## Installation

Copier le contenu de ce dépôt dans `lib/plugins/fmtable/` (le fichier `plugin.info.txt` doit se trouver à la racine de ce dossier), puis vider le cache en rendant la date de `conf/local.php` plus récente (`touch conf/local.php`).

Avec le gestionnaire d'extensions de DokuWiki : *Administration → Gestionnaire d'extensions → Installer*, en indiquant l'adresse du dépôt (archive ZIP).

## Licence

Aucune licence n'a encore été choisie : à ajouter (fichier `LICENSE`) avant de publier le dépôt. DokuWiki est sous GPL-2.0.
