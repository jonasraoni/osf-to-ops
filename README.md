# OSF to OPS Import

## About
This repository uses the [OSF API](https://developer.osf.io) ([Open Science Framework](https://osf.io)) to gather a list of preprints and converts them to a XML format acceptable by the [PKP OPS (Open Preprint Systems)](https://pkp.sfu.ca/ops) Native Import/Export Plugin.

- The authors will be imported as users, thus, they will be able to access their submissions once they "recover" their accounts (the OPS administrator will have to help), if this isn't desirable, avoid the item 6 and review the file on the item 7, to setup the right owner username.
- As retrieving the user email isn't provided by the API (which is great), the code will import the users using a fake email. The email pattern can be configured through an argument.
- This was built for OPS 3.4.0, future versions might introduce breaking changes.


## Instructions

1. Ensure all OPS data is backed up!

2. Register at http://osf.io and grab an authorization token (https://developer.osf.io/#tag/Authentication) to make API requests.

3. Go to the administration interface, enable and setup the DOI and ORCID plugins at the `Website > Plugins`

4. At the `Workflow > Metadata`, enable the: `Keywords`, `Rights`, `Disciplines` and `Publisher ID`.

5. At the root of this repository, run the command bellow to get more information about the usage, then execute it to produce the import data
```bash
php src/import.php -h
```

Here's a sample command to import preprints:
```bash
php import.php -c OPS_SERVER_NAME -p OSF_PREPRINT_PROVIDER_NAME -d -o output -b https://OPS_BASE_INSTALLATION_URL.org/index.php/OPS_SERVER_NAME/preprint/view -t OSF_API_TOKEN
```

6. After executing the `import.php`, go to the output folder (specified by the `-o` argument) and execute the file `users.sql` in your MySQL console

7. Execute the file `import.sh` to import the XMLs

8. Execute the files `downloads.sql` and `assignments.sql`

9. Execute the file `redirects.sql`, it will output redirect (from http://osf.io to the OPS pattern) statements for Apache