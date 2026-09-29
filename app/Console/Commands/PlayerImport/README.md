# Player import

The player import commands create complete player snapshots from a JSON or gzip-compressed JSON export and retain an audit file that can be used to delete those accounts.

## Prerequisites

Set one password for all imported accounts in the project `.env`:

```dotenv
PLAYER_IMPORT_PASSWORD=replace-with-a-secure-password
```

If configuration has previously been cached, refresh it:

```bash
docker compose exec ogamex-app php artisan config:clear
```

Each JSON profile must contain a unique `username` and `email`. Players log in with the email and `PLAYER_IMPORT_PASSWORD`.

## Place the export file

The development Compose configuration mounts the project root at `/var/www`. Place the export in the project root, for example:

```text
OGameX/
├── players.json
└── players.json.gz

Use one OR the other.
```

Plain JSON and gzip-compressed JSON are both accepted. Gzip is detected from the file's magic bytes, so a `.gz` extension is not required. Either file is then available to the application container as `/var/www/players.json` or `/var/www/players.json.gz`. For other deployments, place or mount the file somewhere readable by the PHP container and pass that container path to the command.

Buildings, ships, defenses, research, and resources may omit entries whose level or amount is 0. Missing entries are imported as 0. An entry may still be present with an explicit 0.

## Import players

```bash
docker compose exec ogamex-app php artisan ogamex:player-import /var/www/players.json
# or
docker compose exec ogamex-app php artisan ogamex:player-import /var/www/players.json.gz
```

The command stops at the first failed player. Each player is imported in its own database transaction, so that player's partial data is rolled back on failure. Players completed earlier in the run remain imported.

An audit JSON file is created at the start of the run under:

```text
storage/app/player-import/
```

Each completed user is flushed to the audit only after its database transaction commits successfully. The command prints the audit path once at startup (and again on failure if the run stopped mid-way). Every entry includes the user ID, username, and email.

The audit also records `sourceFile` (resolved path of the export) and `sourceFileChecksum` (lowercase SHA-256 hex digest of that file's raw bytes). For a gzip export the digest is of the compressed file, not the decompressed JSON. SHA-256 is used deliberately so two import runs that reuse the same filename can still be told apart when their contents differ.

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
