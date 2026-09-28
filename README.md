# Mise à jour Dolibarr

Module externe qui met à jour le code Dolibarr depuis l'interface d'administration, sans télécharger ni extraire l'archive à la main.

## Ce que fait le module

1. Il lit les releases stables `x.y.z` du dépôt GitHub `Dolibarr/dolibarr`.
2. Il ne propose que les versions strictement plus récentes que `DOL_VERSION`. Filtre possible par série / branche majeure (`19`, `21`, `24`…). Les stables sont affichées par défaut. La case « Inclure les versions bêta » ajoute les tags `x.y.z-beta`.
3. Il sauvegarde `conf/conf.php` et une copie de `htdocs` dans `documents/dolibarrupdater/backups/`.
4. Il télécharge `dolibarr-x.y.z.zip` depuis `github.com/Dolibarr/dolibarr/releases` et l'extrait.
5. Il remplace les fichiers de `htdocs`. `conf/conf.php`, tout `custom/` et `htdocs/install.lock` ne sont pas écrasés. Le répertoire `conf/` n'est pas purgé.
6. Il crée `documents/upgrade.unlock` et ouvre `/install/check.php`.

La migration SQL reste l'assistant Dolibarr (`upgrade.php`, `upgrade2.php`, `step5.php`). `step5.php` recrée `install.lock` et supprime `upgrade.unlock`.

## Verrou documents/install.lock

La même page permet de créer ou de supprimer uniquement `documents/install.lock`. Supprimer ce fichier rend `/install` accessible tant qu'aucun autre verrou (`htdocs/install.lock`) n'est présent.

## Mise à jour du module via git

Sur la même page admin : configurez une URL HTTPS (`https://github.com/org/dolibarrupdater.git`) et une branche (`main`, etc.), enregistrez, puis lancez **Mettre à jour le module**. Au premier passage le module est cloné ; ensuite un `fetch` + `reset --hard` est fait. Les modifications locales du module sont écrasées. Le binaire `git` doit être installé sur le serveur, et le compte du serveur web doit pouvoir écrire dans `custom/dolibarrupdater`.

## Droits

Page réservée aux administrateurs. Le serveur web doit pouvoir écrire dans `htdocs` et dans le répertoire de données. Les extensions PHP `curl` et `zip` sont requises. Pour la mise à jour du module, `git` est requis.

## Activation

Accueil > Configuration > Modules, famille Technique, module **Mise à jour Dolibarr**. Le menu est ensuite sous Accueil > Configuration.
