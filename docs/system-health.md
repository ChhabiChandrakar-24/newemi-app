# System health

`GET /api/v1/system/health` returns safe API, database, queue, integration, device connectivity and command-delivery status. It intentionally omits passwords, tokens, environment values, exception traces and host internals.
