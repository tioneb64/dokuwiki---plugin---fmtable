# Fmtable : tableaux de fiches filtrables

Plugin DokuWiki (`fmtable`).

Tableau de fiches **à la Dataview** (`TABLE`, `LIST`, `FROM`, `WHERE`, `SORT`, `LIMIT`) construit depuis les en-têtes des fiches, avec **recherche, filtres par caractéristique et tri en direct**.

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

Les requêtes Dataview `TABLE` et `LIST` d'Obsidian fonctionnent telles quelles ; seule la ligne `FILTERS` est propre au plugin. Les blocs `dataviewjs` ne sont pas pris en charge.

- `WHERE` : comparaisons (`=`, `!=`, `<`, `<=`, `>`, `>=`), `AND`, `OR`, `NOT`, parenthèses, `contains(champ, "valeur")`, `null`.
- Filtres : valeur unique = « ou » ; champ liste = « et » ; oui/non pour les booléens ; `≥ ≤ =` pour les nombres.
- Un clic sur une valeur du tableau l'ajoute aux filtres ; un clic sur un titre de colonne trie.
- Champs : ceux de l'en-tête, plus `title`, `file.name`, `created`, `updated`, `expire`, `status`, `tags`.

## Dépendances

`fmeta`.

## Installation

Copier le contenu de ce dépôt dans `lib/plugins/fmtable/` (le fichier `plugin.info.txt` doit se trouver à la racine de ce dossier), puis vider le cache en rendant la date de `conf/local.php` plus récente (`touch conf/local.php`).

Avec le gestionnaire d'extensions de DokuWiki : *Administration → Gestionnaire d'extensions → Installer*, en indiquant l'adresse du dépôt (archive ZIP).

## Licence

Aucune licence n'a encore été choisie : à ajouter (fichier `LICENSE`) avant de publier le dépôt. DokuWiki est sous GPL-2.0.
