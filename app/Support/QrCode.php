<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Codificador QR mínimo, sin dependencias externas.
 *
 * Soporta modo byte, nivel de corrección M y versiones 1 a 10 (hasta 216
 * codewords de datos), suficiente para una URL de menú. Se implementa sobre
 * la especificación ISO/IEC 18004 para no depender de Composer ni de un
 * servicio externo.
 */
final class QrCode
{
    private const EC_LEVEL_BITS = 0b00; // M

    /** @var array<int, array{data: int, ec: int, g1: int, g1data: int, g2: int, g2data: int}> */
    private const BLOCKS_M = [
        1 => ['data' => 16, 'ec' => 10, 'g1' => 1, 'g1data' => 16, 'g2' => 0, 'g2data' => 0],
        2 => ['data' => 28, 'ec' => 16, 'g1' => 1, 'g1data' => 28, 'g2' => 0, 'g2data' => 0],
        3 => ['data' => 44, 'ec' => 26, 'g1' => 1, 'g1data' => 44, 'g2' => 0, 'g2data' => 0],
        4 => ['data' => 64, 'ec' => 18, 'g1' => 2, 'g1data' => 32, 'g2' => 0, 'g2data' => 0],
        5 => ['data' => 86, 'ec' => 24, 'g1' => 2, 'g1data' => 43, 'g2' => 0, 'g2data' => 0],
        6 => ['data' => 108, 'ec' => 16, 'g1' => 4, 'g1data' => 27, 'g2' => 0, 'g2data' => 0],
        7 => ['data' => 124, 'ec' => 18, 'g1' => 4, 'g1data' => 31, 'g2' => 0, 'g2data' => 0],
        8 => ['data' => 154, 'ec' => 22, 'g1' => 2, 'g1data' => 38, 'g2' => 2, 'g2data' => 39],
        9 => ['data' => 182, 'ec' => 22, 'g1' => 3, 'g1data' => 36, 'g2' => 2, 'g2data' => 37],
        10 => ['data' => 216, 'ec' => 26, 'g1' => 4, 'g1data' => 43, 'g2' => 1, 'g2data' => 44],
    ];

    /** @var array<int, array<int, int>> */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var array<int, int>|null */
    private static ?array $exp = null;

    /** @var array<int, int>|null */
    private static ?array $log = null;

    /** @return array<int, array<int, bool>> */
    public static function matrix(string $text, ?int $forceMask = null): array
    {
        $version = self::chooseVersion($text);
        $codewords = self::encodeData($text, $version);
        $size = 17 + 4 * $version;

        [$base, $isData] = self::baseMatrix($version, $size);
        self::placeData($base, $isData, $codewords, $size);

        $bestMatrix = null;
        $bestPenalty = PHP_INT_MAX;
        $masks = $forceMask === null ? range(0, 7) : [$forceMask];

        foreach ($masks as $mask) {
            $candidate = $base;
            foreach ($isData as $row => $columns) {
                foreach ($columns as $col => $isDataCell) {
                    if ($isDataCell && self::maskCondition($mask, $row, $col)) {
                        $candidate[$row][$col] = !$candidate[$row][$col];
                    }
                }
            }

            self::drawFormatInfo($candidate, $size, $mask);
            if ($version >= 7) {
                self::drawVersionInfo($candidate, $size, $version);
            }

            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMatrix = $candidate;
            }
        }

        return $bestMatrix ?? $base;
    }

    public static function svg(string $text, int $moduleSize = 8, int $quietZone = 4, string $dark = '#000000', string $light = '#ffffff'): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $moduleSize = max(1, $moduleSize);
        $quietZone = max(0, $quietZone);
        $dimension = ($size + 2 * $quietZone) * $moduleSize;
        $offset = $quietZone * $moduleSize;

        $path = [];
        foreach ($matrix as $row => $columns) {
            foreach ($columns as $col => $isDark) {
                if (!$isDark) {
                    continue;
                }
                $x = $offset + $col * $moduleSize;
                $y = $offset + $row * $moduleSize;
                $path[] = 'M' . $x . ' ' . $y . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dimension . '" height="' . $dimension . '" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" role="img" aria-label="Código QR">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/>'
            . '<path d="' . implode('', $path) . '" fill="' . $dark . '" shape-rendering="crispEdges"/>'
            . '</svg>';
    }

    public static function png(string $text, int $scale = 10, int $quietZone = 4): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $scale = max(1, $scale);
        $quietZone = max(0, $quietZone);
        $dimension = ($size + 2 * $quietZone) * $scale;
        $offset = $quietZone * $scale;

        $image = imagecreatetruecolor($dimension, $dimension);
        if ($image === false) {
            throw new RuntimeException('No se pudo crear la imagen del QR.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $dimension, $dimension, $white);

        foreach ($matrix as $row => $columns) {
            foreach ($columns as $col => $isDark) {
                if (!$isDark) {
                    continue;
                }
                $x = $offset + $col * $scale;
                $y = $offset + $row * $scale;
                imagefilledrectangle($image, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('No se pudo serializar el QR.');
        }

        return $binary;
    }

    private static function chooseVersion(string $text): int
    {
        $length = strlen($text);

        foreach (self::BLOCKS_M as $version => $block) {
            $countBits = $version <= 9 ? 8 : 16;
            $requiredBits = 4 + $countBits + $length * 8;
            if ($requiredBits <= $block['data'] * 8) {
                return $version;
            }
        }

        throw new RuntimeException('El texto es demasiado largo para el QR (máximo 10 versiones).');
    }

    /** @return array<int, int> */
    private static function encodeData(string $text, int $version): array
    {
        $block = self::BLOCKS_M[$version];
        $capacityBits = $block['data'] * 8;
        $countBits = $version <= 9 ? 8 : 16;
        $length = strlen($text);

        $bits = '0100' . str_pad(decbin($length), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $data = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $data[] = bindec(substr($bits, $i, 8));
        }

        $pad = [0xEC, 0x11];
        $index = 0;
        while (count($data) < $block['data']) {
            $data[] = $pad[$index % 2];
            $index++;
        }

        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $block['g1']; $i++) {
            $blocks[] = array_slice($data, $offset, $block['g1data']);
            $offset += $block['g1data'];
        }
        for ($i = 0; $i < $block['g2']; $i++) {
            $blocks[] = array_slice($data, $offset, $block['g2data']);
            $offset += $block['g2data'];
        }

        $ecBlocks = [];
        foreach ($blocks as $dataBlock) {
            $ecBlocks[] = self::rsEncode($dataBlock, $block['ec']);
        }

        $interleaved = [];
        $maxData = max($block['g1data'], $block['g2data']);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $dataBlock) {
                if (array_key_exists($i, $dataBlock)) {
                    $interleaved[] = $dataBlock[$i];
                }
            }
        }

        for ($i = 0; $i < $block['ec']; $i++) {
            foreach ($ecBlocks as $ecBlock) {
                if (array_key_exists($i, $ecBlock)) {
                    $interleaved[] = $ecBlock[$i];
                }
            }
        }

        return $interleaved;
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function rsEncode(array $data, int $ecCount): array
    {
        $generator = self::generatorPolynomial($ecCount);
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            for ($i = 0; $i < $ecCount; $i++) {
                $remainder[$i] ^= self::gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    /** @return array<int, int> */
    private static function generatorPolynomial(int $ecCount): array
    {
        $generator = [1];

        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= self::gfMultiply($coefficient, self::gfExp($i));
            }
            $generator = $next;
        }

        return $generator;
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        self::initGalois();
        /** @var array<int, int> $exp */
        $exp = self::$exp;
        /** @var array<int, int> $log */
        $log = self::$log;

        return $exp[$log[$a] + $log[$b]];
    }

    private static function gfExp(int $power): int
    {
        self::initGalois();
        /** @var array<int, int> $exp */
        $exp = self::$exp;

        return $exp[$power % 255];
    }

    private static function initGalois(): void
    {
        if (self::$exp !== null && self::$log !== null) {
            return;
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$exp = $exp;
        self::$log = $log;
    }

    /**
     * @return array{0: array<int, array<int, bool|null>>, 1: array<int, array<int, bool>>}
     */
    private static function baseMatrix(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $isData = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFinder($matrix, 0, 0, $size);
        self::drawFinder($matrix, 0, $size - 7, $size);
        self::drawFinder($matrix, $size - 7, 0, $size);

        for ($i = 8; $i < $size - 8; $i++) {
            $matrix[6][$i] = $i % 2 === 0;
            $matrix[$i][6] = $i % 2 === 0;
        }

        $positions = self::ALIGNMENT[$version] ?? [];
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                if (($row === 6 && $col === 6) || ($row === 6 && $col === $size - 7) || ($row === $size - 7 && $col === 6)) {
                    continue;
                }
                self::drawAlignment($matrix, $row, $col, $size);
            }
        }

        $matrix[$size - 8][8] = true;
        self::reserveFormat($matrix, $size);

        if ($version >= 7) {
            for ($row = 0; $row < 6; $row++) {
                for ($col = $size - 11; $col <= $size - 9; $col++) {
                    $matrix[$row][$col] = false;
                }
            }
            for ($row = $size - 11; $row <= $size - 9; $row++) {
                for ($col = 0; $col < 6; $col++) {
                    $matrix[$row][$col] = false;
                }
            }
        }

        return [$matrix, $isData];
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function drawFinder(array &$matrix, int $row, int $col, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }

                if ($r === -1 || $r === 7 || $c === -1 || $c === 7) {
                    if ($matrix[$rr][$cc] === null) {
                        $matrix[$rr][$cc] = false;
                    }
                    continue;
                }

                $matrix[$rr][$cc] = ($r === 0 || $r === 6 || $c === 0 || $c === 6)
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            }
        }
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function drawAlignment(array &$matrix, int $row, int $col, int $size): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }

                $matrix[$rr][$cc] = max(abs($r), abs($c)) !== 1;
            }
        }
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function reserveFormat(array &$matrix, int $size): void
    {
        for ($i = 0; $i < 15; $i++) {
            if ($i < 6) {
                $matrix[$i][8] = false;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = false;
            } else {
                $matrix[$size - 15 + $i][8] = false;
            }

            if ($i < 8) {
                $matrix[8][$size - $i - 1] = false;
            } elseif ($i === 8) {
                $matrix[8][7] = false;
            } else {
                $matrix[8][15 - $i - 1] = false;
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     * @param array<int, array<int, bool>> $isData
     * @param array<int, int> $codewords
     */
    private static function placeData(array &$matrix, array &$isData, array $codewords, int $size): void
    {
        $totalBits = count($codewords) * 8;
        $bitIndex = 0;
        $row = $size - 1;
        $increment = -1;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if ($matrix[$row][$cc] === null) {
                        $bit = 0;
                        if ($bitIndex < $totalBits) {
                            $byte = $codewords[intdiv($bitIndex, 8)];
                            $bit = ($byte >> (7 - ($bitIndex % 8))) & 1;
                        }
                        $matrix[$row][$cc] = $bit === 1;
                        $isData[$row][$cc] = true;
                        $bitIndex++;
                    }
                }

                $row += $increment;
                if ($row < 0 || $row >= $size) {
                    $row -= $increment;
                    $increment = -$increment;
                    break;
                }
            }
        }
    }

    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => false,
        };
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function drawFormatInfo(array &$matrix, int $size, int $mask): void
    {
        $format = (self::EC_LEVEL_BITS << 3) | $mask;
        $remainder = $format << 10;
        $generator = 0x537;

        for ($i = 14; $i >= 10; $i--) {
            if ((($remainder >> $i) & 1) !== 0) {
                $remainder ^= $generator << ($i - 10);
            }
        }

        $bits = (($format << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $bit = (($bits >> $i) & 1) === 1;

            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }

            if ($i < 8) {
                $matrix[8][$size - $i - 1] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][15 - $i - 1] = $bit;
            }
        }

        $matrix[$size - 8][8] = true;
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function drawVersionInfo(array &$matrix, int $size, int $version): void
    {
        $remainder = $version << 12;
        $generator = 0x1F25;

        for ($i = 17; $i >= 12; $i--) {
            if ((($remainder >> $i) & 1) !== 0) {
                $remainder ^= $generator << ($i - 12);
            }
        }

        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $matrix[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
            $matrix[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
        }
    }

    /** @param array<int, array<int, bool|null>> $matrix */
    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        for ($row = 0; $row < $size; $row++) {
            $runColor = null;
            $runLength = 0;
            for ($col = 0; $col < $size; $col++) {
                $color = (bool) $matrix[$row][$col];
                if ($color === $runColor) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColor = $color;
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        for ($col = 0; $col < $size; $col++) {
            $runColor = null;
            $runLength = 0;
            for ($row = 0; $row < $size; $row++) {
                $color = (bool) $matrix[$row][$col];
                if ($color === $runColor) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColor = $color;
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $value = (bool) $matrix[$row][$col];
                if ($value === (bool) $matrix[$row + 1][$col]
                    && $value === (bool) $matrix[$row][$col + 1]
                    && $value === (bool) $matrix[$row + 1][$col + 1]) {
                    $penalty += 3;
                }
            }
        }

        $patternA = '10111010000';
        $patternB = '00001011101';
        for ($row = 0; $row < $size; $row++) {
            $line = '';
            for ($col = 0; $col < $size; $col++) {
                $line .= $matrix[$row][$col] ? '1' : '0';
            }
            $penalty += 40 * (substr_count($line, $patternA) + substr_count($line, $patternB));
        }
        for ($col = 0; $col < $size; $col++) {
            $line = '';
            for ($row = 0; $row < $size; $row++) {
                $line .= $matrix[$row][$col] ? '1' : '0';
            }
            $penalty += 40 * (substr_count($line, $patternA) + substr_count($line, $patternB));
        }

        $dark = 0;
        foreach ($matrix as $row) {
            foreach ($row as $value) {
                if ($value) {
                    $dark++;
                }
            }
        }
        $percent = $dark * 100 / ($size * $size);
        $penalty += 10 * (int) floor(abs($percent - 50) / 5);

        return $penalty;
    }
}
