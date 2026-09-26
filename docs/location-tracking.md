# Consent-based device location

Location consent is separate from general device-management consent. An authorized user records explicit consent and requested mode through `PUT /devices/{device}/location-settings`; Android permission remains independently controlled by the user/system. Disabling records withdrawal, disables future uploads, and queues policy reconciliation when supported.

Modes are `disabled`, `foreground_only`, and `background_allowed`. Backend enablement never fabricates Android permission. The authenticated device endpoint rejects reports unless enablement and active consent both exist. Coordinates have strict bounds, timestamps are limited to one day old/five minutes future, submission is rate-limited, and ordinary audit logs never contain coordinates.

Viewing requires `devices.location.view`; management requires `devices.location.manage`. No public URL, FCM payload, ordinary heartbeat, or device command contains coordinates. `devices:purge-old-locations` removes coordinates older than `DEVICE_LOCATION_RETENTION_DAYS` (default 30) and runs daily.

Background mode still requires Android's separate permission flow and applicable Google Play/distribution approval. Permission alone does not guarantee store approval. Final consent wording requires company/legal review.
