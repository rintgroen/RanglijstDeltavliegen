<?php

/*
 * QR matrix generation adapted from Project Nayuki's QR Code generator.
 *
 * Copyright (c) Project Nayuki. (MIT License)
 * https://www.nayuki.io/page/qr-code-generator-library
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * The Software is provided "as is", without warranty of any kind.
 */

class AppQrCode {
    private const ECC_LOW = 0;
    private const ECC_MEDIUM = 1;

    private const ECC_FORMAT_BITS = [
        0 => 1,
        1 => 0,
    ];

    private const ECC_CODEWORDS_PER_BLOCK = [
        0 => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        1 => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        0 => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        1 => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
    ];

    private $version;
    private $errorCorrectionLevel;
    private $size;
    private $modules = [];
    private $isFunction = [];

    private function __construct(int $version, int $errorCorrectionLevel, array $dataCodewords) {
        $this->version = $version;
        $this->errorCorrectionLevel = $errorCorrectionLevel;
        $this->size = $version * 4 + 17;

        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }

        $this->drawFunctionPatterns();
        $allCodewords = $this->addEccAndInterleave($dataCodewords);
        $this->drawCodewords($allCodewords);
        $mask = 0;
        $this->applyMask($mask);
        $this->drawFormatBits($mask);
    }

    public static function svg(string $text, int $border = 4): string {
        $qr = self::encodeBytes($text, self::ECC_MEDIUM);
        return $qr->toSvg($border);
    }

    private static function encodeBytes(string $text, int $errorCorrectionLevel): self {
        $bytes = $text === '' ? [] : array_values(unpack('C*', $text));
        $usedBitsByVersion = [];
        $version = 0;
        for ($candidate = 1; $candidate <= 40; $candidate++) {
            $charCountBits = $candidate <= 9 ? 8 : 16;
            if (count($bytes) >= (1 << $charCountBits)) {
                continue;
            }
            $usedBits = 4 + $charCountBits + (count($bytes) * 8);
            $usedBitsByVersion[$candidate] = $usedBits;
            if ($usedBits <= self::getNumDataCodewords($candidate, $errorCorrectionLevel) * 8) {
                $version = $candidate;
                break;
            }
        }

        if ($version === 0) {
            $errorCorrectionLevel = self::ECC_LOW;
            for ($candidate = 1; $candidate <= 40; $candidate++) {
                $charCountBits = $candidate <= 9 ? 8 : 16;
                if (count($bytes) >= (1 << $charCountBits)) {
                    continue;
                }
                $usedBits = $usedBitsByVersion[$candidate] ?? (4 + $charCountBits + (count($bytes) * 8));
                if ($usedBits <= self::getNumDataCodewords($candidate, $errorCorrectionLevel) * 8) {
                    $version = $candidate;
                    break;
                }
            }
        }

        if ($version === 0) {
            throw new RuntimeException('QR payload is te groot.');
        }

        $bits = [];
        self::appendBits(0x4, 4, $bits);
        self::appendBits(count($bytes), $version <= 9 ? 8 : 16, $bits);
        foreach ($bytes as $byte) {
            self::appendBits($byte, 8, $bits);
        }

        $capacityBits = self::getNumDataCodewords($version, $errorCorrectionLevel) * 8;
        self::appendBits(0, min(4, $capacityBits - count($bits)), $bits);
        self::appendBits(0, (8 - count($bits) % 8) % 8, $bits);
        for ($padByte = 0xEC; count($bits) < $capacityBits; $padByte ^= 0xEC ^ 0x11) {
            self::appendBits($padByte, 8, $bits);
        }

        $dataCodewords = array_fill(0, (int)(count($bits) / 8), 0);
        foreach ($bits as $i => $bit) {
            $dataCodewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        return new self($version, $errorCorrectionLevel, $dataCodewords);
    }

    private static function appendBits(int $value, int $length, array &$bits): void {
        if ($length < 0 || $length > 31 || ($length < 31 && ($value >> $length) !== 0)) {
            throw new RuntimeException('Ongeldige QR-bitwaarde.');
        }
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function getNumDataCodewords(int $version, int $errorCorrectionLevel): int {
        return (int)floor(self::getNumRawDataModules($version) / 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$errorCorrectionLevel][$version]
            * self::NUM_ERROR_CORRECTION_BLOCKS[$errorCorrectionLevel][$version];
    }

    private static function getNumRawDataModules(int $version): int {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private function drawFunctionPatterns(): void {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        $positions = $this->getAlignmentPatternPositions();
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignmentPattern($positions[$i], $positions[$j]);
            }
        }

        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask): void {
        $data = (self::ECC_FORMAT_BITS[$this->errorCorrectionLevel] << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (((($remainder >> 9) & 1) !== 0) ? 0x537 : 0);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::getBit($bits, 6));
        $this->setFunctionModule(8, 8, self::getBit($bits, 7));
        $this->setFunctionModule(7, 8, self::getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::getBit($bits, $i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::getBit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::getBit($bits, $i));
        }
        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersion(): void {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (((($remainder >> 11) & 1) !== 0) ? 0x1F25 : 0);
        }
        $bits = ($this->version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $color = self::getBit($bits, $i);
            $a = $this->size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $color);
            $this->setFunctionModule($b, $a, $color);
        }
    }

    private function drawFinderPattern(int $x, int $y): void {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $xx >= $this->size || $yy < 0 || $yy >= $this->size) {
                    continue;
                }
                $distance = max(abs($dx), abs($dy));
                $this->setFunctionModule($xx, $yy, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignmentPattern(int $x, int $y): void {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function setFunctionModule(int $x, int $y, bool $isDark): void {
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    private function getAlignmentPatternPositions(): array {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function addEccAndInterleave(array $data): array {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->errorCorrectionLevel][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->errorCorrectionLevel][$this->version];
        $rawCodewords = (int)floor(self::getNumRawDataModules($this->version) / 8);
        $numShortBlocks = $numBlocks - ($rawCodewords % $numBlocks);
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $blocks = [];
        $rsDivisor = self::reedSolomonComputeDivisor($blockEccLen);
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $dataLength = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $blockData = array_slice($data, $k, $dataLength);
            $k += $dataLength;
            $ecc = self::reedSolomonComputeRemainder($blockData, $rsDivisor);
            if ($i < $numShortBlocks) {
                $blockData[] = 0;
            }
            $blocks[] = array_merge($blockData, $ecc);
        }

        $result = [];
        $blockLength = count($blocks[0]);
        for ($i = 0; $i < $blockLength; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private static function reedSolomonComputeDivisor(int $degree): array {
        $result = array_fill(0, $degree - 1, 0);
        $result[] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < count($result); $j++) {
                $result[$j] = self::reedSolomonMultiply($result[$j], $root);
                if ($j + 1 < count($result)) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::reedSolomonMultiply($root, 0x02);
        }
        return $result;
    }

    private static function reedSolomonComputeRemainder(array $data, array $divisor): array {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::reedSolomonMultiply($coefficient, $factor);
            }
        }
        return $result;
    }

    private static function reedSolomonMultiply(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (((($z >> 7) & 1) !== 0) ? 0x11D : 0);
            if ((($y >> $i) & 1) !== 0) {
                $z ^= $x;
            }
        }
        return $z & 0xFF;
    }

    private function drawCodewords(array $data): void {
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;
                    if (!$this->isFunction[$y][$x] && $i < count($data) * 8) {
                        $this->modules[$y][$x] = self::getBit($data[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = false;
                if ($mask === 0) {
                    $invert = ($x + $y) % 2 === 0;
                }
                if (!$this->isFunction[$y][$x] && $invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private static function getBit(int $value, int $index): bool {
        return (($value >> $index) & 1) !== 0;
    }

    private function toSvg(int $border): string {
        $border = max(0, $border);
        $viewSize = $this->size + ($border * 2);
        $parts = [];
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $parts[] = 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewSize . ' ' . $viewSize . '" role="img" aria-label="XCTSK taak QR-code">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . implode('', $parts) . '" fill="#102436"/>'
            . '</svg>';
    }
}
