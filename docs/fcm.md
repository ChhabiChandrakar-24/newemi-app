# Firebase command wake-up

FCM is a minimal wake-up channel, never the command authority. Laravel first persists and authorizes a device command, then queues `SendDeviceCommandPushJob` containing only the command ID. The job fetches the current encrypted active token and sends `type=device_command_available`, command UUID, and protocol version. No customer, financial, command payload, authentication secret, or Firebase credential enters FCM.

Configure server-only secrets:

```dotenv
FIREBASE_ENABLED=true
FIREBASE_PROJECT_ID=your-project
FIREBASE_CREDENTIALS=D:\secure\firebase-service-account.json
QUEUE_CONNECTION=database
```

The credentials file must stay outside source control and readable only by the service account. The HTTP v1 provider creates a short-lived OAuth assertion with OpenSSL. Production requires PHP OpenSSL and an HTTPS-capable HTTP client.

Run a persistent queue worker for `device-push`. Jobs retry at 30/120/300 seconds and re-check command activity/expiry each time. Invalid/unregistered tokens are deactivated. Transient failures do not alter commands; polling remains fallback. `php artisan devices:push-health` displays aggregate counts without tokens.

Push sent means only that FCM accepted the wake-up. Command received/applied timestamps remain separate execution evidence.
