<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Support\QrCode;

final class QrController
{
    public function image(Request $request, array $params = []): Response
    {
        $settings = Setting::public('es');
        $url = (string) ($settings['public_menu_url'] ?? app_public_url('/'));
        $format = strtolower((string) $request->queryParam('format', 'png'));
        $download = (string) $request->queryParam('download', '') === '1';
        $name = 'menu-qr';

        if ($format === 'svg') {
            $svg = QrCode::svg($url, 10, 4);
            $headers = ['Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $name . '.svg"'];

            return Response::raw($svg, 'image/svg+xml; charset=utf-8', 200, $headers);
        }

        if ($format !== 'png') {
            throw new ApiException('INVALID_FORMAT', 'Formato no soportado. Usa png o svg.', 422);
        }

        $png = QrCode::png($url, 12, 4);

        return Response::raw($png, 'image/png', 200, [
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $name . '.png"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function printable(Request $request, array $params = []): Response
    {
        $settings = Setting::public('es');
        $business = $settings['business'] ?? [];
        $url = (string) ($settings['public_menu_url'] ?? app_public_url('/'));
        $menuUrl = (string) ($settings['public_menu_url'] ?? app_public_url('/'));
        $name = (string) ($business['name'] ?? 'Menú');
        $tagline = (string) ($business['tagline'] ?? '');
        $address = (string) ($business['address'] ?? '');
        $phone = (string) ($business['phone'] ?? '');
        $svg = QrCode::svg($url, 12, 4, '#141210', '#ffffff');
        $svgData = 'data:image/svg+xml;base64,' . base64_encode($svg);

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>QR del menú · ' . e($name) . '</title>'
            . '<style>'
            . ':root{color-scheme:light}*{box-sizing:border-box}'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f1ec;color:#141210;font-family:"Segoe UI",system-ui,sans-serif}'
            . '.card{width:min(520px,92vw);background:#fff;border:1px solid #e2dbd1;border-radius:24px;padding:40px;text-align:center;box-shadow:0 24px 60px rgba(20,18,16,.12)}'
            . '.brand{font-family:Georgia,"Times New Roman",serif;font-size:30px;margin:0 0 6px;letter-spacing:.5px}'
            . '.tagline{margin:0 0 28px;color:#6f655a;font-size:15px}'
            . '.qr{width:280px;height:280px;max-width:100%;margin:0 auto;display:block;border-radius:16px}'
            . '.hint{margin:26px 0 4px;font-size:17px;font-weight:600;letter-spacing:.2px}'
            . '.url{margin:0;color:#6f655a;font-size:13px;word-break:break-all;text-decoration:underline;text-underline-offset:3px}'
            . '.meta{margin-top:26px;padding-top:20px;border-top:1px solid #ece6de;color:#6f655a;font-size:13px;line-height:1.7}'
            . '.actions{margin-top:26px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}'
            . '.btn{font:inherit;padding:10px 18px;border-radius:999px;border:1px solid #141210;background:#141210;color:#fff;text-decoration:none}'
            . '.btn.ghost{background:#fff;color:#141210}'
            . '.tip{margin:14px 0 0;color:#8a8075;font-size:12px}'
            . '@media print{body{background:#fff}.card{border:0;box-shadow:none;padding:0}.actions,.tip{display:none}}'
            . '</style></head><body><main class="card">'
            . '<h1 class="brand">' . e($name) . '</h1>'
            . ($tagline !== '' ? '<p class="tagline">' . e($tagline) . '</p>' : '')
            . '<img class="qr" src="' . $svgData . '" alt="Código QR del menú">'
            . '<p class="hint">Escanea para ver nuestro menú</p>'
            . '<a class="url" href="' . e($menuUrl) . '" target="_blank" rel="noopener noreferrer">' . e($menuUrl) . '</a>'
            . (($address !== '' || $phone !== '') ? '<div class="meta">' . ($address !== '' ? e($address) . '<br>' : '') . ($phone !== '' ? e($phone) : '') . '</div>' : '')
            . '<div class="actions"><a class="btn" href="' . e(app_url('/api/v1/admin/qr?format=png&download=1')) . '">Descargar PNG</a>'
            . '<a class="btn ghost" href="' . e(app_url('/admin/')) . '">Volver al panel</a></div>'
            . '<p class="tip">También puedes imprimir con Ctrl+P o Cmd+P.</p>'
            . '</main></body></html>';

        return Response::raw($html, 'text/html; charset=utf-8', 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
