# Politique de sécurité

## Signaler une vulnérabilité

N'ouvrez pas d'issue publique pour une vulnérabilité exploitable. Utilisez le
signalement privé de vulnérabilité du dépôt GitHub lorsqu'il est activé, ou
contactez le mainteneur par un canal privé indiqué dans son profil GitHub.

Précisez la version concernée, l'impact, les étapes minimales de reproduction
et une proposition de correction si vous en avez une. Ne transmettez pas de
mot de passe, base musicale, fichier audio ou autre donnée personnelle.

## Modèle de sécurité

Muzik est conçu pour un hébergement personnel. L'application PHP gère
l'authentification par session dès qu'au moins un compte existe dans
`data/users.db`. Les mots de passe y sont stockés sous forme de hachages bcrypt
et chaque compte possède son propre catalogue et sa propre racine musicale.

Cette authentification ne chiffre pas les échanges : l'administrateur doit
servir l'application en HTTPS, directement ou derrière un proxy inverse. Une
protection supplémentaire au niveau du serveur web reste possible, mais n'est
pas requise par la configuration Apache fournie.

La route de suppression d'un album efface des fichiers sous `music_root`. Une
sauvegarde de la bibliothèque est recommandée avant toute opération
d'administration.
