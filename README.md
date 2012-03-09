A tool for creating/managing RDS snapshots as backups.  Automatic backups at
RDS are great, but they only stick around for the specified retention time.

This will help you to take snapshots and keep them around as backups for 
weeks, months, years, etc.  There's a lot of extending to do I'm sure but it
works for now.

Two notes:

- All config is done inside the script
- The script will use rds-backup-<timestamp> as a namespacing to separate its
  backups from your own regular manual backups.  It's pretty easy to change,
  but please be aware so you don't step on each other's toes.

Kevin O'Connor <kevino@arc90.com> @gooeyblob
