<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Models\RateLimit;
use App\Models\Setting;
use App\Models\User;
use App\Services\Totp;
use App\Services\TwoFactor;
use App\Support\QrCode;

/**
 * Gestión de la verificación en dos pasos de la cuenta autenticada.
 */
final class TwoFactorController
{
    private const SETUP_TTL = 600;

    public function status(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $codes = TwoFactor::decodeRecoveryCodes((string) ($user['two_factor_recovery_codes'] ?? ''));

        return Response::success([
            'enabled' => !empty($user['two_factor_enabled_at']),
            'enabled_at' => $user['two_factor_enabled_at'] ?? null,
            'recovery_codes_remaining' => count($codes),
            'available' => Crypto::isConfigured(),
        ]);
    }

    public function setup(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if (!empty($user['two_factor_enabled_at'])) {
            throw new ApiException('TWO_FACTOR_ALREADY_ENABLED', 'La verificación en dos pasos ya está activa.', 409);
        }

        if (!Crypto::isConfigured()) {
            throw new ApiException(
                'TWO_FACTOR_UNAVAILABLE',
                'Falta configurar APP_KEY para poder activar la verificación en dos pasos.',
                503,
            );
        }

        $secret = TwoFactor::generateSecret();
        Session::set('_2fa_setup', ['secret' => $secret, 'started' => time()]);

        $issuer = $this->issuer();
        $account = (string) $user['email'];
        $uri = TwoFactor::provisioningUri($secret, $account, $issuer);
        $svg = QrCode::svg($uri, 7, 4, '#141210', '#ffffff');

        return Response::success([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'issuer' => $issuer,
            'account' => $account,
            'expires_in' => self::SETUP_TTL,
        ]);
    }

    public function enable(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'code' => 'required|string|maxlen:20',
        ]);

        $user = Auth::user();
        if (!empty($user['two_factor_enabled_at'])) {
            throw new ApiException('TWO_FACTOR_ALREADY_ENABLED', 'La verificación en dos pasos ya está activa.', 409);
        }

        $pending = Session::get('_2fa_setup');
        if (
            !is_array($pending)
            || !isset($pending['secret'], $pending['started'])
            || time() - (int) $pending['started'] > self::SETUP_TTL
        ) {
            Session::forget('_2fa_setup');
            throw new ApiException(
                'TWO_FACTOR_SETUP_EXPIRED',
                'La configuración expiró. Genera un nuevo código QR.',
                422,
            );
        }

        $userId = (int) $user['id'];
        RateLimit::hit('2fa-enable:' . $request->ip() . ':' . $userId, 10, 900);

        if (!Totp::verify((string) $pending['secret'], (string) $data['code'])) {
            audit('auth.2fa_setup_failed', 'users', $userId);

            throw new ApiException('INVALID_2FA_CODE', 'El código no es válido. Revisa la hora de tu dispositivo.', 422, [
                'code' => 'Código incorrecto o expirado.',
            ]);
        }

        $codes = TwoFactor::generateRecoveryCodes();
        User::enableTwoFactor(
            $userId,
            TwoFactor::encryptSecret((string) $pending['secret']),
            TwoFactor::hashRecoveryCodes($codes),
        );

        Session::forget('_2fa_setup');
        audit('auth.2fa_enabled', 'users', $userId);

        return Response::success([
            'enabled' => true,
            'enabled_at' => now(),
            'recovery_codes' => $codes,
        ]);
    }

    public function disable(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'password' => 'required|string',
            'code' => 'required|string|maxlen:20',
        ]);

        $user = Auth::user();
        if (empty($user['two_factor_enabled_at'])) {
            throw new ApiException('TWO_FACTOR_NOT_ENABLED', 'La verificación en dos pasos no está activa.', 409);
        }

        $userId = (int) $user['id'];
        $this->requirePassword((string) $data['password'], $user);
        RateLimit::hit('2fa-disable:' . $request->ip() . ':' . $userId, 10, 900);

        $viaRecovery = false;
        if (!TwoFactor::verifyCode((string) ($user['two_factor_secret'] ?? ''), (string) $data['code'])) {
            $viaRecovery = User::consumeRecoveryCode($userId, (string) $data['code']);
            if (!$viaRecovery) {
                throw new ApiException('INVALID_2FA_CODE', 'El código no es válido.', 422, [
                    'code' => 'Código incorrecto o expirado.',
                ]);
            }
        }

        User::disableTwoFactor($userId);
        audit('auth.2fa_disabled', 'users', $userId, ['via' => $viaRecovery ? 'recovery' : 'totp']);

        return Response::success(['enabled' => false]);
    }

    public function recoveryCodes(Request $request, array $params = []): Response
    {
        $data = Validator::validate($request->body(), [
            'password' => 'required|string',
            'code' => 'required|string|maxlen:20',
        ]);

        $user = Auth::user();
        if (empty($user['two_factor_enabled_at'])) {
            throw new ApiException('TWO_FACTOR_NOT_ENABLED', 'La verificación en dos pasos no está activa.', 409);
        }

        $userId = (int) $user['id'];
        $this->requirePassword((string) $data['password'], $user);
        RateLimit::hit('2fa-recovery:' . $request->ip() . ':' . $userId, 10, 900);

        $viaRecovery = false;
        if (!TwoFactor::verifyCode((string) ($user['two_factor_secret'] ?? ''), (string) $data['code'])) {
            $viaRecovery = User::consumeRecoveryCode($userId, (string) $data['code']);
            if (!$viaRecovery) {
                throw new ApiException('INVALID_2FA_CODE', 'El código no es válido.', 422, [
                    'code' => 'Código incorrecto o expirado.',
                ]);
            }
        }

        $codes = TwoFactor::generateRecoveryCodes();
        User::updateRecoveryCodes($userId, TwoFactor::hashRecoveryCodes($codes));
        audit('auth.2fa_recovery_regenerated', 'users', $userId, ['via' => $viaRecovery ? 'recovery' : 'totp']);

        return Response::success(['recovery_codes' => $codes]);
    }

    /** @param array<string, mixed> $user */
    private function requirePassword(string $password, array $user): void
    {
        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new ApiException('INVALID_PASSWORD', 'La contraseña actual no es correcta.', 422, [
                'password' => 'La contraseña actual no es correcta.',
            ]);
        }
    }

    private function issuer(): string
    {
        $settings = Setting::public('es');
        $issuer = trim((string) ($settings['business']['name'] ?? ''));

        return $issuer !== '' ? $issuer : (string) config('app.name', 'AppMenu');
    }
}
