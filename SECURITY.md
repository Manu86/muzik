# Politique de sécurité

## Signaler une vulnérabilité

N'ouvrez pas d'issue publique pour une vulnérabilité exploitable. Utilisez le
signalement privé de vulnérabilité du dépôt GitHub lorsqu'il est activé, ou
contactez le mainteneur par un canal privé indiqué dans son profil GitHub.

Précisez la version concernée, l'impact, les étapes minimales de reproduction
et une proposition de correction si vous en avez une. Ne transmettez pas de
mot de passe, base musicale, fichier audio ou autre donnée personnelle.

## Modèle de sécurité

Muzik est conçu pour un hébergement personnel. L'application PHP ne fournit pas
elle-même l'authentification : l'administrateur doit protéger le service au
niveau du serveur web ou d'un proxy inverse et utiliser HTTPS.

La route de suppression d'un album efface des fichiers sous `music_root`. Une
sauvegarde de la bibliothèque est recommandée avant toute opération
d'administration.
