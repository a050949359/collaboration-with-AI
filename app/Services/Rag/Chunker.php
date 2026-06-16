<?php

namespace App\Services\Rag;

/**
 * 遞迴邊界切塊器:盡量順著自然結構切，讓初稿乾淨、減少後續人/LLM 編輯。
 *
 * 策略(以「字元」計，中文友善):
 *  1. 依優先序的分隔符把文字切成片段:段落(空行) > 行(換行) > 句(。！？等) > 硬切
 *  2. 某片段仍超過 size → 退到下一層更細的分隔符再切
 *  3. 把相鄰的小片段「貪婪打包」到接近 size 的塊，太小(< min)的盡量併進去
 *
 * 不做 overlap(邊界順結構切，必要性低；config.overlap 保留供日後使用)。
 * 對外介面 chunk(): string[] 不變，之後若要換語意切塊可直接替換實作。
 */
class Chunker
{
    private int $size;

    private int $min;

    public function __construct(?int $size = null, ?int $min = null)
    {
        $this->size = $size ?? (int) config('rag.chunk.size', 450);
        $this->min = $min ?? (int) config('rag.chunk.min', 120);

        if ($this->size < 1) {
            $this->size = 1;
        }
        if ($this->min < 0 || $this->min >= $this->size) {
            $this->min = (int) max(0, min($this->min, $this->size - 1));
        }
    }

    /** 由粗到細的邊界分隔符(regex,保留分隔內容)。 */
    private const SEPARATORS = [
        '/(?<=\n)\s*\n/u',          // 段落:空行
        '/(?<=\n)/u',               // 行:單換行
        '/(?<=[。！？!?；;])/u',     // 句:中英句末標點
    ];

    /**
     * 將整份文字切成多個片段。空字串回空陣列。
     *
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        // 去 UTF-8 BOM(Drive export text/plain 會帶)、統一換行、去頭尾空白
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if (mb_strlen($text) === 0) {
            return [];
        }

        $pieces = $this->splitRecursive($text, 0);

        return $this->pack($pieces);
    }

    /**
     * 遞迴切:超過 size 的片段往下一層更細的分隔符切;到最細仍過大就硬切。
     *
     * @return array<int, string>
     */
    private function splitRecursive(string $text, int $level): array
    {
        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        if ($level >= count(self::SEPARATORS)) {
            return $this->hardCut($text);
        }

        $parts = preg_split(self::SEPARATORS[$level], $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        // 這層切不動(整段沒有該分隔符)→ 直接往下一層
        if (count($parts) <= 1) {
            return $this->splitRecursive($text, $level + 1);
        }

        $out = [];
        foreach ($parts as $part) {
            foreach ($this->splitRecursive($part, $level + 1) as $sub) {
                $out[] = $sub;
            }
        }

        return $out;
    }

    /**
     * 最細層仍超過 size 的片段:固定字數硬切。
     *
     * @return array<int, string>
     */
    private function hardCut(string $text): array
    {
        $out = [];
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i += $this->size) {
            $out[] = mb_substr($text, $i, $this->size);
        }

        return $out;
    }

    /**
     * 貪婪打包:把相鄰片段累積到接近 size 的塊;避免產生 < min 的碎塊。
     *
     * @param  array<int, string>  $pieces
     * @return array<int, string>
     */
    private function pack(array $pieces): array
    {
        $chunks = [];
        $buffer = '';

        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            $candidate = $buffer === '' ? $piece : $buffer."\n".$piece;

            if (mb_strlen($candidate) <= $this->size) {
                $buffer = $candidate;

                continue;
            }

            // 放不下:先收掉 buffer,piece 另起
            if ($buffer !== '') {
                $chunks[] = $buffer;
            }
            $buffer = $piece;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $this->mergeTinyTail($chunks);
    }

    /**
     * 若最後一塊太小(< min),併回前一塊(且合併後不超過 size)。
     *
     * @param  array<int, string>  $chunks
     * @return array<int, string>
     */
    private function mergeTinyTail(array $chunks): array
    {
        $count = count($chunks);
        if ($count >= 2 && mb_strlen($chunks[$count - 1]) < $this->min) {
            $merged = $chunks[$count - 2]."\n".$chunks[$count - 1];
            if (mb_strlen($merged) <= $this->size) {
                $chunks[$count - 2] = $merged;
                array_pop($chunks);
            }
        }

        return $chunks;
    }
}
