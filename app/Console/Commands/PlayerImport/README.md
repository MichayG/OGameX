# Player import

The player import commands create complete player snapshots from a JSON export and retain an audit file that can be used to delete those accounts.

## Prerequisites

Set one password for all imported accounts in the project `.env`:

```dotenv
PLAYER_IMPORT_PASSWORD=replace-with-a-secure-password
```

If configuration has previously been cached, refresh it:

```bash
docker compose exec ogamex-app php artisan config:clear
```

Each JSON profile must contain a unique `username` and `email`. Players log in with the JSON email and `PLAYER_IMPORT_PASSWORD`.

## Place the JSON file

The development Compose configuration mounts the project root at `/var/www`. Place the export in the project root, for example:

```text
OGameX/
└── players.json
```

It is then available to the application container as `/var/www/players.json`. For other deployments, place or mount the file somewhere readable by the PHP container and pass that container path to the command.

## Import players

```bash
docker compose exec ogamex-app php artisan ogamex:player-import /var/www/players.json
```

The command stops at the first failed player. Each player is imported in its own database transaction, so that player's partial data is rolled back on failure. Players completed earlier in the run remain imported.

An audit JSON file is created at the start of the run under:

```text
storage/app/player-import/
```

Each completed user is flushed to the audit only after its database transaction commits successfully. The command prints the audit path once at startup (and again on failure if the run stopped mid-way). Every entry includes the user ID, username, and email.

The audit also records `sourceFile` (resolved path of the JSON export) and `sourceFileChecksum` (lowercase SHA-256 hex digest of that file's raw bytes). SHA-256 is used deliberately so two import runs that reuse the same filename can still be told apart when their contents differ.

## Roll back an import

Pass the audit file printed by the import command:

```bash
docker compose exec ogamex-app php artisan ogamex:player-import:rollback /var/www/storage/app/player-import/import-20260725-134500.json
```

The command asks for confirmation. For non-interactive use:

```bash
docker compose exec ogamex-app php artisan ogamex:player-import:rollback /var/www/storage/app/player-import/import-20260725-134500.json --force
```

Rollback calls `PlayerService::delete()` for every recorded ID, cleaning the user's planets, queues, fleet missions, messages, highscores, and research. Missing users are skipped. Audit files are deliberately retained for history.
