# Query Engine

The Query Engine is the main API and web interface for the Moogle search engine. It provides endpoints and web pages for searching web pages and images, retrieving page metadata, exploring page connections (outlinks and backlinks), and viewing search statistics. The Query Engine is built with Laravel and serves as the bridge between users and the indexed data stored in MongoDB.

## Features

- **Keyword Search**: Search for web pages using keywords, ranked by TF-IDF and PageRank.
- **Image Search**: Search for images using keywords and view associated metadata.
- **Suggestions**: Provides search suggestions and fuzzy matching for misspelled queries.
- **Page Connections**: Explore outlinks and backlinks for any indexed page.
- **Statistics**: View search statistics, top searches, and random page recommendations.
- **Web Interface**: User-friendly frontend for searching and browsing results.
- **REST API**: JSON endpoints for integration with other services or clients.

## API Endpoints

### SearX-compatible endpoint (recommended for Wax)

`GET /search`

Query params:
- `q` (required): search text
- `categories`: `general` (default), `images`, or `videos`
- `pageno`: page number (default: `1`)
- `per_page`: results per page (default `20`, max `50`)
- `format=json` (optional, accepted for compatibility)

Example:

```bash
curl "https://<your-render-service>.onrender.com/search?q=privacy+tools&categories=general&pageno=1&format=json"
```

The response includes:
- `results` array with `url`, `title`, and `content`
- `number_of_results`

### Versioned API route

`GET /api/v1/search`

Same behavior and query params as `/search`.

## Setup

### Using Docker

The recommended way to run the Query Engine is with Docker. This ensures all dependencies are handled and the service runs in an isolated environment.

1. **Install Docker**:  
   Follow the instructions for your OS on the [Docker website](https://docs.docker.com/get-docker/).

2. **Configure Environment Variables**:  
   Create a `.env` file in the `services/query-engine` directory with the following content (adjust as needed):
   ```env
   APP_KEY=base64:your_app_key_here
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost

   MONGODB_URI=mongodb://<mongo_user>:<mongo_password>@<mongo_host>:<mongo_port>/<mongo_db>?authSource=admin
   MONGODB_DATABASE=<mongo_db>
   REDIS_HOST=<your_redis_host>
   REDIS_PASSWORD=<your_redis_password>
   REDIS_PORT=<your_redis_port>
   ```

3. **Build and Run**:  
   In the `services/query-engine` directory, run:
   ```bash
   docker compose build
   docker compose up
   ```

### Without Docker
The process of running the Query Engine without Docker is a bit more involved, as it requires setting up the environment manually. I will update this README with the necessary steps to run the Query Engine without Docker in the future. For now, please refer to the official Laravel documentation for setting up a Laravel application locally: [Laravel Installation](https://laravel.com/docs/installation).

## Render Deployment Notes

Set these environment variables in Render:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<your-service>.onrender.com`
- `APP_KEY=<run "php artisan key:generate --show" locally and paste the output>`
- `CORS_ALLOWED_ORIGIN=https://wax-0j4o.onrender.com` (or `*` while testing)
- `MONGODB_URI=<your_mongodb_uri>`
- `MONGODB_DATABASE=<your_mongodb_database>`
- `REDIS_HOST=<your_redis_host>`
- `REDIS_PORT=<your_redis_port>`
- `REDIS_PASSWORD=<your_redis_password_or_empty>`
- `CACHE_STORE=file`
- `SESSION_DRIVER=file`
- `QUEUE_CONNECTION=sync`
