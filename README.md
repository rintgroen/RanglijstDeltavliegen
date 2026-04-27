# Ranglijst Deltavliegen

Public website and admin tools for the Dutch hang gliding ranking. The app
publishes rankings for `Klasse 1` and `Sportklasse`, based on Dutch Nationals
results and WPRS points.

The interface is in Dutch and the codebase is a small, framework-free PHP/MySQL
application.

## Features

- Public ranking pages for `Klasse 1` and `Sportklasse`.
- Pilot profile pages with ranking history.
- Competition list and competition detail pages.
- Explanation page for the ranking calculation.
- Admin dashboard with site statistics.
- Pilot management, including CIVL ID and active status.
- WPRS points management per year and class.
- CSV competition result upload, export, and deletion.
- Visitor memories for competitions, with admin moderation.

## Requirements

- PHP 7.4 or newer.
- MySQL or MariaDB.
- PHP PDO MySQL extension.
- `mbstring` and `iconv` are recommended for UTF-8 handling and name matching.
- A web server that can run PHP, or PHP's built-in server for local development.

No Composer dependencies are required for the normal CSV workflow.

## Project Structure

```text
admin/              Password-protected management pages
includes/           Shared application and ranking logic
public/             Public website pages and assets
public/uploads/     Uploaded competition memory photos
config.example.php  Example local configuration
db.php              Database connection helper
index.php           Front controller redirecting to the public home page
```

## Setup

1. Clone the repository.

   ```sh
   git clone <repo-url>
   cd RanglijstDeltavliegen
   ```

2. Create a local config file.

   ```sh
   cp config.example.php config.php
   ```

3. Edit `config.php` with your local database credentials and admin password
   hash. Generate an admin password hash with:

   ```sh
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Put the generated value in `ADMIN_PASSWORD_HASH`.

4. Create or import the MySQL database.

   This repository currently expects the database schema to exist already. The
   application reads and writes these tables:

   - `rankings_pilots`
   - `rankings_world_points`
   - `rankings_competitions`
   - `rankings_competition_results`
   - `rankings_competition_memories`
   - `rankings_nationals_meta`
   - `rankings_nationals_results`

5. Make uploaded memory photos writable by the web server.

   ```sh
   chmod -R u+w public/uploads
   ```

## Local Development

From the repository root:

```sh
php -S localhost:8000 -t .
```

Then open:

```text
http://localhost:8000/
```

The root `index.php` redirects visitors to the public home page. The admin area
is available at:

```text
http://localhost:8000/admin/
```

Add `?debug=1` to a URL to show extra PHP/database error details while working
locally.

## Competition CSV Format

Competition uploads use CSV files with this shape:

```csv
Piloot,Taak 1,Taak 2,Taak 3,Totaal
Pilot Name,732,814,690,2236
```

The first column is the pilot name. Intermediate columns are task scores. The
last `Totaal` or `Total` column is optional; if it is missing, the app sums the
task columns.

Pilot names are matched against existing pilots by normalized name. If no match
is found, the uploaded result is still stored with the original pilot name.

## Ranking Method

For modern years, the ranking combines:

- Dutch Nationals score for the selected year.
- Dutch Nationals score for the previous year, weighted at 50%.
- WPRS score for the selected year, weighted at 150%.

For years up to and including 2008, the historical method uses three Dutch
Nationals results with descending weights. The public explanation page contains
the complete formula.

## Deployment Notes

- Keep `config.php` out of Git. It contains credentials and is already ignored.
- Point your web server at the repository root, or route traffic directly to
  `public/` depending on the hosting setup.
- Ensure `public/uploads/memories/` is writable if visitors can upload memory
  photos.
- Use `ADMIN_PASSWORD_HASH` for new installs instead of the legacy
  `ADMIN_PASSWORD` fallback.

## License

No license has been specified yet.
