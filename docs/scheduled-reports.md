# Scheduled reports

Schedules support daily, weekly and monthly frequencies. `reports:run-scheduled` dispatches due records every fifteen minutes; `reports:cleanup` expires private files daily. Production must run `php artisan schedule:run` every minute and a supervised queue worker.
