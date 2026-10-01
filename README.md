# Automation Dashboard

Dashboard PHP léger pour gérer des règles d'automatisation en Lua et suivre les événements reçus. Aucune dépendance : PHP et SQLite uniquement.

## Fonctionnalités

- Créer, modifier, mettre en pause et supprimer des règles (script Lua + événement déclencheur)
- Émettre un événement de test avec un payload JSON
- Historique des derniers événements
- Protection par mot de passe optionnelle, protection CSRF, requêtes préparées

## Démarrage

Prérequis : PHP 8.1+ avec l'extension `pdo_sqlite`.

```bash
git clone https://github.com/OWNER/automation-dashboard.git
cd automation-dashboard
DASH_PASSWORD=secret php -S localhost:8080 -t public
```

Ouvre ensuite http://localhost:8080. La base `data/app.db` est créée automatiquement.

## Configuration

| Variable | Rôle | Défaut |
|---|---|---|
| `DASH_PASSWORD` | Mot de passe du dashboard (aucune authentification si vide) | vide |
| `DB_PATH` | Chemin du fichier SQLite | `data/app.db` |

## Base de données

Le dashboard partage la base SQLite avec le reste de la plateforme (serveur Go, workers Python).

```sql
rules  (id, name, event, script, enabled, created_at)
events (id, name, payload, created_at)
```

Le serveur lit les règles actives dont `event` correspond à l'événement reçu, puis exécute leur `script` Lua en exposant le payload JSON sous la variable `event`.

```lua
if event.temp > 25 then
    notify("Trop chaud")
end
```

## Production

Ne jamais exposer le dashboard sans `DASH_PASSWORD` et sans HTTPS (reverse proxy Caddy ou Nginx). Seul le dossier `public/` doit être servi.

## Licence

MIT
