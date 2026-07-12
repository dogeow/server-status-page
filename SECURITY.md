# Security Policy

## Reporting a vulnerability

Please report security issues through the repository's private security advisory feature. Do not open a public issue containing credentials, exploit details, or production infrastructure information.

## Deployment secrets

Never commit production `.env` files, private keys, certificates, database dumps, Agent state, logs, host inventories, subscription tokens, or server-specific deployment files. Keep monitor credentials in Agent-local environment variables or secret files and rotate any value that may have been exposed.
