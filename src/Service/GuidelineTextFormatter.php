<?php
declare(strict_types=1);

namespace App\Service;

final class GuidelineTextFormatter
{
    /**
     * Reflow PDF lines without changing the source wording.
     *
     * @return list<array{type: string, text: string, number?: int}>
     */
    public static function blocks(string $text, string $title = ''): array
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/(?m)^\s*(\d+[.)]|[•□])\s*\n\s*/u', '$1 ', $text);
        $blocks = [];
        $newParagraph = true;
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line));
            if ($line === '') {
                $newParagraph = true;
                continue;
            }
            if (!$blocks && strcasecmp(rtrim($line, ':'), rtrim($title, ':')) === 0) {
                continue;
            }
            if (preg_match('/^(\d+)[.)]\s+(.+)$/u', $line, $match)) {
                $blocks[] = ['type' => 'ordered', 'text' => $match[2], 'number' => (int)$match[1]];
            } elseif (preg_match('/^([•□])\s*(.+)$/u', $line, $match)) {
                $blocks[] = ['type' => $match[1] === '□' ? 'checklist' : 'bullet', 'text' => $match[2]];
            } elseif (mb_strlen($line) < 100 && preg_match('/[A-Z]{2}/', $line)
                && mb_strtoupper($line) === $line) {
                $blocks[] = ['type' => 'heading', 'text' => $line];
            } else {
                $last = array_key_last($blocks);
                $continues = $last !== null && $blocks[$last]['type'] !== 'heading'
                    && (!$newParagraph || preg_match('/^[a-z]/', $line)
                        || !preg_match('/[.!?:;)]$/u', $blocks[$last]['text']));
                if ($continues) {
                    $blocks[$last]['text'] .= ' ' . $line;
                } else {
                    $blocks[] = ['type' => 'paragraph', 'text' => $line];
                }
            }
            $newParagraph = false;
        }

        return $blocks;
    }
}
