# Bibliothèque vidéo

## Présentation

Cette application est une bibliothèque vidéo permettant d'organiser, parcourir et retrouver facilement des vidéos à partir de leurs noms de fichiers et de leurs métadonnées.

Elle a été conçue pour fonctionner avec une bibliothèque vidéo locale existante, sans nécessiter de renommer les fichiers ni de modifier les fichiers vidéo.

L'application permet notamment de :

* parcourir les vidéos disponibles ;
* rechercher des vidéos ;
* lire les vidéos directement depuis la bibliothèque ;
* attribuer et gérer des tags ;
* filtrer la bibliothèque par tag ;
* retrouver rapidement toutes les vidéos associées à un même tag ;
* enrichir progressivement la bibliothèque grâce à la détection automatique des tags.

---

## Principe des tags

Les tags sont initialement détectés à partir du nom des fichiers vidéo.

Tout texte placé entre parenthèses est considéré comme un tag.

Par exemple :

```text
(Studio Alpha) - Une scène - (Paul Martin).mp4
```

peut produire les tags :

```text
Studio Alpha
Paul Martin
```

Les parenthèses vides sont ignorées.

Les fichiers vidéo ne sont jamais renommés ou modifiés par l'application.

---

## Registre permanent des tags

Les tags connus sont conservés dans un registre permanent.

Ce registre constitue la mémoire de l'application.

Un tag découvert une fois peut donc continuer à être connu même si la vidéo qui l'avait apporté est ensuite supprimée de la bibliothèque.

Cette séparation permet de conserver la connaissance des tags indépendamment des vidéos actuellement présentes.

Les relations entre une vidéo et ses tags sont, elles, liées à la bibliothèque actuelle et peuvent être nettoyées lorsqu'une vidéo disparaît.

---

## Détection intelligente des tags

Le système ne se limite pas aux tags explicitement présents entre parenthèses.

Au fil de l'utilisation, il peut découvrir qu'un tag déjà connu apparaît dans le nom d'une vidéo qui ne possède pas encore ce tag.

Par exemple, si le tag suivant est déjà connu :

```text
Paul
```

et qu'une vidéo possède le nom :

```text
Une histoire avec Paul Martin.mp4
```

l'application peut proposer :

```text
Ajouter le tag :
Paul
```

La proposition doit être validée par l'utilisateur avant que le tag soit ajouté.

Cette approche permet à la bibliothèque de devenir progressivement plus riche sans modifier automatiquement les données existantes.

---

## Validation des suggestions

Les suggestions automatiques sont soumises à l'utilisateur.

Lorsqu'une vidéo possède une ou plusieurs correspondances potentielles, l'utilisateur peut :

* accepter le tag proposé ;
* refuser la proposition ;
* modifier la sélection ;
* ajouter manuellement un autre tag ;
* retirer un tag proposé avant validation.

Aucune modification définitive n'est effectuée sans action de l'utilisateur.

Les refus peuvent être mémorisés afin d'éviter de proposer continuellement la même association.

---

## Ajout manuel de tags

Les tags ne sont pas limités à ceux détectés automatiquement.

Lors de la revue d'une vidéo, il est possible d'ajouter manuellement un tag.

Il est également possible de créer directement un nouveau tag depuis le menu des tags.

Un tag créé manuellement rejoint alors le registre permanent et pourra être utilisé par le système lors des futures recherches et détections.

---

## Menu des tags

Un menu dédié aux tags est accessible depuis le bouton **☰** situé en haut à gauche de l'interface.

Le menu est fermé par défaut afin de conserver un maximum d'espace pour la bibliothèque vidéo.

Lorsqu'il est ouvert, il permet notamment de :

* consulter tous les tags connus ;
* rechercher ou sélectionner un tag ;
* filtrer la bibliothèque par tag ;
* ajouter manuellement un nouveau tag.

Cliquer sur un tag permet de retrouver toutes les vidéos qui lui sont associées.

---

## Recherche et navigation

La bibliothèque permet de rechercher les vidéos à partir de leur nom et de naviguer rapidement entre les contenus.

Les tags constituent une seconde méthode de navigation.

Depuis une vidéo, les tags associés peuvent être sélectionnés pour afficher les autres vidéos utilisant le même tag.

Cela permet de créer progressivement une navigation transversale dans la bibliothèque, indépendamment de l'organisation physique des fichiers.

---

## Conservation des données

Le système distingue volontairement deux types d'informations.

### Tags connus

Les tags connus sont conservés de manière permanente.

Ils représentent la connaissance acquise par l'application et ne sont pas supprimés simplement parce qu'une vidéo disparaît.

### Tags associés aux vidéos

Les associations :

```text
Vidéo → Tags
```

sont conservées pour les vidéos présentes dans la bibliothèque.

Lorsqu'une vidéo est supprimée, son association peut être supprimée sans supprimer pour autant les tags du registre permanent.

Cette architecture permet d'éviter de perdre les connaissances acquises au fil du temps.

---

## Philosophie du système

Le système privilégie une approche progressive et contrôlée :

1. les fichiers vidéo restent inchangés ;
2. les tags sont découverts à partir des noms de fichiers ;
3. les nouveaux tags sont mémorisés ;
4. les correspondances potentielles sont recherchées progressivement ;
5. les modifications proposées sont soumises à l'utilisateur ;
6. les décisions validées enrichissent la bibliothèque ;
7. les connaissances globales sont conservées même lorsque les vidéos disparaissent.

L'objectif est de transformer progressivement une simple collection de fichiers vidéo en une bibliothèque organisée, navigable et enrichie par les tags.
