# Purple Music — installation Docker

## Option A — image prête à l'emploi (style MeTube, recommandé)

Une seule commande suffit — aucun clonage du repo, aucun build local :

```bash
docker run -d \
  --name purplemusic \
  -p 51837:80 \
  -v $(pwd)/purplemusic-data:/var/www/purplemusic-data \
  -v $(pwd)/purplemusic-music:/var/www/html/music \
  -v $(pwd)/purplemusic-covers:/var/www/html/covers \
  ghcr.io/axolat000/purplemusic:latest
```

Puis ouvrir `http://localhost:51837` — l'assistant d'installation (nom du site, compte admin, thème) se lance automatiquement au premier accès.

Trois dossiers apparaissent à côté de la commande (`purplemusic-data`, `purplemusic-music`, `purplemusic-covers`) : c'est là que tout est stocké, faciles à sauvegarder ou déplacer.

## Option B — build local (docker compose)

Si tu préfères builder toi-même à partir du code source :

```bash
docker compose up -d
```

Puis `http://localhost:51837`.

## Ce qui est persistant

- `.../data` → `config.php` généré à l'installation + la base SQLite (contient les mots de passe hashés des comptes — **volontairement stocké hors du dossier servi par le serveur web**, jamais accessible en HTTP même en cas d'erreur de configuration)
- `.../music` → les fichiers audio uploadés (servis directement, par design)
- `.../covers` → les pochettes (servies directement, par design)

Le code applicatif (PHP/JS/CSS), lui, vit dans l'image — pour mettre à jour :
```bash
# Option A
docker pull ghcr.io/axolat000/purplemusic:latest
docker stop purplemusic && docker rm purplemusic
# puis relancer la commande docker run du début

# Option B
docker compose build --no-cache && docker compose up -d
```

## Publier l'image sur GHCR (une fois)

Un workflow GitHub Actions (`.github/workflows/docker-publish.yml`) build et publie automatiquement l'image sur `ghcr.io/axolat000/purplemusic` à chaque push sur `main` (multi-architecture : amd64 + arm64, pour que ça tourne aussi sur Raspberry Pi). Rien à configurer — il utilise le jeton `GITHUB_TOKEN` fourni automatiquement par GitHub, pas de secret à créer.

Après le tout premier build, le package est privé par défaut : pour que `docker run ghcr.io/axolat000/purplemusic` fonctionne pour n'importe qui (comme MeTube), il faut le rendre public une fois dans GitHub → onglet **Packages** du profil/repo → `purplemusic` → **Package settings** → **Change visibility** → **Public**.

## Mise à jour en un clic (admin, option B uniquement)

Avec `docker compose`, un sidecar [Watchtower](https://github.com/nickfedor/watchtower) optionnel permet aux
admins de mettre à jour l'app en un clic depuis une popup dans l'interface, sans jamais donner à l'app PHP
elle-même accès au socket Docker (le sidecar est le seul conteneur à le monter, restreint via label à ne
gérer que `purplemusic`).

Pour l'activer :
```bash
cp .env.example .env
# éditer .env et remplacer WATCHTOWER_TOKEN par un jeton aléatoire, ex :
openssl rand -hex 32
docker compose up -d
```

Sans `.env`/`WATCHTOWER_TOKEN`, tout continue de fonctionner normalement : les admins reçoivent quand même la
notification de mise à jour disponible, avec les commandes ci-dessus à copier-coller à la place du bouton
"Mettre à jour". Idem pour l'option A (`docker run` simple) : pas de mise à jour automatique possible, mais la
notification s'affiche toujours pour prévenir qu'une nouvelle version existe.

## Sauvegardes

```bash
tar czf purplemusic-backup.tar.gz purplemusic-data purplemusic-music purplemusic-covers
```

## Tout supprimer (y compris les données)

```bash
# Option A
docker stop purplemusic && docker rm purplemusic
rm -rf purplemusic-data purplemusic-music purplemusic-covers

# Option B
docker compose down -v
```
