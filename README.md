# TileMasterAI

Bootstrap PHP landing page for TileMasterAI.

## Environment configuration
- Create a `.env` file at the project root (not committed to Git) that includes secrets such as `OPENAI_API_KEY`.
- Environment variables are loaded via `config/env.php`. Include this loader in any PHP entrypoint with:
  ```php
  require __DIR__ . '/config/env.php';
  ```
- Access values using `getenv('OPENAI_API_KEY');` and never echo or commit secret values.

## Local usage
Serve the repository with PHP (e.g., `php -S localhost:8000`) and visit the root to confirm the welcome page and environment status indicator.
