# Delivery and reconciliation reliability

The system assumes at-least-once hints and delivery. Room command UUID records prevent repeated Android side effects; Laravel row locks prevent concurrent dispatch; command TTL is checked before every push and pull; worker runs drain at most five commands. FCM, periodic WorkManager, network recovery, boot recovery, app start, and manual sync all converge on the authenticated pull endpoint.

Offline or failed push leaves the command queued. Invalid push tokens are retired. Temporary provider failures use bounded Laravel queue retry. Duplicate wake-ups use unique Android work and cannot apply an FCM payload directly. Desired state changes at queue time; actual state changes only after a successful authenticated result.

Production operations require one-minute cron invocation of `php artisan schedule:run`, queue workers, failed-job monitoring, HTTPS, database backups, and alerting on push/command failures. Scheduled tasks cover command expiry, policy evaluation, offline detection, and location retention.

Direct-boot FCM is intentionally not enabled: device credentials are credential-protected and must not be accessed before user storage unlock. Normal `BOOT_COMPLETED`, polling, connectivity, and FCM reconciliation recover safely afterward.
