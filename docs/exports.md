# Exports

`POST /api/v1/reports/generate` queues XLSX or PDF generation. Files use Laravel's private local disk in development and should use a private object-storage disk in production. Downloads are authenticated and ownership/admin authorized. Files expire after seven days. XLSX cells beginning with formula-control characters are prefixed safely.
