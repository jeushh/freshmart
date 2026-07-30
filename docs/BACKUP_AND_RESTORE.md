# Backup and restore

## Backup

From `apps/api`:

```bash
php artisan freshmart:backup
```

The command uses SQLite `VACUUM INTO`, so it creates a consistent snapshot
while the API is serving normal traffic. The default directory is
`storage/app/backups`. Each `.sqlite` file has a `.manifest.json` containing
the creation time, application version, size, filename, and SHA-256 checksum.
Files are created with owner-only permissions where the operating system
supports them.

Retention defaults to the `backup_retention_count` system setting. Override it
for a run with `--retention=20`, or select a protected destination with
`--directory=/absolute/path`.

Use an operating-system scheduler for regular backups. The application does
not silently create a scheduler or claim that a backup has been copied
off-host. Monitor command exit status and copy verified backups to encrypted,
access-controlled storage according to your retention policy.

## Restore

Restoring replaces application data. Before proceeding:

1. stop every API server, queue worker, and scheduled process;
2. verify `DB_DATABASE` identifies the modern Laravel database;
3. select a backup and its matching manifest in the configured backup
   directory; and
4. retain an off-host copy.

Then run:

```bash
php artisan freshmart:restore freshmart-YYYYMMDD-HHMMSS.sqlite --confirm
```

The command refuses to run without `--confirm`. It verifies directory
containment, extension, manifest shape, checksum, SQLite integrity, and
foreign keys. It creates a `pre-restore-*.sqlite` safety backup before atomic
replacement and reapplies that safety copy if validation or replacement
fails.

After restore, run:

```bash
php artisan freshmart:health
php artisan migrate:status
```

Start the API only after both checks pass and a representative login/report
smoke test succeeds.

## Legacy protection and drills

Both commands refuse to target the preserved
`database/freshmart.sqlite`. Never rename that reference file to bypass the
guard.

Practice restores on an isolated copy at least quarterly. A backup is not
operationally proven until its checksum, integrity, foreign keys, migrations,
and application smoke tests have passed in a restore drill.
