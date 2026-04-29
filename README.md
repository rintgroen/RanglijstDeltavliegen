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
- Separate competition scoring section for scorer-managed tasks, IGC uploads,
  LiveTrack24 candidate collection, review/exclusion, scoring, and published
  results. These scored competitions are stored in separate `rankings_scoring_*`
  tables and do not affect the Dutch ranking unless their results are manually
  uploaded through the existing CSV workflow.

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
database/           Optional schema extensions
includes/           Shared application and ranking logic
public/             Public website pages and assets
public/uploads/     Uploaded competition memory photos
scoring/            Scorer magic-login and task scoring workspace
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

   The optional scorer-run competition scoring section also needs the tables in:

   ```sh
   mysql -u <user> -p <database> < database/scoring_schema.sql
   ```

   Re-run the same file after pulling scoring-section updates; statements use
   `CREATE TABLE IF NOT EXISTS`, so new scoring support tables are added without
   recreating existing data.

   The LiveTrack24 track collection pages also run guarded table/column checks
   when used. If the web database user cannot create or alter scoring tables,
   apply the scoring schema changes with a database admin user first.

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

## Competition Scoring Workflow

Admins add allowed scorer e-mail addresses in `admin/scorers.php`. A scorer can
request a magic login link at `scoring/login.php`, create competitions, upload
waypoints, define tasks and start gates, build the task turnpoint sequence, match
uploaded IGC tracks, review candidate tracks per pilot, score the task, and
publish results.

Waypoint upload accepts GPX, SeeYou CUP/CSV with name/lat/lon columns,
OziExplorer WPT, and CompeGPS/FS WPT with WGS84 latitude/longitude or UTM
waypoint records.

Scorers can also add IGC tracklogs directly on a task page. This is useful when
pilots cannot upload themselves or when the scorer has downloaded the file from
a pilot device. Pilot e-mail is optional for scorer-added tracklogs. Scorers can
also add manual minimum-distance, DNF, or ABS entries for pilots without a usable
track.

When a scorer is added, the app sends a welcome email with a link to the scoring
login page. Scorer magic-login emails use the same mail setup.

Pilots upload IGC files at `public/track_upload.php` with only their name,
e-mail address, and tracklog. Tracklogs are matched to tasks later by time window
and task area.

Pilots can also manage a persistent track collection profile at
`public/track_profile.php`. The profile uses a magic link sent to their e-mail
address, stores their score display name and e-mail address, and lets them opt in
or out of automatic LiveTrack24 collection. The app stores the public
LiveTrack24 username, not a LiveTrack24 password.

On a task review page, scorers can use `LiveTrack24 zoeken` to check opted-in
profiles for public LiveTrack24 tracks near the task time window and task area.
Imported LiveTrack24 IGC files are added as candidate tracklogs beside manual
uploads. The scorer still decides which track is used for scoring.

The task review screen has a pilot list beside a single-pilot review panel.
Within each pilot group, the scorer chooses exclude, ABS, DNF, minimum distance,
or one selected track. Other track candidates are kept as alternatives and
excluded from scoring. The task/track map is loaded only for the active pilot
when `Use track` is selected.
Scoring uses the reviewed rows only; finding uploads and LiveTrack24 candidates
is an explicit review step.

The scoring engine is isolated in `includes/scoring.php` and is labelled
`GAP2025`. It implements the first operational pass for task evaluation, point
allocation, time points, leading approximation, and publication. Before using it
as an official replacement for FS, validate the numerical output against known
FS/CIVL test cases for the exact GAP2025 edge cases you need.

## Postmark Setup

Scorer login and welcome emails are sent through Postmark when
`POSTMARK_SERVER_TOKEN` is configured in `config.php`.

1. In Postmark, open or create the Server you want to use for this site.
2. Verify the sender address or domain that will be used as
   `SCORING_MAIL_FROM`.
3. Copy the Server API token from the Server's API Tokens tab.
4. Set these values in `config.php`:

   ```php
   const SITE_BASE_URL = 'https://your-domain.example';
   const SCORING_MAIL_FROM = 'noreply@your-domain.example';
   const SCORING_MAIL_FROM_NAME = 'Ranglijst Deltavliegen';
   const POSTMARK_SERVER_TOKEN = 'your-postmark-server-token';
   const POSTMARK_MESSAGE_STREAM = 'outbound';
   ```

5. Add a scorer in `admin/scorers.php` and check that the welcome email arrives.

For a dry-run test without delivering email, Postmark supports the special server
token `POSTMARK_API_TEST`.

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
- Ensure `public/uploads/scoring/` can be created/written by the web server if
  scorer waypoints, pilot IGC uploads, or LiveTrack24 imports are enabled.
- Use `ADMIN_PASSWORD_HASH` for new installs instead of the legacy
  `ADMIN_PASSWORD` fallback.

## License

No license has been specified yet.
