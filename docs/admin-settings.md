# Admin settings

Settings under `/api/v1/settings/*` are runtime-editable, permission protected and audited. Secret replacement requires a new value; blank preserves the existing value. `remove_secrets` explicitly removes supported secrets. `APP_KEY`, database credentials and initial administrator remain bootstrap server settings.
