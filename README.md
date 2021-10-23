# OSF Import

## About
This repository uses the OSF's API (https://developer.osf.io/) to gather a list of preprints and converts them to a format acceptable by the OPS Native Import/Export Plugin.

More help about the usage can be found by running:


## Instructions

1. Register at http://osf.io and grab an authorization token (https://developer.osf.io/#tag/Authentication) to make API requests.

2. Ensure all data is backed up! Enable and setup the DOI and ORCID plugins and on the `Workflow > Metadata`, enable the: `Keywords`, `Subjects` and `Publisher ID`.

3. Run the command bellow to get more information about the available arguments
```php
php src/import.php -h
```

4. After executing the `import.php`, go to the output folder and execute the file `users.sql` in your MySQL console

5. Execute the file `import.sh`

6. Execute the files `downloads.sql` and `assignments.sql`

7. Execute the file `redirects.sql`, it will output redirect (from http://osf.io to the OPS pattern) statements for Apache