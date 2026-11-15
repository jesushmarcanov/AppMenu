-- AppMenu · verificación en dos pasos (TOTP)
-- El secreto se guarda cifrado con APP_KEY (AES-256-GCM).
-- Los códigos de recuperación se guardan como hashes bcrypt en JSON.

ALTER TABLE users
    ADD COLUMN two_factor_secret TEXT NULL AFTER password_hash,
    ADD COLUMN two_factor_enabled_at DATETIME NULL AFTER two_factor_secret,
    ADD COLUMN two_factor_recovery_codes TEXT NULL AFTER two_factor_enabled_at;
