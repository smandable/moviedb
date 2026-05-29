# MovieDB

A local movie file cataloging and management application built with Angular and PHP. MovieDB scans directories for video files, extracts metadata using FFprobe, detects duplicates, and provides an interactive grid-based interface for browsing and managing your movie library.

## Features

- **Movie Library Grid** — Browse your entire collection in a sortable, filterable AG Grid table with inline editing
- **Metadata Extraction** — Automatically extracts video dimensions, duration, and file size via FFprobe
- **Duplicate Detection** — Identifies duplicate files across volumes and flags size differences
- **Batch File Renaming** — Scan for and normalize inconsistent file names in bulk
- **External Drive Search** — Search for titles across mounted macOS volumes via Finder smart folders
- **Internationalization** — i18n support via ngx-translate (English by default)

## Tech Stack

| Layer     | Technology                                                                    |
|-----------|-------------------------------------------------------------------------------|
| Frontend  | Angular 21, TypeScript 5.9, RxJS 7.8                                         |
| UI        | AG Grid 33, Bootstrap 5.3, Tailwind CSS, ng-bootstrap 20, Font Awesome 6    |
| Backend   | PHP 7+, MySQL / MariaDB                                                      |
| Tooling   | Angular CLI 21, Karma + Jasmine, PostCSS                                     |
| Metadata  | FFprobe (via Homebrew)                                                        |

## Prerequisites

- [Node.js](https://nodejs.org/) v20+
- npm (included with Node.js)
- PHP 7+ with the MySQLi extension
- MySQL or MariaDB
- [FFprobe](https://ffmpeg.org/) — install via Homebrew:
  ```bash
  brew install ffmpeg
  ```
- A local web server that can serve PHP (e.g. [MAMP](https://www.mamp.info/), Apache, or PHP's built-in server)

## Getting Started

### 1. Clone the repository

```bash
git clone https://github.com/smandable/moviedb.git
cd moviedb
```

### 2. Install dependencies

```bash
npm install
```

### 3. Set up the database

Create a MySQL database and table for storing movie records:

```sql
CREATE DATABASE movieLibrary;

USE movieLibrary;

CREATE TABLE movies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255),
  dimensions VARCHAR(50),
  duration FLOAT,
  filesize BIGINT,
  date_created DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 4. Configure the PHP backend

Copy the example environment file and fill in your credentials:

```bash
cp server/.env.example server/.env
```

Edit `server/.env` with your database details:

```
DB_HOST=localhost:3306
DB_USERNAME=your_username
DB_PASSWORD=your_password
DB_DATABASE=movieLibrary
DB_TABLE=movies
UPDATE_MISSING_DATA_ONLY=false
```

Both `server/.env` and `server/config.php` are gitignored.

### 5. Start the PHP server

Ensure your PHP server (MAMP, Apache, etc.) is running and serves the `server/` directory at the URL configured in `src/environments/environment.ts`. The default development URL is:

```
http://localhost:8888/moviedb/server/
```

### 6. Start the development server

```bash
ng serve
```

Navigate to `http://localhost:4200`. The app will automatically reload on source changes.

## Available Scripts

| Command              | Description                                      |
|----------------------|--------------------------------------------------|
| `ng serve`           | Start the Angular dev server on port 4200        |
| `npm run build`      | Build the application                            |
| `npm run build:prod` | Build with production optimizations              |
| `npm run start:prod` | Serve the production build on port 4200          |
| `npm run watch`      | Build in watch mode (development config)         |
| `npm test`           | Run unit tests via Karma                         |
| `npm run add-page`   | Scaffold a new page module and component         |

## Project Structure

```
moviedb/
├── src/
│   ├── app/
│   │   ├── pages/
│   │   │   ├── home/                  # Movie library grid view
│   │   │   ├── update-db/             # File scanning & DB sync
│   │   │   └── not-found/             # 404 page
│   │   ├── shared/
│   │   │   ├── components/            # Reusable UI (toast, modals, layout, grid)
│   │   │   ├── services/              # App, Movie, File, and Store services
│   │   │   ├── helpers/               # Formatters, grid theme, storage, string utils
│   │   │   ├── enums/                 # Endpoint, environment, storage key enums
│   │   │   └── directives/            # Custom directives
│   │   ├── app.routes.ts              # Route definitions (lazy-loaded)
│   │   └── app.config.ts              # App providers & configuration
│   ├── assets/
│   │   ├── scss/                      # Global styles and variables
│   │   ├── i18n/                      # Translation files (en.json)
│   │   └── img/                       # Favicons and images
│   └── environments/                  # Dev and prod environment configs
├── server/                            # PHP backend API
│   ├── db_connect.php                 # Database connection
│   ├── getAllMovies.php               # Fetch all movies
│   ├── editCurrentRow.php             # Update a movie record
│   ├── deleteRow.php                  # Delete a movie record
│   ├── processFilesForDB.php          # Scan directory & extract metadata
│   ├── checkFileNamesToNormalize.php  # Check files needing renaming
│   ├── renameTheFilesToNormalize.php  # Batch rename files
│   └── openExternalDriveSearch.php    # macOS Finder search
├── angular.json                       # Angular CLI configuration
├── tsconfig.json                      # TypeScript config with path aliases
├── karma.conf.js                      # Test runner configuration
└── package.json
```

## API Endpoints

All endpoints are served by the PHP backend. The base URL is configured in `src/environments/`.

| Endpoint                          | Method | Description                                          |
|-----------------------------------|--------|------------------------------------------------------|
| `getAllMovies.php`                | GET    | Retrieve all movie records ordered by date created   |
| `editCurrentRow.php`             | POST   | Update one or more fields on a movie record          |
| `deleteRow.php`                  | POST   | Delete a movie record by ID                          |
| `processFilesForDB.php`          | POST   | Scan a directory, extract metadata, find duplicates  |
| `checkFileNamesToNormalize.php`  | POST   | Check which files in a directory need renaming       |
| `normalizeName.php`              | POST   | Normalize a single base name (rename-modal preview)  |
| `renameTheFilesToNormalize.php`  | POST   | Execute batch file renaming                          |
| `openExternalDriveSearch.php`    | POST   | Open a macOS Finder smart folder search              |

## Configuration

### Environment Files

Environment-specific settings live in `src/environments/`:

- **`environment.ts`** — Development (API at `localhost:8888/moviedb/server/`)
- **`environment.prod.ts`** — Production (API at `localhost:8888/moviedb/server/`, same as development)

### TypeScript Path Aliases

The project uses path aliases for clean imports (configured in `tsconfig.json`):

```typescript
import { MovieService } from '@services/movie.service';
import { fileSizeFormatter } from '@helpers/formatters.helper';
import { EndpointEnum } from '@enums/endpoint.enum';
```

### Supported Video Formats

MP4, MKV, AVI, MOV, WMV, MPG, M4V, DIVX
